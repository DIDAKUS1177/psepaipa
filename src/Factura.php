<?php
/**
 * Factura (liquidación oficial) del predial para un rango de vigencias.
 *
 * Sigue al sistema oficial (PrediosController::generate_factura_pdf_vigencias
 * del Laravel de predial) en lo que importa para que el banco y el recaudo
 * por archivo la reconozcan:
 *  - las cifras las deja SP_CALCULO_PREDIAL (@ANO = desde, @ANO_FIN = hasta);
 *  - el número es AAAA + consecutivo de 5 cifras de anios.numero_factura_actual
 *    del año en curso, y queda en predios_pagos.factura_pago de cada vigencia;
 *  - el valor es la suma de total_calculo hasta primer_fecha; con una sola
 *    vigencia se agregan los cupones de segunda y tercera fecha si siguen
 *    vigentes (total_dos / total_tres);
 *  - el código de barras es GS1-128: FNC1 415 EAN 8020 factura(24) FNC1 3900
 *    valor(14) FNC1 96 fecha(AAAAMMDD).
 *
 * Diferencias a propósito con el oficial (revisión del 2026-10-12):
 *  - antes de calcular se valida que TODAS las vigencias pedidas existan y
 *    sigan pendientes (el procedimiento reescribe filas pagadas y crea años
 *    que no existían), y el cálculo corre dentro de la misma transacción que
 *    la numeración: si algo falla, no queda nada guardado;
 *  - el consecutivo se toma con candado y se salta cualquier número que ya
 *    exista en predios_pagos o en pagos (el recaudo por archivo busca solo por
 *    número, sin el predio);
 *  - un número solo se reutiliza si es de este predio, no está pagado ni en
 *    pagos, y ninguna otra vigencia lo tiene;
 *  - al renumerar, el número viejo se libera de las vigencias que quedan por
 *    fuera, para que un recibo viejo no pague filas que ya no cubre;
 *  - límites de facturas nuevas por usuario, por predio y por hora.
 */

declare(strict_types=1);

final class Factura
{
    const FNC1 = "\xF1";

    const MAX_POR_USUARIO_DIA = 10;
    const MAX_POR_PREDIO_DIA  = 4;
    const MAX_GLOBAL_HORA     = 300;

