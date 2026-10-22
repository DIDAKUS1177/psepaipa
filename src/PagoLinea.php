<?php
/**
 * Pagos en línea del predial: los intentos y el registro del pago en la base
 * oficial. Dos pasarelas (2026-10-21): AvalPay (PSE, Banco de Bogotá) y
 * 1cero1pay (Bancolombia, src/UnoCeroUno.php). Cada intento lleva el
 * proveedor de su pasarela; un predio no tiene dos pagos en curso aunque sean
 * de pasarelas distintas.
 *
 * Intentos: tabla generacion_pagos, la misma que usaba el PSE anterior (una
 * fila por intento). No se crean tablas nuevas. Columnas que se usan:
 *   referencia = id del predio, factura = número de la factura que se paga,
 *   codigo_entidad = EAN, propietario_identificacion = documento de quien
 *   paga, total = valor, Impuesto = "Predial AAAA-AAAA" (el rango pagado),
 *   IDTransaccion = requestId de AvalPay, URL = enlace de pago, CUS =
 *   autorización del banco, MedioPago = banco, Mensaje = último mensaje,
 *   EstadoTransaccion = COMENZADA / PENDING / APPROVED / REJECTED / ... /
 *   DOBLE / REVISAR, Activo = 1 mientras falta saber en qué terminó,
 *   proveedor = config avalpay.proveedor (5 por defecto: distinto del PSE
 *   viejo, para no mezclar sus intentos con los de este portal).
 *
 * Registro: igual que el recaudo por archivo del sistema oficial
 * (UploadController): INSERT en pagos, predios_pagos.pagado = -1 con
 * fecha_pago, valor_pago e id_banco, y predios.ultimo_anio_pago. Diferencias:
 * todo en una transacción con candado por factura; solo se registra si las
 * vigencias de la factura siguen siendo las del intento y lo que entró
 * alcanza; valor_pago = total_calculo (lo que se cobró hoy; el oficial elige
 * entre total_calculo/total_dos/total_tres por la fecha del archivo). Lo que
 * no cuadra queda en REVISAR o DOBLE para conciliarlo a mano.
 */

declare(strict_types=1);

final class PagoLinea
{
    const MINUTOS_COMENZADA = 35;   // una sesión sin intento vence a los 30
    const DIAS_PENDIENTE    = 3;    // PSE puede tardar en confirmar
    const FINALES_SIN_PAGO  = ['REJECTED', 'EXPIRED', 'FAILED', 'DECLINED', 'ERROR'];

    /** Proveedor de AvalPay en generacion_pagos. */
    public static function proveedor(): int
    {
        return (int) config('avalpay.proveedor', 5);
    }

    /** Los proveedores de este portal (AvalPay y 1cero1pay). */
    public static function proveedores(): array
    {
        return array_values(array_unique([self::proveedor(), UnoCeroUno::proveedor()]));
    }

    public static function esUnoCeroUno(array $i): bool
    {
        return (int) $i['proveedor'] === UnoCeroUno::proveedor() && UnoCeroUno::proveedor() !== self::proveedor();
    }

    /** "IN (?, ?)" de los proveedores, para las consultas. */
    private static function enProveedores(): string
    {
        return 'proveedor IN (' . implode(', ', array_fill(0, count(self::proveedores()), '?')) . ')';
    }

    /** ¿La pasarela del intento está configurada? */
    public static function pasarelaLista(array $i): bool
    {
        return self::esUnoCeroUno($i) ? UnoCeroUno::configurado() : AvalPay::configurado();
    }

    /**
     * Intento en curso del predio, o null. Uno con sesión en AvalPay bloquea
     * hasta que una consulta diga en qué terminó (retorno, aviso, la tarea
     * programada o refrescar()): un pago empezado al minuto 29 puede quedar
     * pendiente en el banco. Uno sin sesión, solo unos minutos. Uno APROBADO
     * por el banco que no se pudo registrar bloquea SIN límite: la plata ya
     * entró y el predio no debe volver a cobrarse.
     */
    public static function enTramite(int $idPredio): ?array
    {
        return Db::fila(
            "SELECT TOP 1 * FROM generacion_pagos
              WHERE " . self::enProveedores() . " AND referencia = ? AND Activo = 1
                AND (EstadoTransaccion = 'APPROVED'
                     OR (created_at >= ? AND (ISNULL(IDTransaccion, '') <> '' OR created_at >= ?)))
              ORDER BY IdGeneraPado DESC",
            [...self::proveedores(), (string) $idPredio,
             date('Y-m-d\TH:i:s', time() - self::DIAS_PENDIENTE * 86400),
             date('Y-m-d\TH:i:s', time() - self::MINUTOS_COMENZADA * 60)]
        );
    }

