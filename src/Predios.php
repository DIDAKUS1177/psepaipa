<?php
/**
 * Predios, propietarios y estado de cuenta, leídos de la base del sistema
 * oficial de predial (Laravel).
 *
 * Las cifras NO se calculan aquí: las calcula el procedimiento oficial
 * SP_CALCULO_PREDIAL, que escribe cada vigencia en predios_pagos (el mismo
 * que corre el sistema de la Alcaldía al previsualizar una factura). Este
 * portal lo ejecuta con la fecha de hoy y lee lo que dejó.
 *
 * En predios_pagos las banderas usan -1 como verdadero (pagado, anulada,
 * prescrito, exencion, acuerdo).
 */

declare(strict_types=1);

final class Predios
{
    /** Condición de una vigencia que se debe y se puede pagar en línea. */
    const SE_DEBE = 'pp.pagado = 0 AND ISNULL(pp.anulada, 0) = 0 AND ISNULL(pp.prescrito, 0) = 0';

    /**
     * Vigencia dentro de un acuerdo de pago ACTIVO. El sistema oficial no marca
     * predios_pagos.acuerdo: el acuerdo vive en predios_acuerdos_pago
     * (estado_acuerdo = 1, años anio_inicial_acuerdo a anio_final_acuerdo) y se
     * paga por cuotas en la Alcaldía. Esas vigencias no se pagan ni se facturan
     * aquí (revisión del 2026-10-13: en la copia había 10 acuerdos activos con
     * la marca en 0 y el portal las dejaba pagar).
     */
    const EN_ACUERDO = '(ISNULL(pp.acuerdo, 0) <> 0 OR EXISTS (SELECT 1 FROM predios_acuerdos_pago ap
                          WHERE ap.id_predio = pp.id_predio AND ap.estado_acuerdo = 1
                            AND pp.ultimo_anio BETWEEN ap.anio_inicial_acuerdo AND ap.anio_final_acuerdo))';
    const SIN_ACUERDO = 'NOT ' . self::EN_ACUERDO;

    /** Referencia catastral como se guarda: solo dígitos. */
    public static function normalizarReferencia(string $ref): string
    {
        return preg_replace('/\D/', '', $ref) ?? '';
    }

    /**
     * Predios de un documento (como propietario principal o copropietario).
     * La identificación del propietario se compara sin puntos ni guiones; si
     * en la base trae dígito de verificación (NIT con guion), también se
     * reconoce el número sin él.
     */
    public static function delDocumento(string $documento): array
    {
        $doc = Cuentas::normalizarDocumento($documento);
        if ($doc === '') {
            return [];
        }
        return Db::filas(
            "SELECT p.id, p.codigo_predio, p.codigo_predio_anterior, p.direccion, p.avaluo, p.ultimo_anio_pago,
                    z.descripcion AS zona, MIN(prp.jerarquia) AS jerarquia,
                    d.vigencias_debe, d.total_debe, d.debe_desde
               FROM propietarios pr
               JOIN predios_propietarios prp ON prp.id_propietario = pr.id
               JOIN predios p ON p.id = prp.id_predio
               LEFT JOIN zonas z ON z.id = p.id_zona
               OUTER APPLY (SELECT COUNT(*) AS vigencias_debe, SUM(pp.total_calculo) AS total_debe, MIN(pp.ultimo_anio) AS debe_desde
                              FROM predios_pagos pp WHERE pp.id_predio = p.id AND " . self::SE_DEBE . ") d
              WHERE p.estado = 1
                AND (REPLACE(REPLACE(REPLACE(pr.identificacion, '.', ''), '-', ''), ' ', '') = ?
                     OR (CHARINDEX('-', pr.identificacion) > 1
                         AND REPLACE(REPLACE(LEFT(pr.identificacion, CHARINDEX('-', pr.identificacion) - 1), '.', ''), ' ', '') = ?))
              GROUP BY p.id, p.codigo_predio, p.codigo_predio_anterior, p.direccion, p.avaluo, p.ultimo_anio_pago, z.descripcion,
                       d.vigencias_debe, d.total_debe, d.debe_desde
              ORDER BY p.codigo_predio",
            [$doc, $doc]
        );
    }

    /**
     * Busca por referencia catastral, actual o anterior. Devuelve los ids
     * (normalmente uno).
     */
    public static function buscar(string $referencia): array
    {
        $ref = self::normalizarReferencia($referencia);
        if (strlen($ref) < 8) {
            return [];
        }
        return Db::filas(
            "SELECT TOP 10 p.id, p.codigo_predio, p.codigo_predio_anterior, p.direccion, p.ultimo_anio_pago, z.descripcion AS zona,
                    (SELECT TOP 1 pr.nombre FROM predios_propietarios prp JOIN propietarios pr ON pr.id = prp.id_propietario
                      WHERE prp.id_predio = p.id ORDER BY prp.jerarquia) AS propietario,
                    d.vigencias_debe, d.total_debe, d.debe_desde
               FROM predios p
               LEFT JOIN zonas z ON z.id = p.id_zona
               OUTER APPLY (SELECT COUNT(*) AS vigencias_debe, SUM(pp.total_calculo) AS total_debe, MIN(pp.ultimo_anio) AS debe_desde
                              FROM predios_pagos pp WHERE pp.id_predio = p.id AND " . self::SE_DEBE . ") d
              WHERE p.estado = 1
                AND (REPLACE(REPLACE(p.codigo_predio, '-', ''), ' ', '') = ?
                     OR REPLACE(REPLACE(p.codigo_predio_anterior, '-', ''), ' ', '') = ?)
              ORDER BY p.id",
            [$ref, $ref]
        );
    }

    public static function detalle(int $id): ?array
    {
        $p = Db::fila(
            "SELECT p.id, p.codigo_predio, p.codigo_predio_anterior, p.direccion, p.avaluo, p.ultimo_anio_pago,
                    p.area_metros, p.area_construida, p.area_hectareas, p.tarifa_actual, p.destino_economico,
                    z.descripcion AS zona,
                    (SELECT TOP 1 d.matricula_inmobiliaria FROM predios_datos d WHERE d.id_predio = p.id ORDER BY d.id DESC) AS matricula
               FROM predios p
               LEFT JOIN zonas z ON z.id = p.id_zona
              WHERE p.id = ? AND p.estado = 1",
            [$id]
        );
        if ($p === null) {
            return null;
        }
        $p['propietarios'] = Db::filas(
            "SELECT pr.identificacion, pr.nombre, prp.jerarquia
               FROM predios_propietarios prp
               JOIN propietarios pr ON pr.id = prp.id_propietario
              WHERE prp.id_predio = ?
              ORDER BY prp.jerarquia",
            [$id]
        );
        return $p;
    }

    /** ¿El documento es propietario (principal o no) del predio? */
    public static function esPropietario(int $id, string $documento): bool
    {
        $doc = Cuentas::normalizarDocumento($documento);
        if ($doc === '') {
            return false;
        }
        return (int) Db::valor(
            "SELECT COUNT(*) FROM predios_propietarios prp JOIN propietarios pr ON pr.id = prp.id_propietario
              WHERE prp.id_predio = ?
                AND (REPLACE(REPLACE(REPLACE(pr.identificacion, '.', ''), '-', ''), ' ', '') = ?
                     OR (CHARINDEX('-', pr.identificacion) > 1
                         AND REPLACE(REPLACE(LEFT(pr.identificacion, CHARINDEX('-', pr.identificacion) - 1), '.', ''), ' ', '') = ?))",
            [$id, $doc, $doc]
        ) > 0;
    }

    /**
     * Predios que esta sesión puede abrir: los suyos y los que encontró
     * buscando por referencia catastral. Así no se recorren todos por id.
     */
    public static function permitir(int $id): void
    {
        $_SESSION['predios_ok'][$id] = true;
    }

    public static function permitido(int $id, string $documento): bool
    {
        if (!empty($_SESSION['predios_ok'][$id])) {
            return true;
        }
        if (self::esPropietario($id, $documento)) {
            self::permitir($id);
            return true;
        }
        return false;
    }

    /**
     * Valida un rango ANTES de correr el cálculo oficial (que reescribe filas
     * pagadas y crea años que no existían): todas las vigencias del rango deben
     * existir y estar pendientes; solo la del año en curso puede faltar (la
     * crea el cálculo), y solo si es el final del rango. Lanza AvisoUsuario.
     */
    public static function validarRango(int $id, int $desde, int $hasta, bool $bloquear = false): void
    {
        $anio = (int) date('Y');
        if ($desde < 1900 || $hasta > $anio || $desde > $hasta) {
            throw new AvisoUsuario('Las vigencias elegidas no son válidas.');
        }
        $existentes = [];
        foreach (Db::filas(
            'SELECT pp.ultimo_anio, pp.pagado, pp.anulada, pp.prescrito, CASE WHEN ' . self::EN_ACUERDO . ' THEN 1 ELSE 0 END AS acuerdo
               FROM predios_pagos pp' . ($bloquear ? ' WITH (UPDLOCK)' : '') . '
              WHERE pp.id_predio = ? AND pp.ultimo_anio BETWEEN ? AND ?',
            [$id, $desde, $hasta]
        ) as $f) {
            $existentes[(int) $f['ultimo_anio']] = $f;
        }
        for ($a = $desde; $a <= $hasta; $a++) {
            if (!isset($existentes[$a])) {
                if ($a === $anio && $a === $hasta) {
                    continue;
                }
                throw new AvisoUsuario('La vigencia ' . $a . ' no está liquidada para este predio. Elige años seguidos de los que aparecen en el estado de cuenta.');
            }
            $f = $existentes[$a];
            if ((int) $f['pagado'] !== 0 || (int) $f['anulada'] !== 0 || (int) $f['prescrito'] !== 0 || (int) $f['acuerdo'] !== 0) {
                throw new AvisoUsuario('La vigencia ' . $a . ' ya no está pendiente de pago en línea. Vuelve a abrir el predio.');
            }
        }
    }

    /**
     * Rango que se cotiza al abrir el predio: el primer bloque de vigencias
     * pendientes SEGUIDAS (sin huecos), más la del año en curso si todavía no
     * existe y el bloque llega hasta el año anterior. Sin deudas: el año en
     * curso (el cálculo lo crea si falta, como el sistema oficial).
     */
    public static function rangoInicial(int $id): array
    {
        $anio = (int) date('Y');
        $anios = array_map('intval', array_column(Db::filas(
            'SELECT pp.ultimo_anio FROM predios_pagos pp WHERE pp.id_predio = ? AND ' . self::SE_DEBE . ' AND ' . self::SIN_ACUERDO . ' ORDER BY pp.ultimo_anio',
            [$id]
        ), 'ultimo_anio'));
        $existeActual = (int) Db::valor('SELECT COUNT(*) FROM predios_pagos WHERE id_predio = ? AND ultimo_anio = ?', [$id, $anio]) > 0;
        if (!$anios) {
            if ($existeActual) {
                return [null, null];
            }
            return [$anio, $anio];
        }
        $desde = $hasta = $anios[0];
        foreach ($anios as $a) {
            if ($a === $hasta + 1) {
                $hasta = $a;
            } elseif ($a > $hasta) {
                break;
            }
        }
        if (!$existeActual && $hasta === $anio - 1) {
            $hasta = $anio;
        }
        return [$desde, $hasta];
    }

    /**
     * Todos los bloques de vigencias pendientes SEGUIDAS, [[desde, hasta], ...],
     * con el año en curso agregado al último si todavía no existe (como
     * rangoInicial). Al abrir el predio se recalculan todos con la fecha de hoy.
     */
    public static function bloques(int $id): array
    {
        $anio = (int) date('Y');
        $anios = array_map('intval', array_column(Db::filas(
            'SELECT pp.ultimo_anio FROM predios_pagos pp WHERE pp.id_predio = ? AND ' . self::SE_DEBE . ' AND ' . self::SIN_ACUERDO . ' ORDER BY pp.ultimo_anio',
            [$id]
        ), 'ultimo_anio'));
        $existeActual = (int) Db::valor('SELECT COUNT(*) FROM predios_pagos WHERE id_predio = ? AND ultimo_anio = ?', [$id, $anio]) > 0;
        $bloques = [];
        foreach ($anios as $a) {
            $n = count($bloques);
            if ($n > 0 && $bloques[$n - 1][1] === $a - 1) {
                $bloques[$n - 1][1] = $a;
            } else {
                $bloques[] = [$a, $a];
            }
        }
        if (!$existeActual) {
            $n = count($bloques);
            if ($n > 0 && $bloques[$n - 1][1] === $anio - 1) {
                $bloques[$n - 1][1] = $anio;
            } elseif ($n === 0) {
                $bloques[] = [$anio, $anio];
            }
        }
        return $bloques;
    }

    /** Vigencia más antigua que se debe y se puede pagar (sin acuerdo), o null. */
    public static function primeraDeuda(int $id): ?int
    {
        $min = Db::valor(
            'SELECT MIN(pp.ultimo_anio) FROM predios_pagos pp WHERE pp.id_predio = ? AND ' . self::SE_DEBE . ' AND ' . self::SIN_ACUERDO,
            [$id]
        );
        return $min === null ? null : (int) $min;
    }

    /**
     * Cotiza SIN guardar: corre el procedimiento oficial para el rango dentro
     * de una transacción, lee las cifras y la deshace. Así ver un predio no
     * escribe nada en la base oficial; solo la factura guarda.
     *
     * Con $todo = true (al abrir el predio) recalcula también los demás
     * bloques pendientes, dentro de la misma transacción.
     *
     * Devuelve ['ok' => bool, 'filas' => estadoCuenta(), 'total' => valor del
     * primer cupón para el rango, 'hasta' => fecha límite de ese valor].
     * Con ok = false las cifras son las últimas guardadas.
     */
    public static function cotizar(int $id, ?int $desde = null, ?int $hasta = null, bool $todo = false): array
    {
        if ($desde === null || $hasta === null) {
            [$desde, $hasta] = self::rangoInicial($id);
        }
        if ($desde === null) {
            // Al día y con la vigencia actual ya liquidada: nada que calcular.
            $filas = self::estadoCuenta($id);
            return ['ok' => true, 'filas' => $filas, 'total' => 0, 'hasta' => hoy(), 'desde' => null, 'hastaAnio' => null,
                    'desglose' => ['impuesto' => 0, 'intereses' => 0, 'sobretasa' => 0, 'descuento' => 0, 'otros' => 0], 'rango' => []];
        }
        self::validarRango($id, $desde, $hasta);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        $ok = true;
        try {
            // Al abrir el predio ($todo) se recalculan TODAS las vigencias
            // pendientes con la fecha de hoy, no solo el rango elegido: así
            // ninguna cifra en pantalla queda con intereses de otro día. El
            // rango elegido va de último, para que sus filas tengan el
            // descuento de pagarlo junto.
            if ($todo) {
                foreach (self::bloques($id) as [$bd, $bh]) {
                    if ($bd !== $desde || $bh !== $hasta) {
                        self::calcularHoy($id, $bd, $bh);
                    }
                }
            }
            self::calcularHoy($id, $desde, $hasta);
        } catch (Throwable $t) {
            error_log('[psepaipa] cotizar predio ' . $id . ': ' . $t->getMessage());
            $ok = false;
        }
        try {
            if (!$ok && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $filas = self::estadoCuenta($id);
            $rango = Db::filas(
                'SELECT * FROM predios_pagos pp WHERE pp.id_predio = ? AND pp.ultimo_anio BETWEEN ? AND ? AND ' . self::SE_DEBE . ' AND ' . self::SIN_ACUERDO . ' ORDER BY pp.ultimo_anio',
                [$id, $desde, $hasta]
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $cupon = $rango ? Factura::cupones($rango)[0] : ['valor' => 0, 'hasta' => hoy()];

        // Desglose de lo que se paga en el rango (para "¿Qué estoy pagando?").
        $desglose = ['impuesto' => 0.0, 'intereses' => 0.0, 'sobretasa' => 0.0, 'descuento' => 0.0, 'otros' => 0.0];
        foreach ($filas as $f) {
            if ($f['pagable'] && $f['anio'] >= $desde && $f['anio'] <= $hasta) {
                foreach ($desglose as $k => $v) {
                    $desglose[$k] = $v + $f[$k];
                }
            }
        }
        // Si el cupón no coincide con la suma (redondeos del oficial), la
        // diferencia va a "otros" para que el desglose sume el total exacto.
        $desglose['otros'] += $cupon['valor'] - array_sum($desglose);

        return ['ok' => $ok, 'filas' => $filas, 'total' => $cupon['valor'], 'hasta' => $cupon['hasta'],
                'desde' => $desde, 'hastaAnio' => $hasta, 'desglose' => $desglose, 'rango' => $rango];
    }

    /**
     * Facturas ya emitidas del predio (por cualquier canal: ventanilla, el
     * PSE anterior o este portal), agrupadas por número.
     */
    public static function recibosEmitidos(int $id): array
    {
        return Db::filas(
            "SELECT TOP 20 pp.factura_pago AS numero, MIN(pp.ultimo_anio) AS desde, MAX(pp.ultimo_anio) AS hasta,
                    MAX(pp.fecha_emision) AS emision, SUM(pp.total_calculo) AS valor,
                    SUM(ISNULL(pp.valor_pago, 0)) AS pagado, MAX(pp.fecha_pago) AS fecha_pago,
                    MIN(CASE WHEN pp.pagado = 0 THEN 0 ELSE 1 END) AS todo_pagado
               FROM predios_pagos pp
              WHERE pp.id_predio = ? AND ISNULL(pp.factura_pago, '') <> '' AND ISNULL(pp.anulada, 0) = 0
              GROUP BY pp.factura_pago
              ORDER BY MAX(pp.fecha_emision) DESC, pp.factura_pago DESC",
            [$id]
        );
    }

    /**
     * Cálculo oficial de un rango con la fecha de hoy, INCLUIDO el año en curso.
     *
     * Probado contra el procedimiento (2026-10-13): con @ANO_FIN (rango de
     * varios años) recalcula todas las vigencias pendientes anteriores pero NO
     * la del año en curso, que se quedaba con la cifra del último día en que
     * alguien la calculó sola (en la prueba, $424 guardado contra $481 de
     * hoy). Con @ANO = año en curso y @ANO_FIN = 0 -lo que hace la "vista
     * previa" del sistema oficial- sí la recalcula. Por eso, si el rango llega
     * al año en curso, primero se corre así y luego el rango.
     */
    public static function calcularHoy(int $id, int $desde, int $hasta): void
    {
        $anio = (int) date('Y');
        if ($hasta === $anio && $desde !== $hasta) {
            self::calcular($id, $anio, $anio);
        }
        self::calcular($id, $desde, $hasta);
    }

    /** Corre el cálculo oficial (dentro de la transacción de quien llama). */
    public static function calcular(int $id, int $desde, int $hasta): void
    {
        $st = Db::pdo()->prepare('SET NOCOUNT ON; EXEC SP_CALCULO_PREDIAL @USUARIO_ID = ?, @ANO = ?, @ANO_FIN = ?, @PREDIO_ID = ?, @FECHA_PAGO = ?');
        $st->execute([(int) config('usuario_sistema', 1), $desde, $hasta === $desde ? 0 : $hasta, $id, hoy() . 'T00:00:00']);
        // Hay que consumir todos los resultados para que SQL Server termine.
        do {
            if ($st->columnCount() > 0) {
                $st->fetchAll();
            }
        } while ($st->nextRowset());
    }

    /**
     * Vigencias del predio para el estado de cuenta: todas las que se deben,
     * más las que están en acuerdo de pago o prescritas (para mostrarlas sin
     * poder pagarlas). "Otros" es lo que queda del total una vez separados
     * impuesto, intereses, sobretasa y descuentos, para que cada fila sume
     * exactamente su total oficial.
     */
    public static function estadoCuenta(int $id): array
    {
        $filas = Db::filas(
            "SELECT pp.id, pp.ultimo_anio AS anio, pp.avaluo, pp.tarifa, pp.pagado, pp.anulada, pp.prescrito, pp.exencion,
                    CASE WHEN " . self::EN_ACUERDO . " THEN 1 ELSE 0 END AS acuerdo,
                    pp.valor_concepto1 AS c1, pp.valor_concepto2 AS c2, pp.valor_concepto3 AS c3, pp.valor_concepto4 AS c4,
                    pp.valor_concepto13 AS c13, pp.valor_concepto15 AS c15,
                    pp.total_calculo, pp.total_dos, pp.total_tres,
                    pp.primer_fecha, pp.porcentaje_uno, pp.segunda_fecha, pp.porcentaje_dos, pp.tercera_fecha, pp.porcentaje_tres,
                    pp.factura_pago, pp.fecha_emision
               FROM predios_pagos pp
              WHERE pp.id_predio = ? AND pp.pagado = 0 AND ISNULL(pp.anulada, 0) = 0
              ORDER BY pp.ultimo_anio",
            [$id]
        );
        $out = [];
        foreach ($filas as $f) {
            $impuesto  = (float) $f['c1'];
            $intereses = (float) $f['c2'] + (float) $f['c4'];
            $sobretasa = (float) $f['c3'];
            $descuento = (float) $f['c13'] + (float) $f['c15'];
            $total     = (float) $f['total_calculo'];
            $estado = 'debe';
            if ((int) $f['prescrito'] !== 0) {
                $estado = 'prescrita';
            } elseif ((int) $f['acuerdo'] !== 0) {
                $estado = 'acuerdo';
            }
            $out[] = [
                'anio'       => (int) $f['anio'],
                'avaluo'     => (float) $f['avaluo'],
                'tarifa'     => round((float) $f['tarifa'] * 1000, 3),
                'impuesto'   => $impuesto,
                'intereses'  => $intereses,
                'sobretasa'  => $sobretasa,
                'descuento'  => $descuento,
                'otros'      => round($total - $impuesto - $intereses - $sobretasa - $descuento),
                'total'      => $total,
                'estado'     => $estado,
                'pagable'    => $estado === 'debe' && $total > 0,
                'factura'    => $f['factura_pago'],
                'primerFecha' => $f['primer_fecha'],
            ];
        }
        return $out;
    }

    /** Pagos registrados del predio (lo más reciente primero). */
    public static function historicoPagos(int $id): array
    {
        return Db::filas(
            "SELECT TOP 30 pg.fecha_pago, pg.numero_recibo, pg.valor_facturado, pg.anio_pago, b.nombre AS banco
               FROM pagos pg
               LEFT JOIN bancos b ON b.id = COALESCE(pg.id_banco_archivo, pg.id_banco_factura)
              WHERE pg.id_predio = ?
              ORDER BY pg.fecha_pago DESC, pg.id DESC",
            [$id]
        );
    }

    /** Oculta un nombre a quien no es el dueño: "FLAMINI****". */
    public static function enmascararNombre(string $nombre): string
    {
        $nombre = trim($nombre);
        $visible = mb_substr($nombre, 0, min(4, max(2, (int) floor(mb_strlen($nombre) / 4))));
        return $visible . str_repeat('*', max(4, min(20, mb_strlen($nombre) - mb_strlen($visible))));
    }

    public static function enmascararDocumento(string $doc): string
    {
        $doc = trim($doc);
        return mb_substr($doc, 0, 3) . str_repeat('*', max(3, mb_strlen($doc) - 3));
    }
}
