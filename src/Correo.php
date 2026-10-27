<?php
/**
 * Envío de correo del portal (recuperar la contraseña). Cliente SMTP mínimo:
 * texto en UTF-8, una sola parte, con STARTTLS (587) o SSL directo (465) y
 * AUTH LOGIN. Sin dependencias: no se trajo PHPMailer para un solo correo.
 *
 * De dónde salen los datos del servidor (en este orden):
 *   1. config.local.php, 'correo' => ['host','puerto','usuario','clave',
 *      'remitente','nombre','seguridad' ('tls' | 'ssl' | 'ninguna')].
 *   2. El appsettings.json de la API .NET anterior (FromEmail, FromPass, Host,
 *      Port), por la misma ruta de 'db_appsettings' (o 'correo_appsettings'):
 *      el correo de notificaciones que ya usaba el sistema viejo. La clave no
 *      se copia: se lee en el servidor cuando se necesita.
 */

declare(strict_types=1);

/** El servidor de correo no contestó: no se sabe si el mensaje llegó a salir. */
final class CorreoSinRespuesta extends RuntimeException
{
}

/** Se mandó el mensaje completo y no hubo respuesta: puede haberse entregado. */
final class CorreoIncierto extends RuntimeException
{
}

final class Correo
{
    /** Segundos que puede tardar todo el envío (conexión, cifrado, autenticación y mensaje). */
    const PLAZO = 40;

    /** @var float momento (microtime) en que se acaba el plazo del envío en curso */
    private static $vence = 0.0;

