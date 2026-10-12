<?php
/**
 * Cuentas del portal: las mismas de la tabla usuarios_pse que usaba el PSE
 * anterior (Angular + .NET), para que nadie tenga que volver a inscribirse.
 *
 * La contraseña se guarda como el sistema anterior: MD5 sin sal, en
 * hexadecimal MAYÚSCULA (así están las 200 de Guateque). Es débil, pero
 * cambiar el formato dejaría sin entrar a quien siga usando el sistema
 * anterior mientras conviven; queda anotado como pendiente.
 */

declare(strict_types=1);

final class Cuentas
{
    const INTENTOS_MAXIMOS = 5;
    const MINUTOS_BLOQUEO  = 5;

    /** Tipos de documento de la tabla tipos_identificacion. */
    const TIPOS = [1 => 'Cédula de ciudadanía', 2 => 'Tarjeta de identidad', 3 => 'Pasaporte', 4 => 'Cédula de extranjería'];

    public static function huella(string $clave): string
    {
        return strtoupper(md5($clave));
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
        $bloqueo = (int) ($_SESSION['bloqueo_hasta'] ?? 0);
        if ($bloqueo > time()) {
            $min = (int) ceil(($bloqueo - time()) / 60);
            return "Demasiados intentos fallidos. Espera $min minuto" . ($min === 1 ? '' : 's') . ' e intenta de nuevo.';
        }

        $doc = self::normalizarDocumento($documento);
        if ($doc === '' || $clave === '') {
            return 'Escribe tu número de documento y tu contraseña.';
        }

        $u = Db::fila(
            'SELECT TOP 1 id, documento, id_tipo_identificacion, nombres, apellidos, correo_electronico, telefono, password, estado
               FROM usuarios_pse WHERE documento = ? ORDER BY id',
            [$doc]
        );

        $ok = $u !== null
            && strtoupper(trim((string) $u['estado'])) === 'A'
            && hash_equals(strtoupper((string) $u['password']), self::huella($clave));

        if (!$ok) {
            $_SESSION['intentos'] = (int) ($_SESSION['intentos'] ?? 0) + 1;
            if ($_SESSION['intentos'] >= self::INTENTOS_MAXIMOS) {
                $_SESSION['bloqueo_hasta'] = time() + self::MINUTOS_BLOQUEO * 60;
                $_SESSION['intentos'] = 0;
            }
            if ($u !== null && strtoupper(trim((string) $u['estado'])) !== 'A') {
                return 'Esta cuenta está inactiva. Comunícate con la Dirección de Impuestos.';
            }
            return 'El documento o la contraseña no coinciden.';
        }

        session_regenerate_id(true);
        unset($_SESSION['intentos'], $_SESSION['bloqueo_hasta']);
        $_SESSION['usuario'] = [
            'id'        => (int) $u['id'],
            'documento' => (string) $u['documento'],
            'tipo'      => (int) $u['id_tipo_identificacion'],
            'nombres'   => trim((string) $u['nombres']),
            'apellidos' => trim((string) $u['apellidos']),
            'correo'    => trim((string) $u['correo_electronico']),
            'telefono'  => trim((string) $u['telefono']),
        ];
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

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // Dos inscripciones simultáneas con el mismo documento: entra una.
            Db::candado('psepaipa_inscripcion');
            $existe = Db::valor('SELECT COUNT(*) FROM usuarios_pse WHERE documento = ?', [$doc]);
            if ((int) $existe > 0) {
                $pdo->rollBack();
                return ['documento' => 'Ya existe una cuenta con este documento. Inicia sesión o recupera tu contraseña.'];
            }
            Db::ejecutar(
                "INSERT INTO usuarios_pse (documento, id_tipo_identificacion, nombres, apellidos, fecha_nacimiento,
                        correo_electronico, direccion, telefono, estado, password, id_tipo_usuario, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'A', ?, 1, GETDATE(), GETDATE())",
                [$doc, $tipo, mb_strtoupper($nombres, 'UTF-8'), mb_strtoupper($apellidos, 'UTF-8'), $nacimiento,
                 $correo, $direccion, $telefono, self::huella($clave)]
            );
            $pdo->commit();
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
        return [];
    }

    public static function nombreCompleto(array $u): string
    {
        return trim($u['nombres'] . ' ' . $u['apellidos']);
    }
}
