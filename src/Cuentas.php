<?php
/**
 * Cuentas del portal: las mismas de la tabla usuarios_pse que usaba el PSE
 * anterior (Angular + .NET), para que nadie tenga que volver a inscribirse.
 *
 * La contraseña se guarda como el sistema anterior: MD5 sin sal, en
 * hexadecimal minúscula (así están casi todas; se compara sin importar
 * mayúsculas). Es débil, pero cambiar el formato dejaría sin entrar a quien
 * siga usando el sistema anterior mientras conviven; queda pendiente.
 *
 * Los intentos fallidos se cuentan por documento y por IP (Limites), no en la
 * sesión: abrir otra sesión no reinicia el contador.
 */

declare(strict_types=1);

final class Cuentas
{
    const INTENTOS_MAXIMOS   = 5;   // por documento, en la ventana
    const MINUTOS_BLOQUEO    = 15;
    const INTENTOS_IP_HORA   = 30;  // por IP
    const INSCRIPCIONES_IP   = 5;   // por IP y por hora

    /** Tipos de documento de la tabla tipos_identificacion. */
    const TIPOS = [1 => 'Cédula de ciudadanía', 2 => 'Tarjeta de identidad', 3 => 'Pasaporte', 4 => 'Cédula de extranjería'];

    public static function huella(string $clave): string
    {
        return md5($clave);
    }