    /** @return array{host:string,puerto:int,usuario:string,clave:string,remitente:string,nombre:string,seguridad:string}|null */
    public static function configuracion(): ?array
    {
        $c = (array) config('correo', []);
        if (empty($c['host'])) {
            $ruta = (string) (config('correo_appsettings') ?: config('db_appsettings'));
            $a = $ruta !== '' ? ConexionNet::correo($ruta) : null;
            if ($a === null) {
                return null;
            }
            $c = $a + $c;
        }
        $puerto = (int) ($c['puerto'] ?? 587);
        $c['seguridad'] = (string) ($c['seguridad'] ?? ($puerto === 465 ? 'ssl' : 'tls'));
        $remitente = trim((string) ($c['remitente'] ?? $c['usuario'] ?? ''));
        if (trim((string) $c['host']) === '' || !filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return [
            'host'      => trim((string) $c['host']),
            'puerto'    => $puerto,
            'usuario'   => (string) ($c['usuario'] ?? ''),
            'clave'     => (string) ($c['clave'] ?? ''),
            'remitente' => $remitente,
            'nombre'    => (string) ($c['nombre'] ?? ('Alcaldía de ' . config('municipio.ciudad', ''))),
            'seguridad' => $c['seguridad'],
        ];
    }

    public static function disponible(): bool
    {
        return self::configuracion() !== null;
    }

    /** Resumen sin claves, para herramientas/verificar.php. */
    public static function resumen(): string
    {
        $c = self::configuracion();
        if ($c === null) {
            return 'sin configurar';
        }
        return $c['host'] . ':' . $c['puerto'] . ' ' . $c['seguridad'] . ', remitente ' . self::enmascarar($c['remitente'])
            . ', ' . ($c['usuario'] !== '' && $c['clave'] !== '' ? 'con usuario y clave' : 'sin autenticación');
    }

    /** "contribuyente@dominio.com" → "co***@dominio.com" (con un local corto deja menos letras a la vista). */
    public static function enmascarar(string $correo): string
    {
        $i = strrpos($correo, '@');
        if ($i === false || $i < 1) {
            return '';
        }
        $local = substr($correo, 0, $i);
        return mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 2))) . '***' . substr($correo, $i);
    }

    /**
     * Abre la conexión, la cifra y se autentica, sin mandar nada. Devuelve null
     * si todo está bien o por qué falla (sin claves). Para herramientas/verificar.php:
     * detecta el certificado que PHP no reconoce o una clave rechazada antes de
     * que lo descubra una persona.
     */
    public static function probar(): ?string
    {
        $c = self::configuracion();
        if ($c === null) {
            return 'sin configurar';
        }
        self::$vence = microtime(true) + self::PLAZO;
        try {
            $f = self::conectar($c);
            @fwrite($f, "QUIT\r\n");
            @fclose($f);
            return null;
        } catch (Throwable $t) {
            return $t->getMessage();
        }
    }

    /**
     * Envía un correo de texto. Lanza RuntimeException si no se pudo entregar al
     * servidor, o CorreoIncierto si el mensaje completo salió y no hubo respuesta
     * (puede haberse entregado: quien llama no debe darlo por no enviado).
     */
    public static function enviar(string $para, string $nombre, string $asunto, string $texto): void
    {
        $c = self::configuracion();
        if ($c === null) {
            throw new RuntimeException('correo sin configurar');
        }
        if (!filter_var($para, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $para . $nombre)) {
            throw new RuntimeException('destinatario no válido');
        }
        self::$vence = microtime(true) + self::PLAZO;
        $f = self::conectar($c);
        try {
            self::decir($f, 'MAIL FROM:<' . $c['remitente'] . '>', [250]);
            self::decir($f, 'RCPT TO:<' . $para . '>', [250, 251]);
            self::decir($f, 'DATA', [354]);
            $cabeceras = [
                'Date: ' . date('r'),
                'From: ' . self::cabecera($c['nombre']) . ' <' . $c['remitente'] . '>',
                'To: ' . self::cabecera($nombre) . ' <' . $para . '>',
                'Subject: ' . self::cabecera($asunto),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $c['host'] . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            $cuerpo = chunk_split(base64_encode($texto), 76, "\r\n");
            try {
                self::decir($f, implode("\r\n", $cabeceras) . "\r\n\r\n" . $cuerpo . "\r\n.", [250]);
            } catch (CorreoSinRespuesta $e) {
                throw new CorreoIncierto('el servidor de correo no confirmó el mensaje');
            }
            @fwrite($f, "QUIT\r\n");
        } finally {
            @fclose($f);
        }
    }

    /** Conexión lista para MAIL FROM: saludo, cifrado y autenticación hechos. @return resource */
    private static function conectar(array $c)
    {
        $destino = ($c['seguridad'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['puerto'];
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $c['host']];
        // En Windows PHP no siempre trae las autoridades de certificación: se toma
        // el archivo que ya usa curl (el de AvalPay) si el de openssl no está.
        $curl = (string) ini_get('curl.cainfo');
        if ((string) ini_get('openssl.cafile') === '' && $curl !== '' && is_file($curl)) {
            $ssl['cafile'] = $curl;
        }
        $f = @stream_socket_client($destino, $codigo, $mensaje, 15, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
        if ($f === false) {
            throw new RuntimeException("sin conexión con el servidor de correo ($mensaje)");
        }
        stream_set_timeout($f, 10);
        try {
            self::esperar($f, [220]);
            $yo = 'predial';
            self::decir($f, "EHLO $yo", [250]);
            if ($c['seguridad'] === 'tls') {
                self::decir($f, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($f, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('no se pudo cifrar la conexión con el servidor de correo (certificado)');
                }
                self::decir($f, "EHLO $yo", [250]);
            }
            if ($c['usuario'] !== '' && $c['clave'] !== '') {
                self::decir($f, 'AUTH LOGIN', [334]);
                self::decir($f, base64_encode($c['usuario']), [334]);
                self::decir($f, base64_encode($c['clave']), [235]);
            }
        } catch (Throwable $t) {
            @fclose($f);
            throw $t;
        }
        return $f;
    }

    /** Texto de una cabecera con tildes: =?UTF-8?B?...?= (sin saltos de línea). */
    private static function cabecera(string $t): string
    {
        $t = trim(preg_replace('/[\r\n]+/', ' ', $t) ?? '');
        return $t === '' ? '' : '=?UTF-8?B?' . base64_encode($t) . '?=';
    }

    private static function decir($f, string $linea, array $esperados): void
    {
        fwrite($f, $linea . "\r\n");
        self::esperar($f, $esperados);
    }

    /** Lee la respuesta (puede venir en varias líneas "250-...") y comprueba su código. */
    private static function esperar($f, array $esperados): void
    {
        $ultima = '';
        while (($l = fgets($f, 1024)) !== false) {
            $ultima = $l;
            if (microtime(true) > self::$vence) {
                throw new CorreoSinRespuesta('se acabó el tiempo para hablar con el servidor de correo');
            }
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        if ($ultima === '') {
            throw new CorreoSinRespuesta('el servidor de correo no contestó');
        }
        $codigo = (int) substr($ultima, 0, 3);
        if (!in_array($codigo, $esperados, true)) {
            throw new RuntimeException('el servidor de correo respondió ' . ($codigo ?: 'algo inesperado'));
        }
    }
}