    /**
     * Pago aprobado por el banco que quedó para conciliar a mano (REVISAR) en
     * los últimos 60 días, o null. Mientras exista, el predio avisa y no abre
     * otro pago en línea: el banco ya cobró.
     */
    public static function porConciliar(int $idPredio): ?array
    {
        return Db::fila(
            "SELECT TOP 1 * FROM generacion_pagos
              WHERE " . self::enProveedores() . " AND referencia = ? AND EstadoTransaccion = 'REVISAR' AND created_at >= ?
              ORDER BY IdGeneraPado DESC",
            [...self::proveedores(), (string) $idPredio, date('Y-m-d\TH:i:s', time() - 60 * 86400)]
        );
    }

    /**
     * Consulta en AvalPay los intentos abiertos del predio que ya deberían
     * haber terminado (sesión vencida), para no bloquear sin necesidad. Si
     * AvalPay no responde, siguen bloqueando: es lo seguro.
     */
    public static function refrescar(int $idPredio): void
    {
        $abiertos = Db::filas(
            "SELECT * FROM generacion_pagos
              WHERE " . self::enProveedores() . " AND referencia = ? AND Activo = 1 AND ISNULL(IDTransaccion, '') <> '' AND created_at < ?",
            [...self::proveedores(), (string) $idPredio, date('Y-m-d\TH:i:s', time() - self::MINUTOS_COMENZADA * 60)]
        );
        foreach ($abiertos as $i) {
            if (!self::pasarelaLista($i)) {
                continue;
            }
            try {
                self::actualizar($i);
            } catch (Throwable $t) {
                error_log('[psepaipa pago] refrescar intento ' . $i['IdGeneraPado'] . ': ' . $t->getMessage());
            }
        }
    }

    public static function intento(int $id): ?array
    {
        return Db::fila('SELECT * FROM generacion_pagos WHERE IdGeneraPado = ? AND ' . self::enProveedores(), [$id, ...self::proveedores()]);
    }

    public static function porSesion(string $requestId): ?array
    {
        return Db::fila(
            'SELECT TOP 1 * FROM generacion_pagos WHERE IDTransaccion = ? AND proveedor = ? ORDER BY IdGeneraPado DESC',
            [$requestId, self::proveedor()]
        );
    }

    /** Rango [desde, hasta] guardado en el intento ("Predial 2020-2026"). */
    public static function rango(array $i): array
    {
        if (preg_match('/(\d{4})\s*-\s*(\d{4})/', (string) $i['Impuesto'], $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/(\d{4})/', (string) $i['Impuesto'], $m)) {
            return [(int) $m[1], (int) $m[1]];
        }
        return [0, 0];
    }

    /**
     * Anota el intento si el predio no tiene otro en curso. Usa el MISMO
     * candado de Factura::generar: entre generar la factura y anotar el
     * intento, otra petición pudo renumerar las vigencias (y el pago llegaría
     * con un número que ya no tienen); por eso se vuelve a comprobar que la
     * factura sigue cubriendo el rango por el mismo valor. Devuelve el id o
     * null si ya hay un pago en curso. Lanza AvisoUsuario si la factura cambió.
     */
    public static function crearIntento(int $idPredio, string $factura, int $valor, string $documento, int $desde, int $hasta, bool $unoCeroUno = false): ?int
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::candado('psepaipa_factura_' . $idPredio);
            if (self::enTramite($idPredio) !== null) {
                $pdo->rollBack();
                return null;
            }
            $filas = Db::filas(
                'SELECT ultimo_anio, total_calculo FROM predios_pagos
                  WHERE id_predio = ? AND factura_pago = ? AND pagado = 0 AND ISNULL(anulada, 0) = 0 ORDER BY ultimo_anio',
                [$idPredio, $factura]
            );
            if (array_map(fn($f) => (int) $f['ultimo_anio'], $filas) !== range($desde, $hasta)
                || (int) round(array_sum(array_map(fn($f) => (float) $f['total_calculo'], $filas))) !== $valor) {
                throw new AvisoUsuario('La factura de este predio cambió mientras se preparaba el pago. Vuelve a abrir el predio e intenta de nuevo.');
            }
            $id = (int) Db::valor(
                "INSERT INTO generacion_pagos
                    (codigo_entidad, factura, fuente_pago, ID_impuesto, propietario_identificacion, referencia,
                     tipo_implementacion, total, created_at, Impuesto, EstadoTransaccion, MedioPago, Activo, proveedor)
                 OUTPUT INSERTED.IdGeneraPado
                 VALUES (?, ?, 1, 1, ?, ?, 7, ?, ?, ?, 'COMENZADA', ?, 1, ?)",
                [(string) (Factura::ean() ?? ''), $factura, $documento, (string) $idPredio, $valor,
                 date('Y-m-d\TH:i:s'), 'Predial ' . ($desde === $hasta ? $desde : $desde . '-' . $hasta),
                 $unoCeroUno ? UnoCeroUno::MEDIO : 'PSE', $unoCeroUno ? UnoCeroUno::proveedor() : self::proveedor()]
            );
            $pdo->commit();
            return $id;
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
    }

