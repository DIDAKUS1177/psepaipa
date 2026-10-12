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
                    (SELECT COUNT(*) FROM predios_pagos pp WHERE pp.id_predio = p.id AND " . self::SE_DEBE . ") AS vigencias_debe,
                    (SELECT SUM(pp.total_calculo) FROM predios_pagos pp WHERE pp.id_predio = p.id AND " . self::SE_DEBE . ") AS total_debe,
                    (SELECT MIN(pp.ultimo_anio) FROM predios_pagos pp WHERE pp.id_predio = p.id AND " . self::SE_DEBE . ") AS debe_desde
               FROM propietarios pr
               JOIN predios_propietarios prp ON prp.id_propietario = pr.id
               JOIN predios p ON p.id = prp.id_predio
               LEFT JOIN zonas z ON z.id = p.id_zona
              WHERE p.estado = 1
                AND (REPLACE(REPLACE(REPLACE(pr.identificacion, '.', ''), '-', ''), ' ', '') = ?
                     OR (CHARINDEX('-', pr.identificacion) > 1
                         AND REPLACE(REPLACE(LEFT(pr.identificacion, CHARINDEX('-', pr.identificacion) - 1), '.', ''), ' ', '') = ?))
              GROUP BY p.id, p.codigo_predio, p.codigo_predio_anterior, p.direccion, p.avaluo, p.ultimo_anio_pago, z.descripcion
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
                    (SELECT COUNT(*) FROM predios_pagos pp WHERE pp.id_predio = p.id AND " . self::SE_DEBE . ") AS vigencias_debe,
                    (SELECT SUM(pp.total_calculo) FROM predios_pagos pp WHERE pp.id_predio = p.id AND " . self::SE_DEBE . ") AS total_debe,
                    (SELECT MIN(pp.ultimo_anio) FROM predios_pagos pp WHERE pp.id_predio = p.id AND " . self::SE_DEBE . ") AS debe_desde
               FROM predios p
               LEFT JOIN zonas z ON z.id = p.id_zona
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
        foreach (self::delDocumento($documento) as $p) {
            if ((int) $p['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    /** Vigencia más antigua que se debe y se puede pagar (sin acuerdo), o null. */
    public static function primeraDeuda(int $id): ?int
    {
        $min = Db::valor(
            'SELECT MIN(pp.ultimo_anio) FROM predios_pagos pp WHERE pp.id_predio = ? AND ' . self::SE_DEBE . ' AND ISNULL(pp.acuerdo, 0) = 0',
            [$id]
        );
        return $min === null ? null : (int) $min;
    }

    /**
     * Cotiza SIN guardar: corre el procedimiento oficial para el rango dentro
     * de una transacción, lee las cifras y la deshace. Así ver un predio no
     * escribe nada en la base oficial; solo la factura guarda.
     *
     * Devuelve ['ok' => bool, 'filas' => estadoCuenta(), 'total' => valor del
     * primer cupón para el rango, 'hasta' => fecha límite de ese valor].
     * Con ok = false las cifras son las últimas guardadas.
     */
    public static function cotizar(int $id, ?int $desde = null, ?int $hasta = null): array
    {
        $anio = (int) date('Y');
        if ($desde === null) {
            $min = self::primeraDeuda($id);
            $desde = $min !== null ? min($min, $anio) : $anio;
        }
        $hasta = $hasta ?? $anio;

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        $ok = true;
        try {
            self::ejecutarCalculo($id, $desde, $hasta);
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
                'SELECT * FROM predios_pagos pp WHERE pp.id_predio = ? AND pp.ultimo_anio BETWEEN ? AND ? AND ' . self::SE_DEBE . ' AND ISNULL(pp.acuerdo, 0) = 0 ORDER BY pp.ultimo_anio',
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

    private static function ejecutarCalculo(int $id, int $desde, int $hasta): void
    {
        $st = Db::pdo()->prepare('SET NOCOUNT ON; EXEC SP_CALCULO_PREDIAL @USUARIO_ID = ?, @ANO = ?, @ANO_FIN = ?, @PREDIO_ID = ?, @FECHA_PAGO = ?');
        $st->execute([(int) config('usuario_sistema', 1), $desde, $hasta === $desde ? 0 : $hasta, $id, hoy() . ' 00:00:00']);
        // Hay que consumir todos los resultados para que SQL Server termine.
        do {
            try {
                $st->fetchAll();
            } catch (PDOException $e) {
                // Un resultado sin columnas no se lee.
            }
        } while ($st->nextRowset());
    }

    /**
     * Guarda el cálculo oficial del rango (SP_CALCULO_PREDIAL con la fecha de
     * hoy), como hace el sistema de la Alcaldía al generar una factura. Solo
     * lo usa la factura; para mostrar cifras está cotizar(), que no guarda.
     */
    public static function recalcular(int $id, int $desde, int $hasta): bool
    {
        try {
            self::ejecutarCalculo($id, $desde, $hasta);
        } catch (Throwable $t) {
            error_log('[psepaipa] SP_CALCULO_PREDIAL predio ' . $id . ': ' . $t->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Vigencias del predio para el estado financiero: todas las que se deben,
     * más las que están en acuerdo de pago o prescritas (para mostrarlas sin
     * poder pagarlas). "Otros" es lo que queda del total una vez separados
     * impuesto, intereses, sobretasa y descuentos, para que cada fila sume
     * exactamente su total oficial.
     */
    public static function estadoCuenta(int $id): array
    {
        $filas = Db::filas(
            "SELECT pp.id, pp.ultimo_anio AS anio, pp.avaluo, pp.tarifa, pp.pagado, pp.anulada, pp.prescrito, pp.exencion, pp.acuerdo,
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
        $visible = mb_substr($nombre, 0, min(7, max(3, (int) floor(mb_strlen($nombre) / 3))));
        return $visible . str_repeat('*', max(4, min(20, mb_strlen($nombre) - mb_strlen($visible))));
    }

    public static function enmascararDocumento(string $doc): string
    {
        $doc = trim($doc);
        return mb_substr($doc, 0, 3) . str_repeat('*', max(3, mb_strlen($doc) - 3));
    }
}
