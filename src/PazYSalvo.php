<?php
/**
 * Certificado de paz y salvo del predial, como el del sistema oficial
 * (PrediosController::generate_paz_pdf + pazPDF_pai):
 *  - número = AAAA + 5 cifras del consecutivo anios.consecutivo_paz del año en
 *    curso (el mismo que usa la Alcaldía: los números no se repiten);
 *  - código QR registrado en predios_validaciones_qr (la misma tabla del
 *    sistema oficial), válido hasta el 31 de diciembre del año, con 5
 *    validaciones; se verifica en validar.php (o en la URL del config);
 *  - solo si el predio no debe nada: sin vigencias pendientes ni en acuerdo
 *    de pago, con el año en curso liquidado y pagado, y sin pago en curso.
 *
 * Diferencias a propósito: el nombre y el documento del propietario van
 * ocultos (la cuenta no prueba que quien la usa sea el dueño, igual que en el
 * resto del portal), y si el mismo predio pide otro el mismo día se repite el
 * certificado del día en vez de gastar otro número.
 */

declare(strict_types=1);

final class PazYSalvo
{
    const MAX_POR_USUARIO_DIA = 5;

    /** Por qué no se puede expedir, o null si se puede. */
    public static function motivo(int $id): ?string
    {
        $anio = (int) date('Y');
        $pendientes = (int) Db::valor(
            'SELECT COUNT(*) FROM predios_pagos pp
              WHERE pp.id_predio = ? AND ' . Predios::SE_DEBE . ' AND (pp.total_calculo > 0 OR ISNULL(pp.acuerdo, 0) <> 0)',
            [$id]
        );
        if ($pendientes > 0) {
            return 'El predio tiene vigencias pendientes de pago.';
        }
        $actual = Db::fila('SELECT pagado, exencion, total_calculo FROM predios_pagos WHERE id_predio = ? AND ultimo_anio = ? AND ISNULL(anulada, 0) = 0', [$id, $anio]);
        if ($actual === null) {
            return 'La vigencia ' . $anio . ' todavía no está liquidada para este predio.';
        }
        if ((int) $actual['pagado'] === 0 && (int) $actual['exencion'] === 0 && (float) $actual['total_calculo'] > 0) {
            return 'Falta pagar la vigencia ' . $anio . '.';
        }
        if (PagoLinea::enTramite($id) !== null) {
            return 'Hay un pago en línea en curso: espera a que el banco lo confirme.';
        }
        return null;
    }

    /**
     * Expide (o repite el del día) y devuelve los datos para el PDF.
     * Lanza AvisoUsuario con un mensaje para el contribuyente.
     */
    public static function expedir(int $id, int $idUsuario): array
    {
        $motivo = self::motivo($id);
        if ($motivo !== null) {
            throw new AvisoUsuario('No se puede expedir el paz y salvo: ' . $motivo);
        }
        $anio = (int) date('Y');
        $conQr = self::hayTablaQr();

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::candado('psepaipa_paz_salvo');
            // Solo los del portal (nombre oculto, con *): uno de ventanilla del
            // mismo día lleva el destino y el valor que puso el funcionario.
            $previo = $conQr ? Db::fila(
                'SELECT TOP 1 certificado_numero, token FROM predios_validaciones_qr
                  WHERE id_predio = ? AND fecha_expedicion = ? AND estado = 1 AND propietario_principal LIKE ?
                  ORDER BY id DESC',
                [$id, hoy(), '%*%']
            ) : null;
            if ($previo !== null) {
                $pdo->commit();
                return ['numero' => (string) $previo['certificado_numero'], 'token' => (string) $previo['token'], 'validez' => $anio . '-12-31'];
            }
            if (Limites::excedido('paz_usuario_' . $idUsuario, self::MAX_POR_USUARIO_DIA, 86400)) {
                throw new AvisoUsuario('Ya descargaste varios paz y salvo hoy. Intenta mañana o usa el que descargaste.');
            }
            $a = Db::fila('SELECT id, ISNULL(consecutivo_paz, 0) AS c FROM anios WITH (UPDLOCK) WHERE anio = ?', [$anio]);
            if ($a === null) {
                throw new AvisoUsuario('El año ' . $anio . ' no está abierto en el sistema de predial. Comunícate con la Alcaldía.');
            }
            $n = (int) $a['c'] + 1;
            $numero = $anio . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
            Db::ejecutar('UPDATE anios SET consecutivo_paz = ? WHERE id = ?', [$n, (int) $a['id']]);

            $token = '';
            if ($conQr) {
                $token = bin2hex(random_bytes(16)) . hash('sha256', $id . $numero . microtime());
                $principal = Db::valor(
                    'SELECT TOP 1 pr.nombre FROM predios_propietarios prp JOIN propietarios pr ON pr.id = prp.id_propietario
                      WHERE prp.id_predio = ? ORDER BY prp.jerarquia',
                    [$id]
                );
                $ahora = date('Y-m-d\TH:i:s');
                Db::ejecutar(
                    'INSERT INTO predios_validaciones_qr
                        (token, id_predio, certificado_numero, propietario_principal, fecha_expedicion, fecha_validez,
                         is_validated, created_at, updated_at, estado, max_validations, validation_count)
                     VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 1, 5, 0)',
                    [$token, $id, $numero, Predios::enmascararNombre((string) $principal), hoy(), $anio . '-12-31', $ahora, $ahora]
                );
            }
            $pdo->commit();
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
        Limites::anotar('paz_usuario_' . $idUsuario);
        return ['numero' => $numero, 'token' => $token, 'validez' => $anio . '-12-31'];
    }

    /** Último año pagado del predio (para "último pago con factura No."). */
    public static function ultimoPago(int $id): ?array
    {
        return Db::fila(
            'SELECT TOP 1 ultimo_anio, factura_pago, fecha_pago, avaluo FROM predios_pagos
              WHERE id_predio = ? AND pagado <> 0 AND ISNULL(anulada, 0) = 0 ORDER BY ultimo_anio DESC',
            [$id]
        );
    }

    public static function urlValidacion(string $token): string
    {
        $base = (string) config('paz_salvo.url_validacion', '');
        return $base !== '' ? $base . $token : urlPublica() . '/validar.php?t=' . $token;
    }

    private static function hayTablaQr(): bool
    {
        return Db::valor("SELECT OBJECT_ID('predios_validaciones_qr', 'U')") !== null;
    }
}