    public static function anotarSesion(int $id, string $requestId, string $url): void
    {
        Db::ejecutar('UPDATE generacion_pagos SET IDTransaccion = ?, URL = ? WHERE IdGeneraPado = ?', [$requestId, $url, $id]);
    }

    /** AvalPay no creó la sesión: el intento se cierra para poder reintentar. */
    public static function fallo(int $id, string $mensaje): void
    {
        Db::ejecutar(
            "UPDATE generacion_pagos SET EstadoTransaccion = 'FAILED', Mensaje = ?, Activo = 0 WHERE IdGeneraPado = ?",
            [mb_substr($mensaje, 0, 100), $id]
        );
    }

    /** ¿La sesión consultada es de este intento? (la referencia es su factura). */
    public static function sesionDelIntento(array $respuesta, array $i): bool
    {
        $ref = (string) ($respuesta['request']['payment']['reference'] ?? '');
        return $ref === '' || $ref === trim((string) $i['factura']);
    }

    /**
     * Consulta AvalPay y aplica el resultado al intento (y a la base si quedó
     * aprobado). Devuelve el intento actualizado y lo interpretado.
     */
    public static function actualizar(array $i): array
    {
        if ((int) $i['Activo'] !== 1 || trim((string) $i['IDTransaccion']) === '') {
            return ['intento' => $i, 'info' => null];
        }
        if (self::esUnoCeroUno($i)) {
            // 1cero1pay se consulta por la factura (como el "Validar pago" del viejo).
            $info = UnoCeroUno::interpretar(UnoCeroUno::consultar(trim((string) $i['factura'])), trim((string) $i['IDTransaccion']));
            self::aplicar($i, $info);
            return ['intento' => self::intento((int) $i['IdGeneraPado']), 'info' => $info];
        }
        $r = AvalPay::consultar((string) $i['IDTransaccion']);
        if (!self::sesionDelIntento($r, $i)) {
            error_log('[psepaipa pago] la sesión ' . $i['IDTransaccion'] . ' no es de la factura ' . $i['factura']);
            return ['intento' => $i, 'info' => null];
        }
        $info = AvalPay::interpretar($r);
        self::aplicar($i, $info);
        return ['intento' => self::intento((int) $i['IdGeneraPado']), 'info' => $info];
    }

    /** Guarda el estado; si el banco aprobó, registra el pago. Idempotente. */
    public static function aplicar(array $i, array $info): void
    {
        $id = (int) $i['IdGeneraPado'];
        if ($info['aprobado']) {
            try {
                self::registrar($i, $info);
            } catch (Throwable $t) {
                // El banco aprobó y no se pudo registrar: que quede el rastro
                // (APPROVED con Activo = 1 bloquea el predio sin límite y la
                // tarea lo reintenta) y que el error suba al log.
                Db::ejecutar(
                    "UPDATE generacion_pagos SET EstadoTransaccion = 'APPROVED', Mensaje = ?, CUS = ?, MedioPago = ?
                      WHERE IdGeneraPado = ? AND Activo = 1",
                    [mb_substr('Aprobado, sin registrar: ' . $t->getMessage(), 0, 100), mb_substr($info['autorizacion'], 0, 50),
                     mb_substr($info['banco'], 0, 50), $id]
                );
                throw $t;
            }
            return;
        }
        $estado = $info['estado'];
        $activo = in_array($estado, self::FINALES_SIN_PAGO, true) ? 0 : 1;
        // Sesión vencida sin ningún intento en el banco: ya no puede pagarse.
        if ($info['sinIntento'] && strtotime((string) $i['created_at']) < time() - self::MINUTOS_COMENZADA * 60) {
            $estado = 'EXPIRED';
            $activo = 0;
        }
        Db::ejecutar(
            'UPDATE generacion_pagos SET EstadoTransaccion = ?, Mensaje = ?, MedioPago = ?, Activo = ?
              WHERE IdGeneraPado = ? AND Activo = 1',
            [$estado, $info['mensaje'], mb_substr($info['banco'], 0, 50), $activo, $id]
        );
    }

