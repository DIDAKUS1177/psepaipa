<?php
/**
 * Certificado de paz y salvo del predial, como el del sistema oficial
 * (PrediosController::generate_paz_pdf + pazPDF_pai):
 *  - número = AAAA + 5 cifras del consecutivo anios.consecutivo_paz del año en
 *    curso (el mismo que usa la Alcaldía; guarda el último usado), saltando
 *    los que ya existan en predios_validaciones_qr;
 *  - código QR registrado en predios_validaciones_qr (la misma tabla del
 *    sistema oficial), válido hasta el 31 de diciembre del año, con 5
 *    validaciones; se verifica en validar.php (o en la URL del config);
 *  - solo si el predio no debe nada según las MARCAS oficiales (no según el
 *    valor guardado, que puede estar en 0 sin calcular): sin vigencias
 *    pendientes, sin acuerdo de pago activo con cuotas por pagar, con el año
 *    en curso liquidado y pagado (o exento) y sin pago en línea en curso.
 *
 * Diferencias a propósito: el nombre del propietario va oculto (la cuenta no
 * prueba que quien la usa sea el dueño; el cliente lo aceptó el 2026-10-13), y
 * si el MISMO usuario pide otro del mismo predio el mismo día se le repite el
 * suyo en vez de gastar otro número. Topes por usuario, por IP y por hora:
 * cada certificado nuevo gasta un número del consecutivo oficial.
 */

declare(strict_types=1);

final class PazYSalvo
{
    const MAX_POR_USUARIO_DIA = 5;
    const MAX_POR_IP_DIA      = 10;
    const MAX_GLOBAL_HORA     = 60;

    /** Por qué no se puede expedir, o null si se puede. */
    public static function motivo(int $id): ?string
    {
        $anio = (int) date('Y');
        $pendientes = (int) Db::valor(
            'SELECT COUNT(*) FROM predios_pagos pp
              WHERE pp.id_predio = ? AND ' . Predios::SE_DEBE . ' AND ISNULL(pp.exencion, 0) = 0',
            [$id]
        );
        if ($pendientes > 0) {
            return 'El predio tiene vigencias pendientes de pago.';
        }
        $acuerdo = (int) Db::valor(
            'SELECT COUNT(*) FROM predios_acuerdos_pago ap
              WHERE ap.id_predio = ? AND ap.estado_acuerdo = 1
                AND EXISTS (SELECT 1 FROM predios_acuerdos_pago_detalle d WHERE d.id_acuerdo = ap.id AND d.pagado = 0 AND ISNULL(d.estado, 1) = 1)',
            [$id]
        );
        if ($acuerdo > 0) {
            return 'El predio tiene un acuerdo de pago con cuotas pendientes.';
        }
        $actual = Db::fila('SELECT pagado, exencion FROM predios_pagos WHERE id_predio = ? AND ultimo_anio = ? AND ISNULL(anulada, 0) = 0', [$id, $anio]);
        if ($actual === null) {
            return 'La vigencia ' . $anio . ' todavía no está liquidada para este predio.';
        }
        if ((int) $actual['pagado'] === 0 && (int) $actual['exencion'] === 0) {
            return 'Falta pagar la vigencia ' . $anio . '.';
        }
        if (PagoLinea::enTramite($id) !== null) {
            return 'Hay un pago en línea en curso: espera a que el banco lo confirme.';
        }
        return null;
    }

    /**
     * Expide (o repite el del día del mismo usuario) y devuelve los datos para
     * el PDF. Lanza AvisoUsuario con un mensaje para el contribuyente.
     */
    public static function expedir(int $id, int $idUsuario): array
    {
        $motivo = self::motivo($id);
        if ($motivo !== null) {
            throw new AvisoUsuario('No se puede expedir el paz y salvo: ' . $motivo);
        }
        if (!self::hayTablaQr()) {
            // Sin el QR no se puede verificar: no se gasta un número oficial.
            throw new AvisoUsuario('El paz y salvo en línea no está disponible. Solicítalo en la Dirección de Impuestos.');
        }
        $anio = (int) date('Y');

        // El mismo usuario, el mismo predio, el mismo día: el mismo certificado.
        $previo = $_SESSION['paz'][$id] ?? null;
        if (is_array($previo) && ($previo['fecha'] ?? '') === hoy() && ($previo['usuario'] ?? 0) === $idUsuario
            && Db::valor('SELECT COUNT(*) FROM predios_validaciones_qr WHERE token = ? AND estado = 1', [(string) $previo['token']]) > 0) {
            return ['numero' => (string) $previo['numero'], 'token' => (string) $previo['token'], 'validez' => $anio . '-12-31'];
        }
        if (Limites::excedido('paz_usuario_' . $idUsuario, self::MAX_POR_USUARIO_DIA, 86400)
            || Limites::excedido('paz_ip_' . Limites::ip(), self::MAX_POR_IP_DIA, 86400)) {
            throw new AvisoUsuario('Ya descargaste varios paz y salvo hoy. Intenta mañana o usa el que descargaste.');
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::candado('psepaipa_paz_salvo');
            $hora = (int) Db::valor(
                "SELECT COUNT(*) FROM predios_validaciones_qr WHERE created_at >= ? AND propietario_principal LIKE '%*%'",
                [date('Y-m-d\TH:i:s', time() - 3600)]
            );
            if ($hora >= (int) config('paz_salvo.max_hora', self::MAX_GLOBAL_HORA)) {
                error_log('[psepaipa] Freno global del paz y salvo: ' . $hora . ' en la última hora.');
                throw new AvisoUsuario('En este momento hay muchas solicitudes. Intenta de nuevo en unos minutos.');
            }
            $a = Db::fila('SELECT id, ISNULL(consecutivo_paz, 0) AS c FROM anios WITH (UPDLOCK) WHERE anio = ?', [$anio]);
            if ($a === null) {
                throw new AvisoUsuario('El año ' . $anio . ' no está abierto en el sistema de predial. Comunícate con la Alcaldía.');
            }
            // El sistema oficial toma el número sin este candado: se salta
            // cualquiera que ya exista.
            $n = (int) $a['c'];
            for ($intentos = 0; ; $intentos++) {
                $n++;
                $numero = $anio . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
                if ($n > 99999 || $intentos > 500) {
                    throw new AvisoUsuario('No hay números de paz y salvo disponibles. Comunícate con la Alcaldía.');
                }
                if ((int) Db::valor('SELECT COUNT(*) FROM predios_validaciones_qr WHERE certificado_numero = ?', [$numero]) === 0) {
                    break;
                }
            }
            Db::ejecutar('UPDATE anios SET consecutivo_paz = ? WHERE id = ?', [$n, (int) $a['id']]);

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
                [$token, $id, $numero, Predios::enmascararNombre((string) $principal) ?: '*', hoy(), $anio . '-12-31', $ahora, $ahora]
            );
            $pdo->commit();
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
        Limites::anotar('paz_usuario_' . $idUsuario);
        Limites::anotar('paz_ip_' . Limites::ip());
        $_SESSION['paz'][$id] = ['fecha' => hoy(), 'usuario' => $idUsuario, 'numero' => $numero, 'token' => $token];
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

    public static function hayTablaQr(): bool
    {
        return Db::valor("SELECT OBJECT_ID('predios_validaciones_qr', 'U')") !== null;
    }
}