    /** Documento tal como se guarda: sin puntos, espacios ni guiones. */
    public static function normalizarDocumento(string $doc): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $doc) ?? '');
    }

    /**
     * Inicia sesión. Devuelve null si entró, o el motivo para mostrar.
     */
    public static function entrar(string $documento, string $clave): ?string
    {
        $doc = self::normalizarDocumento($documento);
        if ($doc === '' || $clave === '') {
            return 'Escribe tu número de documento y tu contraseña.';
        }
        $claveDoc = 'login_doc_' . $doc;
        $claveIp = 'login_ip_' . Limites::ip();
        if (Limites::excedido($claveDoc, self::INTENTOS_MAXIMOS, self::MINUTOS_BLOQUEO * 60)
            || Limites::excedido($claveIp, self::INTENTOS_IP_HORA, 3600)) {
            return 'Demasiados intentos fallidos. Espera ' . self::MINUTOS_BLOQUEO . ' minutos e intenta de nuevo.';
        }

        // El documento se compara limpio en los dos lados: hay cuentas viejas
        // guardadas con puntos o guiones, y alguno repetido.
        $candidatas = Db::filas(
            "SELECT id, documento, id_tipo_identificacion, nombres, apellidos, correo_electronico, telefono, password, estado
               FROM usuarios_pse
              WHERE REPLACE(REPLACE(REPLACE(REPLACE(UPPER(documento), '.', ''), '-', ''), ' ', ''), '|', '') = ?
              ORDER BY id",
            [$doc]
        );
        $u = null;
        foreach ($candidatas as $c) {
            if (strtoupper(trim((string) $c['estado'])) === 'A'
                && hash_equals(strtolower(trim((string) $c['password'])), self::huella($clave))) {
                $u = $c;
                break;
            }
        }
        if ($u === null) {
            Limites::anotar($claveDoc);
            Limites::anotar($claveIp);
            return 'No pudimos iniciar sesión con esos datos. Revisa el documento y la contraseña; si el problema sigue, comunícate con la Dirección de Impuestos.';
        }

        session_regenerate_id(true);
        Limites::borrar($claveDoc);
        $_SESSION['usuario'] = [
            'id'        => (int) $u['id'],
            'documento' => $doc,
            'tipo'      => (int) $u['id_tipo_identificacion'],
            'nombres'   => trim((string) $u['nombres']),
            'apellidos' => trim((string) $u['apellidos']),
            'correo'    => trim((string) $u['correo_electronico']),
            'telefono'  => trim((string) $u['telefono']),
        ];
        $_SESSION['predios_ok'] = [];
        return null;
    }

    public static function salir(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /**
     * Inscripción. Devuelve [errores por campo]; vacío si quedó creada.
     */
    public static function inscribir(array $d): array
    {
        $err = [];
        $doc = self::normalizarDocumento((string) ($d['documento'] ?? ''));
        $tipo = (int) ($d['tipo'] ?? 0);
        $nombres = trim(preg_replace('/\s+/', ' ', (string) ($d['nombres'] ?? '')));
        $apellidos = trim(preg_replace('/\s+/', ' ', (string) ($d['apellidos'] ?? '')));
        $nacimiento = (string) ($d['nacimiento'] ?? '');
        $correo = trim((string) ($d['correo'] ?? ''));
        $direccion = trim(preg_replace('/\s+/', ' ', (string) ($d['direccion'] ?? '')));
        $telefono = preg_replace('/\D/', '', (string) ($d['telefono'] ?? '')) ?? '';
        $clave = (string) ($d['clave'] ?? '');
        $clave2 = (string) ($d['clave2'] ?? '');

        if (!isset(self::TIPOS[$tipo])) {
            $err['tipo'] = 'Elige el tipo de documento.';
        }
        if ($doc === '' || strlen($doc) < 4 || strlen($doc) > 13) {
            $err['documento'] = 'El documento debe tener entre 4 y 13 caracteres.';
        } elseif (in_array($tipo, [1, 2], true) && !ctype_digit($doc)) {
            $err['documento'] = 'Este tipo de documento solo lleva números.';
        }
        if ($nombres === '' || mb_strlen($nombres) > 60) {
            $err['nombres'] = 'Escribe tus nombres (hasta 60 caracteres).';
        }
        if ($apellidos === '' || mb_strlen($apellidos) > 60) {
            $err['apellidos'] = 'Escribe tus apellidos (hasta 60 caracteres).';
        }
        $fn = DateTime::createFromFormat('!Y-m-d', $nacimiento);
        if ($fn === false || $fn->format('Y-m-d') !== $nacimiento || $fn > new DateTime('-14 years') || $fn < new DateTime('1900-01-01')) {
            $err['nacimiento'] = 'Escribe una fecha de nacimiento válida (debes tener al menos 14 años).';
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 128) {
            $err['correo'] = 'Escribe un correo válido.';
        }
        if ($direccion === '' || mb_strlen($direccion) > 128) {
            $err['direccion'] = 'Escribe tu dirección (hasta 128 caracteres).';
        }
        if (strlen($telefono) < 7 || strlen($telefono) > 10) {
            $err['telefono'] = 'El teléfono debe tener entre 7 y 10 dígitos.';
        }
        if (strlen($clave) < 8) {
            $err['clave'] = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif ($clave !== $clave2) {
            $err['clave2'] = 'Las dos contraseñas no coinciden.';
        }
        if ($err) {
            return $err;
        }
        if (Limites::excedido('inscripcion_ip_' . Limites::ip(), self::INSCRIPCIONES_IP, 3600)) {
            return ['general' => 'Se crearon varias cuentas desde esta conexión en la última hora. Intenta más tarde.'];
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // Dos inscripciones simultáneas con el mismo documento: entra una.
            Db::candado('psepaipa_inscripcion');
            $existe = Db::valor(
                "SELECT COUNT(*) FROM usuarios_pse WHERE REPLACE(REPLACE(REPLACE(REPLACE(UPPER(documento), '.', ''), '-', ''), ' ', ''), '|', '') = ?",
                [$doc]
            );
            if ((int) $existe > 0) {
                $pdo->rollBack();
                return ['documento' => 'Ya existe una cuenta con este documento. Inicia sesión; si no recuerdas la contraseña, comunícate con la Dirección de Impuestos.'];
            }
            Db::ejecutar(
                "INSERT INTO usuarios_pse (documento, id_tipo_identificacion, nombres, apellidos, fecha_nacimiento,
                        correo_electronico, direccion, telefono, estado, password, id_tipo_usuario, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'A', ?, 1, GETDATE(), GETDATE())",
                [$doc, $tipo, mb_strtoupper($nombres, 'UTF-8'), mb_strtoupper($apellidos, 'UTF-8'), $nacimiento,
                 $correo, $direccion, $telefono, self::huella($clave)]
            );
            $pdo->commit();
            Limites::anotar('inscripcion_ip_' . Limites::ip());
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
        return [];
    }

    /** Sin la I, la O, el 0 ni el 1, que se confunden al leerlos. */
    const LETRAS_TEMPORAL = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const RECUPERACIONES_DOC = 3;    // por documento y por hora
    const RECUPERACIONES_IP  = 10;   // por IP y por hora

    /**
     * Olvidé mi contraseña (igual que en Industria y Comercio, 2026-10-27): la
     * persona escribe su documento y le llega al correo de su cuenta una
     * contraseña temporal, que reemplaza la anterior. Si el correo no sale, la
     * anterior se deja como estaba. Devuelve ['correo' => "ca***@x.com"] o
     * ['error' => texto].
     */
    public static function recuperar(string $documento): array
    {
        $doc = self::normalizarDocumento($documento);
        if ($doc === '') {
            return ['error' => 'Escribe tu número de documento.'];
        }
        $claveDoc = 'recuperar_doc_' . $doc;
        $claveIp = 'recuperar_ip_' . Limites::ip();
        if (Limites::excedido($claveDoc, self::RECUPERACIONES_DOC, 3600) || Limites::excedido($claveIp, self::RECUPERACIONES_IP, 3600)) {
            return ['error' => 'Ya se pidió la contraseña varias veces. Revisa tu correo (también el spam) o intenta de nuevo en una hora.'];
        }
        Limites::anotar($claveDoc);
        Limites::anotar($claveIp);

        $cuentas = Db::filas(
            "SELECT id, nombres, correo_electronico, password
               FROM usuarios_pse
              WHERE REPLACE(REPLACE(REPLACE(REPLACE(UPPER(documento), '.', ''), '-', ''), ' ', ''), '|', '') = ?
                AND UPPER(LTRIM(RTRIM(estado))) = 'A'
              ORDER BY id",
            [$doc]
        );
        if (!$cuentas) {
            return ['error' => 'El documento no se encuentra registrado.'];
        }
        $correo = '';
        $nombre = '';
        foreach ($cuentas as $c) {
            $e = trim((string) $c['correo_electronico']);
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $correo = $e;
                $nombre = trim((string) $c['nombres']);
                break;
            }
        }
        if ($correo === '') {
            return ['error' => 'La cuenta no tiene un correo registrado. Comunícate con la Dirección de Impuestos.'];
        }
        if (!Correo::disponible()) {
            error_log('[psepaipa] recuperar contraseña: no hay correo configurado (ver Correo.php).');
            return ['error' => 'La recuperación automática no está disponible en este momento. Comunícate con la Dirección de Impuestos.'];
        }

        $temporal = '';
        for ($i = 0; $i < 8; $i++) {
            $temporal .= self::LETRAS_TEMPORAL[random_int(0, strlen(self::LETRAS_TEMPORAL) - 1)];
        }
        $ids = array_map(fn($c) => (int) $c['id'], $cuentas);
        $marcas = implode(', ', array_fill(0, count($ids), '?'));
        // La contraseña cambia primero y el correo sale después; si no sale, se devuelve la anterior.
        Db::ejecutar("UPDATE usuarios_pse SET password = ?, updated_at = GETDATE() WHERE id IN ($marcas)", array_merge([self::huella($temporal)], $ids));
        try {
            Correo::enviar(
                $correo,
                $nombre,
                'Recuperación de contraseña - Impuesto Predial',
                "Hola " . ($nombre !== '' ? mb_convert_case(mb_strtolower($nombre), MB_CASE_TITLE) : '') . ",\r\n\r\n"
                . "Tu contraseña temporal es: $temporal\r\n\r\n"
                . "Úsala para iniciar sesión en el portal del Impuesto Predial y cámbiala desde \"Cambiar contraseña\" en el menú de tu cuenta.\r\n"
                . "Si no la pediste tú, ingresa y cámbiala, o comunícate con la Dirección de Impuestos.\r\n\r\n"
                . "Alcaldía de " . config('municipio.ciudad', '')
            );
        } catch (Throwable $t) {
            foreach ($cuentas as $c) {
                Db::ejecutar('UPDATE usuarios_pse SET password = ? WHERE id = ?', [(string) $c['password'], (int) $c['id']]);
            }
            error_log('[psepaipa] recuperar contraseña: no salió el correo: ' . $t->getMessage());
            return ['error' => 'No pudimos enviar el correo. Tu contraseña sigue igual; intenta de nuevo en unos minutos o comunícate con la Dirección de Impuestos.'];
        }
        return ['correo' => Correo::enmascarar($correo)];
    }

    /**
     * Cambiar la contraseña con la sesión abierta: pide la actual y escribe la
     * nueva dos veces. Devuelve [errores por campo]; vacío si quedó cambiada.
     */
    public static function cambiarClave(int $idUsuario, string $actual, string $nueva, string $nueva2): array
    {
        $limite = 'cambiar_clave_' . $idUsuario;
        if (Limites::excedido($limite, self::INTENTOS_MAXIMOS, self::MINUTOS_BLOQUEO * 60)) {
            return ['general' => 'Demasiados intentos fallidos. Espera ' . self::MINUTOS_BLOQUEO . ' minutos e intenta de nuevo.'];
        }
        $err = [];
        if ($actual === '') {
            $err['actual'] = 'Escribe tu contraseña actual.';
        }
        if (strlen($nueva) < 8) {
            $err['nueva'] = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif ($nueva !== $nueva2) {
            $err['nueva2'] = 'Las dos contraseñas no coinciden.';
        }
        if ($err) {
            return $err;
        }
        $fila = Db::fila("SELECT password FROM usuarios_pse WHERE id = ? AND UPPER(LTRIM(RTRIM(estado))) = 'A'", [$idUsuario]);
        if ($fila === null || !hash_equals(strtolower(trim((string) $fila['password'])), self::huella($actual))) {
            Limites::anotar($limite);
            return ['actual' => 'La contraseña actual no es correcta.'];
        }
        Db::ejecutar('UPDATE usuarios_pse SET password = ?, updated_at = GETDATE() WHERE id = ?', [self::huella($nueva), $idUsuario]);
        Limites::borrar($limite);
        return [];
    }

    public static function nombreCompleto(array $u): string
    {
        return trim($u['nombres'] . ' ' . $u['apellidos']);
    }
}