    /**
     * Registra el pago aprobado en la base oficial. Nunca lanza por un caso
     * de negocio: lo que no cuadra queda marcado DOBLE o REVISAR.
     */
    private static function registrar(array $i, array $info): void
    {
        $id = (int) $i['IdGeneraPado'];
        $factura = trim((string) $i['factura']);
        $idPredio = (int) $i['referencia'];
        [$desde, $hasta] = self::rango($i);
        $valor = $info['valor'] ?? (int) $i['total'];
        $fecha = self::fechaColombia($info['fecha']);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::candado('psepaipa_pago_factura_' . $factura);
            $actual = Db::fila('SELECT Activo, EstadoTransaccion FROM generacion_pagos WITH (UPDLOCK) WHERE IdGeneraPado = ?', [$id]);
            if ($actual === null || (int) $actual['Activo'] !== 1) {
                $pdo->commit();   // ya lo aplicó otro (aviso, retorno o tarea)
                return;
            }

            $marca = function (string $estado, string $mensaje) use ($id, $info): void {
                Db::ejecutar(
                    'UPDATE generacion_pagos SET EstadoTransaccion = ?, Mensaje = ?, CUS = ?, MedioPago = ?, Activo = 0 WHERE IdGeneraPado = ?',
                    [$estado, mb_substr($mensaje, 0, 100), mb_substr($info['autorizacion'], 0, 50), mb_substr($info['banco'], 0, 50), $id]
                );
                error_log('[psepaipa pago] intento ' . $id . ' ' . $estado . ': ' . $mensaje);
            };

            // Un APPROVED del ambiente de pruebas de la pasarela no es plata real.
            $uno = self::esUnoCeroUno($i);
            if ($uno ? (UnoCeroUno::esPruebas() && !config('unocerouno.registrar_pruebas', false))
                     : (AvalPay::esPruebas() && !config('avalpay.registrar_pruebas', false))) {
                $marca('PRUEBAS', 'Aprobado en el ambiente de pruebas de ' . ($uno ? '1cero1pay' : 'AvalPay') . ': no se registra');
                $pdo->commit();
                return;
            }
            if ((int) Db::valor('SELECT COUNT(*) FROM pagos WHERE CAST(numero_recibo AS NVARCHAR(50)) = ?', [$factura]) > 0) {
                $marca('DOBLE', 'Aprobado, pero la factura ya estaba pagada');
                $pdo->commit();
                return;
            }
            $filas = Db::filas(
                'SELECT * FROM predios_pagos WITH (UPDLOCK)
                  WHERE factura_pago = ? AND id_predio = ? AND pagado = 0 AND ISNULL(anulada, 0) = 0
                  ORDER BY ultimo_anio',
                [$factura, $idPredio]
            );
            $anios = array_map(fn($f) => (int) $f['ultimo_anio'], $filas);
            if (!$filas || $desde === 0 || $anios !== range($desde, $hasta)) {
                $pagadas = (int) Db::valor(
                    'SELECT COUNT(*) FROM predios_pagos WHERE factura_pago = ? AND id_predio = ? AND pagado <> 0',
                    [$factura, $idPredio]
                );
                $marca($pagadas > 0 ? 'DOBLE' : 'REVISAR', 'Aprobado, pero las vigencias de la factura cambiaron');
                $pdo->commit();
                return;
            }
            $debe = (int) round(array_sum(array_map(fn($f) => (float) $f['total_calculo'], $filas)));
            if ($valor + 1 < $debe) {
                $marca('REVISAR', 'Aprobado por ' . $valor . ', la factura vale ' . $debe);
                $pdo->commit();
                return;
            }

            $banco = self::idBancoPse($uno);
            $ultima = end($filas);
            $columnas = ['fecha_pago', 'numero_recibo', 'id_predio', 'valor_facturado', 'anio_pago', 'fecha_factura',
                         'id_banco_factura', 'id_banco_archivo', 'origen', 'acuerdo', 'created_at', 'updated_at'];
            $ahora = date('Y-m-d\TH:i:s');
            $emision = $ultima['fecha_emision'] ? substr((string) $ultima['fecha_emision'], 0, 10) . 'T00:00:00' : $fecha;
            $valores = [$fecha, $factura, $idPredio, $valor, (int) $ultima['ultimo_anio'],
                        $emision, $banco, $banco, 'A', 0, $ahora, $ahora];
            if (self::pagosTieneUsuario()) {
                $columnas[] = 'id_usuario';
                $valores[] = (int) config('usuario_sistema', 1);
            }
            Db::ejecutar(
                'INSERT INTO pagos (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')',
                $valores
            );
            $ids = array_map(fn($f) => (int) $f['id'], $filas);
            Db::ejecutar(
                'UPDATE predios_pagos SET fecha_pago = ?, valor_pago = total_calculo, id_banco = ?, pagado = -1
                  WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge([$fecha, $banco], $ids)
            );
            Db::ejecutar(
                'UPDATE predios SET ultimo_anio_pago = ? WHERE id = ? AND (ultimo_anio_pago IS NULL OR ultimo_anio_pago < ?)',
                [(int) $ultima['ultimo_anio'], $idPredio, (int) $ultima['ultimo_anio']]
            );
            Db::ejecutar(
                "UPDATE generacion_pagos SET EstadoTransaccion = 'APPROVED', Mensaje = ?, CUS = ?, MedioPago = ?, total = ?, Activo = 0
                  WHERE IdGeneraPado = ?",
                [mb_substr('Pago registrado' . ($valor > $debe + 1 ? ' (entró ' . $valor . ', valía ' . $debe . ')' : ''), 0, 100),
                 mb_substr($info['autorizacion'], 0, 50), mb_substr($info['banco'], 0, 50), $valor, $id]
            );
            $pdo->commit();
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
    }

    /** bancos.id del recaudo en línea (asobancaria 999, o el del config de cada pasarela). */
    private static function idBancoPse(bool $unoCeroUno = false): int
    {
        $c = (int) config($unoCeroUno ? 'unocerouno.id_banco' : 'avalpay.id_banco', 0);
        if ($c > 0) {
            return $c;
        }
        $id = Db::valor("SELECT TOP 1 id FROM bancos WHERE asobancaria = '999' ORDER BY id");
        if ($id === null) {
            throw new RuntimeException('No hay banco PSE (asobancaria 999) en la tabla bancos; configure avalpay.id_banco.');
        }
        return (int) $id;
    }

    private static function pagosTieneUsuario(): bool
    {
        return (int) Db::valor("SELECT COUNT(*) FROM sys.columns WHERE object_id = OBJECT_ID('pagos') AND name = 'id_usuario'") > 0;
    }

    /**
     * Fecha del banco en hora de Colombia, a medianoche y en ISO con "T":
     * predios_pagos.fecha_pago es datetime y, con un login en español,
     * '2026-10-13' se lee día-mes (error o fecha invertida).
     */
    private static function fechaColombia(string $iso): string
    {
        try {
            return (new DateTime($iso))->setTimezone(new DateTimeZone('America/Bogota'))->format('Y-m-d\T00:00:00');
        } catch (Throwable $t) {
            return date('Y-m-d\T00:00:00');
        }
    }

    /**
     * Enlace de vuelta firmado (sirve aunque la sesión del portal haya vencido).
     * Con AvalPay, la misma firma de siempre (los enlaces ya enviados siguen
     * valiendo); sin AvalPay, con la clave de 1cero1pay.
     */
    public static function urlRetorno(int $id): string
    {
        return urlPublica() . '/retorno.php?i=' . $id . '&t=' . self::firmaRetorno($id);
    }

    public static function firmaRetorno(int $id): string
    {
        if (AvalPay::configurado()) {
            return AvalPay::firmaRetorno($id);
        }
        return substr(hash_hmac('sha256', 'retorno|' . $id, UnoCeroUno::secreto()), 0, 32);
    }

    public static function firmaValida(int $id, string $firma): bool
    {
        return $id > 0 && (AvalPay::configurado() || UnoCeroUno::configurado()) && hash_equals(self::firmaRetorno($id), $firma);
    }
}