    /**
     * Genera (o reimprime) la factura. Devuelve los datos para el PDF.
     * Lanza AvisoUsuario con un mensaje para el contribuyente.
     */
    public static function generar(int $idPredio, int $desde, int $hasta, int $idUsuario): array
    {
        $anioActual = (int) date('Y');
        if ($desde < 1900 || $hasta > $anioActual || $desde > $hasta) {
            throw new AvisoUsuario('Las vigencias elegidas no son válidas.');
        }

        $pdo = Db::pdo();
        $nuevo = false;
        $pdo->beginTransaction();
        try {
            Db::candado('psepaipa_factura_' . $idPredio);

            // Con un pago en línea en curso no se renumera: el pago llegaría
            // con un número que ya no tienen las vigencias.
            if (PagoLinea::enTramite($idPredio) !== null) {
                throw new AvisoUsuario('Este predio tiene un pago en línea en curso. Espera a que el banco lo confirme antes de generar otra factura.');
            }

            // 1. Validar ANTES del cálculo oficial.
            Predios::validarRango($idPredio, $desde, $hasta, true);

            // 2. Cálculo oficial, dentro de la transacción.
            Predios::calcular($idPredio, $desde, $hasta);

            // 3. Leer lo calculado.
            $filas = Db::filas(
                'SELECT pp.* FROM predios_pagos pp WITH (UPDLOCK)
                  WHERE pp.id_predio = ? AND pp.ultimo_anio BETWEEN ? AND ? AND ' . Predios::SE_DEBE . ' AND ISNULL(pp.acuerdo, 0) = 0
                  ORDER BY pp.ultimo_anio',
                [$idPredio, $desde, $hasta]
            );
            $anios = array_map(fn($f) => (int) $f['ultimo_anio'], $filas);
            if ($anios !== range($desde, $hasta)) {
                throw new AvisoUsuario('Alguna de las vigencias elegidas ya no está pendiente de pago. Vuelve a abrir el predio.');
            }
            $total = array_sum(array_map(fn($f) => (float) $f['total_calculo'], $filas));
            if ($total <= 0) {
                throw new AvisoUsuario('Las vigencias elegidas no tienen valor a pagar.');
            }

            // 4. Número: el mismo si se puede; si no, uno nuevo (con límites).
            $ids = array_map(fn($f) => (int) $f['id'], $filas);
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $numero = self::numeroReutilizable($idPredio, $filas, $ids, $marcas);
            if ($numero === null) {
                self::revisarLimites($idPredio, $idUsuario);
                $numero = self::siguienteNumero($anioActual);
                $nuevo = true;
                // Los números viejos de estas vigencias se liberan también en las
                // que quedan por fuera: un recibo viejo no debe pagar filas sueltas.
                $viejos = array_unique(array_filter(array_map(fn($f) => trim((string) $f['factura_pago']), $filas)));
                foreach ($viejos as $viejo) {
                    Db::ejecutar(
                        "UPDATE predios_pagos SET factura_pago = NULL
                          WHERE id_predio = ? AND factura_pago = ? AND pagado = 0 AND id NOT IN ($marcas)",
                        array_merge([$idPredio, $viejo], $ids)
                    );
                }
            }
            Db::ejecutar(
                "UPDATE predios_pagos SET factura_pago = ?, fecha_emision = ? WHERE id IN ($marcas)",
                array_merge([$numero, date('Y-m-d\TH:i:s')], $ids)
            );
            $pdo->commit();
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
        if ($nuevo) {
            Limites::anotar('factura_usuario_' . $idUsuario);
            Limites::anotar('factura_predio_' . $idPredio);
        }

        return [
            'numero'  => $numero,
            'emision' => date('Y-m-d H:i:s'),
            'filas'   => $filas,
            'cupones' => self::cupones($filas),
            'ean'     => self::ean(),
        ];
    }

    /** Frena el abuso: cada factura nueva gasta un número del consecutivo oficial. */
    private static function revisarLimites(int $idPredio, int $idUsuario): void
    {
        if (Limites::excedido('factura_usuario_' . $idUsuario, self::MAX_POR_USUARIO_DIA, 86400)
            || Limites::excedido('factura_predio_' . $idPredio, self::MAX_POR_PREDIO_DIA, 86400)) {
            throw new AvisoUsuario('Ya generaste varias facturas nuevas hoy. Usa la última que descargaste o intenta mañana.');
        }
        $hora = (int) Db::valor(
            'SELECT COUNT(DISTINCT factura_pago) FROM predios_pagos WHERE fecha_emision >= ?',
            [date('Y-m-d\TH:i:s', time() - 3600)]
        );
        if ($hora >= (int) config('facturas_hora_max', self::MAX_GLOBAL_HORA)) {
            error_log('[psepaipa] Freno global: ' . $hora . ' facturas en la última hora.');
            throw new AvisoUsuario('En este momento hay muchas solicitudes. Intenta de nuevo en unos minutos.');
        }
    }

    /**
     * El número que ya tienen las vigencias, si se puede reutilizar sin
     * riesgo para el recaudo por archivo. Si no, null (número nuevo).
     */
    private static function numeroReutilizable(int $idPredio, array $filas, array $ids, string $marcas): ?string
    {
        $n = trim((string) (end($filas)['factura_pago'] ?? ''));
        if ($n === '') {
            return null;
        }
        foreach ($filas as $f) {
            $otro = trim((string) ($f['factura_pago'] ?? ''));
            if ($otro !== '' && $otro !== $n) {
                return null;
            }
        }
        $choques = (int) Db::valor(
            "SELECT COUNT(*) FROM predios_pagos
              WHERE factura_pago = ? AND (id_predio <> ? OR pagado <> 0 OR id NOT IN ($marcas))",
            array_merge([$n, $idPredio], $ids)
        );
        if ($choques > 0 || self::enPagos($n)) {
            return null;
        }
        return $n;
    }

    private static function enPagos(string $numero): bool
    {
        return (int) Db::valor('SELECT COUNT(*) FROM pagos WHERE CAST(numero_recibo AS NVARCHAR(50)) = ?', [$numero]) > 0;
    }

    /**
     * Consecutivo del año en curso, con la misma regla del sistema oficial,
     * saltando números que ya existan (el sistema oficial también los toma,
     * sin candado).
     */
    private static function siguienteNumero(int $anio): string
    {
        Db::candado('psepaipa_consecutivo_factura');
        $a = Db::fila('SELECT id, numero_factura_inicial, numero_factura_actual FROM anios WITH (UPDLOCK) WHERE anio = ?', [$anio]);
        if ($a === null) {
            throw new AvisoUsuario('El año ' . $anio . ' no está abierto en el sistema de predial. Comunícate con la Alcaldía.');
        }
        $ini = (int) $a['numero_factura_inicial'];
        $act = (int) $a['numero_factura_actual'];
        if ($act === 0) {
            $seq = $ini === 0 ? 1 : $ini;
        } elseif ($ini !== 0 && $ini > $act) {
            $seq = $ini;
        } else {
            $seq = $act;
        }
        for ($intentos = 0; ; $intentos++) {
            if ($seq > 99999 || $intentos > 500) {
                throw new AvisoUsuario('No hay números de factura disponibles. Comunícate con la Alcaldía.');
            }
            $numero = $anio . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
            $usado = (int) Db::valor('SELECT COUNT(*) FROM predios_pagos WHERE factura_pago = ?', [$numero]) > 0 || self::enPagos($numero);
            if (!$usado) {
                break;
            }
            $seq++;
        }
        Db::ejecutar(
            'UPDATE anios SET numero_factura_actual = ?, numero_factura_inicial = CASE WHEN ISNULL(numero_factura_inicial, 0) = 0 THEN ? ELSE numero_factura_inicial END WHERE id = ?',
            [$seq + 1, $seq, (int) $a['id']]
        );
        return $numero;
    }

    /**
     * Cupones de pago: [valor, fecha límite (Y-m-d), % descuento].
     * Mismo criterio del sistema oficial.
     */
    public static function cupones(array $filas): array
    {
        $hoy = hoy();
        $ultima = end($filas);
        $previas = 0.0;
        foreach ($filas as $f) {
            if ((int) $f['ultimo_anio'] < (int) $ultima['ultimo_anio']) {
                $previas += (float) $f['total_calculo'];
            }
        }
        $fecha = function ($v): ?string {
            return $v === null || $v === '' ? null : substr((string) $v, 0, 10);
        };
        $f1 = $fecha($ultima['primer_fecha']);
        $cupones = [[
            'valor'     => (int) round($previas + (float) $ultima['total_calculo']),
            'hasta'     => ($f1 !== null && $f1 >= $hoy) ? $f1 : $hoy,
            'descuento' => (float) $ultima['porcentaje_uno'],
        ]];
        if (count($filas) === 1 && $f1 !== null) {
            $f2 = $fecha($ultima['segunda_fecha']);
            $f3 = $fecha($ultima['tercera_fecha']);
            if ($f2 !== null && $hoy <= $f2 && $f1 < $f2) {
                $cupones[] = ['valor' => (int) round((float) $ultima['total_dos']), 'hasta' => $f2, 'descuento' => (float) $ultima['porcentaje_dos']];
            }
            if ($f3 !== null && $f2 !== null && $hoy <= $f3 && $f2 < $f3) {
                $cupones[] = ['valor' => (int) round((float) $ultima['total_tres']), 'hasta' => $f3, 'descuento' => (float) $ultima['porcentaje_tres']];
            }
        }
        return $cupones;
    }

    /** EAN del convenio de recaudo de predial (parametros.ean). */
    public static function ean(): ?string
    {
        $v = Db::valor("SELECT TOP 1 valor FROM parametros WHERE nombre = 'ean' ORDER BY CASE WHEN estado = 1 THEN 0 ELSE 1 END, id");
        $v = preg_replace('/\D/', '', (string) $v);
        return strlen((string) $v) === 13 ? $v : null;
    }

    /** Contenido GS1-128 (con FNC1) y la línea legible para el cajero. */
    public static function codigoBarras(string $ean, string $numero, int $valor, string $hasta): array
    {
        $factura = str_pad(preg_replace('/\D/', '', $numero), 24, '0', STR_PAD_LEFT);
        $monto = str_pad((string) max(0, $valor), 14, '0', STR_PAD_LEFT);
        $fecha = str_replace('-', '', $hasta);
        return [
            self::FNC1 . '415' . $ean . '8020' . $factura . self::FNC1 . '3900' . $monto . self::FNC1 . '96' . $fecha,
            '(415)' . $ean . '(8020)' . $factura . '(3900)' . $monto . '(96)' . $fecha,
        ];
    }
}
