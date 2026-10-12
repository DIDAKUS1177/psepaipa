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
 * Dos diferencias a propósito con el oficial:
 *  - el consecutivo se toma con candado (el oficial no lo tiene: dos
 *    facturas al tiempo podían salir con el mismo número);
 *  - si alguna vigencia del rango ya está en OTRA factura, se numera de nuevo
 *    todo el rango. El oficial la sacaba de la factura en silencio, y la
 *    factura salía por menos años de los pedidos.
 */

declare(strict_types=1);

final class Factura
{
    const FNC1 = "\xF1";

    /**
     * Genera (o reimprime) la factura. Devuelve los datos para el PDF.
     * Lanza RuntimeException con un mensaje para el contribuyente.
     */
    public static function generar(int $idPredio, int $desde, int $hasta): array
    {
        $anioActual = (int) date('Y');
        if ($desde < 1990 || $hasta > $anioActual || $desde > $hasta) {
            throw new RuntimeException('Las vigencias elegidas no son válidas.');
        }

        $pdo = Db::pdo();
        // El procedimiento oficial corre fuera de la transacción, como en el
        // sistema de la Alcaldía: maneja sus propias transacciones.
        if (!Predios::recalcular($idPredio, $desde, $hasta)) {
            throw new RuntimeException('No pudimos calcular la factura en este momento. Intenta de nuevo en unos minutos.');
        }

        $pdo->beginTransaction();
        try {
            Db::candado('psepaipa_factura_' . $idPredio);

            $filas = Db::filas(
                'SELECT pp.* FROM predios_pagos pp WITH (UPDLOCK)
                  WHERE pp.id_predio = ? AND pp.ultimo_anio BETWEEN ? AND ? AND ' . Predios::SE_DEBE . ' AND ISNULL(pp.acuerdo, 0) = 0
                  ORDER BY pp.ultimo_anio',
                [$idPredio, $desde, $hasta]
            );
            $anios = array_map(fn($f) => (int) $f['ultimo_anio'], $filas);
            if ($anios !== range($desde, $hasta)) {
                throw new RuntimeException('Alguna de las vigencias elegidas ya no está pendiente de pago. Vuelve a abrir el predio.');
            }
            $total = array_sum(array_map(fn($f) => (float) $f['total_calculo'], $filas));
            if ($total <= 0) {
                throw new RuntimeException('Las vigencias elegidas no tienen valor a pagar.');
            }

            $ultima = end($filas);
            $numero = self::numeroReutilizable($idPredio, $filas, $ultima);
            if ($numero === null) {
                $numero = self::siguienteNumero($anioActual);
            }
            $ids = array_map(fn($f) => (int) $f['id'], $filas);
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            Db::ejecutar(
                "UPDATE predios_pagos SET factura_pago = ?, fecha_emision = ? WHERE id IN ($marcas)",
                array_merge([$numero, date('Y-m-d H:i:s')], $ids)
            );
            $pdo->commit();
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }

        return [
            'numero'   => $numero,
            'emision'  => date('Y-m-d H:i:s'),
            'filas'    => $filas,
            'cupones'  => self::cupones($filas),
            'ean'      => self::ean(),
        ];
    }

    /**
     * El número de la factura que ya tiene la última vigencia, si todas las
     * del rango están en esa misma factura o sin factura, y ninguna vigencia
     * fuera del rango la comparte. Si no, null (número nuevo).
     */
    private static function numeroReutilizable(int $idPredio, array $filas, array $ultima): ?string
    {
        $n = trim((string) ($ultima['factura_pago'] ?? ''));
        if ($n === '') {
            return null;
        }
        foreach ($filas as $f) {
            $otro = trim((string) ($f['factura_pago'] ?? ''));
            if ($otro !== '' && $otro !== $n) {
                return null;
            }
        }
        $ids = array_map(fn($f) => (int) $f['id'], $filas);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $fuera = (int) Db::valor(
            "SELECT COUNT(*) FROM predios_pagos WHERE id_predio = ? AND factura_pago = ? AND pagado = 0 AND id NOT IN ($marcas)",
            array_merge([$idPredio, $n], $ids)
        );
        return $fuera === 0 ? $n : null;
    }

    /** Consecutivo del año en curso, con la misma regla del sistema oficial. */
    private static function siguienteNumero(int $anio): string
    {
        Db::candado('psepaipa_consecutivo_factura');
        $a = Db::fila('SELECT id, numero_factura_inicial, numero_factura_actual FROM anios WITH (UPDLOCK) WHERE anio = ?', [$anio]);
        if ($a === null) {
            throw new RuntimeException('El año ' . $anio . ' no está abierto en el sistema de predial. Comunícate con la Alcaldía.');
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
        if ($seq > 99999) {
            throw new RuntimeException('Se agotó la numeración de facturas del año. Comunícate con la Alcaldía.');
        }
        Db::ejecutar(
            'UPDATE anios SET numero_factura_actual = ?, numero_factura_inicial = CASE WHEN ISNULL(numero_factura_inicial, 0) = 0 THEN ? ELSE numero_factura_inicial END WHERE id = ?',
            [$seq + 1, $seq, (int) $a['id']]
        );
        return $anio . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
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
