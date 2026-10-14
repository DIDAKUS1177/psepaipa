<?php
/**
 * AvalPay (PlacetoPay Web Checkout) para el predial. Misma integración que ya
 * está certificada y en producción en el módulo de ICA de Paipa
 * (business/class.placetopay.php), adaptada a este portal.
 *
 * Configuración en config.local.php (nunca en el repositorio):
 *   'avalpay' => [
 *       'url'     => 'https://checkout.avalpaycenter.com/api',   // con /api
 *       'login'   => '...',
 *       'secret'  => '...',
 *       'activo'  => true,
 *       'usuarios_prueba' => ['123', ...],   // vacío = todos ven el botón
 *   ]
 * Sin url, login y secret el botón de pago en línea no aparece.
 *
 * MISMA CUENTA QUE ICA (pedido del cliente, 2026-10-13): con
 *   'avalpay_ica' => ruta del config.municipio.php del módulo de ICA
 * las credenciales se leen del convenio de ICA en el servidor (tabla
 * conf_parametros de su base: PASARELA_BASEURL/LOGIN/SECRETKEY, o sus
 * constantes PLACETOPAY_*), igual que la conexión sale del appsettings: nadie
 * copia la clave. Lo que se ponga en 'avalpay' tiene prioridad.
 */

declare(strict_types=1);

final class AvalPay
{
    const MINUTOS_SESION = 30;          // la certificación exige entre 10 y 30
    const ESTADOS_EN_TRAMITE = ['COMENZADA', 'PENDING'];

    private static ?array $cred = null;

    /** ['url', 'login', 'secret'] del config o, si faltan, del convenio de ICA. */
    private static function cred(): array
    {
        if (self::$cred !== null) {
            return self::$cred;
        }
        $c = ['url' => (string) config('avalpay.url', ''), 'login' => (string) config('avalpay.login', ''), 'secret' => (string) config('avalpay.secret', '')];
        $ruta = (string) config('avalpay_ica', '');
        if (($c['url'] === '' || $c['login'] === '' || $c['secret'] === '') && $ruta !== '') {
            try {
                $ica = self::deIca($ruta);
                foreach ($c as $k => $v) {
                    if ($v === '') {
                        $c[$k] = $ica[$k] ?? '';
                    }
                }
            } catch (Throwable $t) {
                error_log('[psepaipa] no se pudo leer el convenio AvalPay de ICA: ' . $t->getMessage());
            }
        }
        return self::$cred = $c;
    }

    /** Constantes define('X', '...') de un archivo PHP, leídas SIN ejecutarlo. */
    public static function constantes(string $ruta): array
    {
        if (!is_file($ruta) || !is_readable($ruta)) {
            throw new RuntimeException('no existe o no se puede leer el config de ICA');
        }
        $patron = <<<'RE'
/define\(\s*(['"])([A-Z0-9_]+)\1\s*,\s*(['"])((?:\\.|(?!\3).)*)\3\s*\)/
RE;
        // Sin comentarios: un ejemplo "// define(...)" o "/* define(...) */" no cuenta.
        $texto = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($ruta)) ?? '';
        $texto = preg_replace('~^\s*(//|#).*$~m', '', $texto) ?? '';
        preg_match_all($patron, $texto, $m, PREG_SET_ORDER);
        $k = [];
        foreach ($m as $d) {
            // Como lo leería PHP: entre comillas simples solo \\ y \' son escapes
            // ('.\MSSQLSERVER2022' conserva la barra); entre dobles, los comunes.
            $k[$d[2]] ??= $d[3] === "'"
                ? strtr($d[4], ['\\\\' => '\\', "\\'" => "'"])
                : strtr($d[4], ['\\\\' => '\\', '\\"' => '"', '\\$' => '$', '\\n' => "\n", '\\t' => "\t"]);
        }
        return $k;
    }

    /**
     * Lee el convenio de ICA SIN ejecutar su config: sus constantes (base de
     * datos y PLACETOPAY_*) y, en su base, conf_parametros (la tabla manda; las
     * constantes son el respaldo, como en ICA). Si ICA usa Wompi, no sirve aquí.
     */
    public static function deIca(string $ruta): array
    {
        $k = self::constantes($ruta);
        $p = [];
        if (!empty($k['DB_PROD_SERVER']) && !empty($k['DB_PROD_NAME'])) {
            $pdo = new PDO('sqlsrv:Server=' . $k['DB_PROD_SERVER'] . ';Database=' . $k['DB_PROD_NAME'] . ';TrustServerCertificate=1;LoginTimeout=5',
                (string) ($k['DB_PROD_USER'] ?? ''), (string) ($k['DB_PROD_PASS'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8, PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 10]);
            $st = $pdo->query("SELECT par_Clave, par_Valor FROM conf_parametros WHERE ISNULL(par_Estado, 1) = 1
                                 AND par_Clave IN ('PASARELA_PROVEEDOR', 'PASARELA_BASEURL', 'PASARELA_LOGIN', 'PASARELA_SECRETKEY')");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $p[$f['par_Clave']] = trim((string) $f['par_Valor']);
            }
        }
        if (strtoupper($p['PASARELA_PROVEEDOR'] ?? '') === 'WOMPI') {
            throw new RuntimeException('ICA usa Wompi, no AvalPay');
        }
        $v = fn(string $param, string $constante) => ($p[$param] ?? '') !== '' ? $p[$param] : trim((string) ($k[$constante] ?? ''));
        return ['url' => $v('PASARELA_BASEURL', 'PLACETOPAY_BASEURL'), 'login' => $v('PASARELA_LOGIN', 'PLACETOPAY_LOGIN'),
                'secret' => $v('PASARELA_SECRETKEY', 'PLACETOPAY_SECRETKEY')];
    }

    public static function configurado(): bool
    {
        $c = self::cred();
        return $c['url'] !== '' && $c['login'] !== '' && $c['secret'] !== '';
    }

    /**
     * ¿Se puede mostrar el botón? Sin abrir la base de ICA en cada vista de
     * predio: con el convenio de ICA basta con que su config exista; si las
     * credenciales no se pueden leer, pagar.php lo dice al intentar pagar.
     */
    private static function configuradoBarato(): bool
    {
        $ruta = (string) config('avalpay_ica', '');
        if ($ruta !== '' && ((string) config('avalpay.url', '') === '' || (string) config('avalpay.login', '') === '' || (string) config('avalpay.secret', '') === '')) {
            return is_file($ruta);
        }
        return self::configurado();
    }

    /** ¿Las credenciales son del ambiente de pruebas de AvalPay? */
    public static function esPruebas(): bool
    {
        return stripos(self::cred()['url'], '.test.') !== false;
    }

    /** Si se usa el convenio de ICA, en qué ambiente (para la verificación). */
    public static function ambiente(): string
    {
        $u = self::cred()['url'];
        return $u === '' ? 'sin configurar' : (self::esPruebas() ? 'PRUEBAS (' . parse_url($u, PHP_URL_HOST) . ')' : 'PRODUCCIÓN (' . parse_url($u, PHP_URL_HOST) . ')');
    }

    /** ¿El enlace de pago es del propio AvalPay? (https, host conocido o el de la API). */
    public static function urlDePagoValida(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $permitidos = array_map('strtolower', array_merge(
            ['checkout.avalpaycenter.com', 'checkout.test.avalpaycenter.com'],
            (array) config('avalpay.hosts_permitidos', []),
            [(string) parse_url(self::cred()['url'], PHP_URL_HOST)]
        ));
        $https = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
        return $host !== '' && in_array($host, $permitidos, true) && ($https || in_array($host, (array) config('avalpay.hosts_permitidos', []), true));
    }

    /** ¿Este usuario ve el pago en línea? (modo prueba: solo los de la lista). */
    public static function disponiblePara(?array $u): bool
    {
        if ($u === null || !config('avalpay.activo', false) || !self::configuradoBarato()) {
            return false;
        }
        $prueba = array_map('strval', (array) config('avalpay.usuarios_prueba', []));
        return !$prueba || in_array((string) $u['documento'], $prueba, true);
    }

    private static function auth(): array
    {
        $seed = date('c');
        $nonce = random_bytes(16);
        return [
            'login'   => self::cred()['login'],
            'tranKey' => base64_encode(hash('sha256', $nonce . $seed . self::cred()['secret'], true)),
            'nonce'   => base64_encode($nonce),
            'seed'    => $seed,
        ];
    }

    private static function post(string $ruta, array $payload): array
    {
        $ch = curl_init(rtrim(self::cred()['url'], '/') . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 25,
        ]);
        $r = curl_exec($ch);
        if ($r === false) {
            $e = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Sin conexión con AvalPay: ' . $e);
        }
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string) $r, true);
        if (!is_array($data)) {
            throw new RuntimeException('Respuesta no válida de AvalPay (HTTP ' . $codigo . ')');
        }
        return $data;
    }

    /** Crea la sesión de pago. Devuelve [requestId, processUrl]. */
    public static function crearSesion(string $referencia, int $valor, string $descripcion, string $retorno, array $comprador, array $campos): array
    {
        $payload = [
            'auth'    => self::auth(),
            'payment' => [
                'reference'   => $referencia,
                'description' => $descripcion,
                'amount'      => ['currency' => 'COP', 'total' => $valor],
                'fields'      => $campos,
            ],
            'expiration'    => date('c', strtotime('+' . self::MINUTOS_SESION . ' minutes')),
            'returnUrl'     => $retorno,
            'ipAddress'     => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'userAgent'     => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'psepaipa'), 0, 255),
            'paymentMethod' => 'pse',
            'locale'        => 'es_CO',
        ];
        if ($comprador) {
            $payload['buyer'] = $comprador;
        }
        $d = self::post('/session', $payload);
        if (($d['status']['status'] ?? '') !== 'OK' || empty($d['requestId']) || empty($d['processUrl'])) {
            throw new RuntimeException('AvalPay rechazó la sesión: ' . ($d['status']['message'] ?? 'sin detalle'));
        }
        return [(string) $d['requestId'], (string) $d['processUrl']];
    }

    /** Estado real de una sesión. Lanza si la respuesta no trae ESA sesión. */
    public static function consultar(string $requestId): array
    {
        $d = self::post('/session/' . (int) $requestId, ['auth' => self::auth()]);
        if (!isset($d['requestId']) || (string) $d['requestId'] !== (string) (int) $requestId) {
            throw new RuntimeException('AvalPay no devolvió la sesión ' . (int) $requestId . ': ' . ($d['status']['message'] ?? 'sin detalle'));
        }
        return $d;
    }

    /** Firma del aviso: hash(requestId + status + date + secret), SHA-1 o "sha256:". */
    public static function firmaValida(array $cuerpo): bool
    {
        $firma = (string) ($cuerpo['signature'] ?? '');
        if ($firma === '' || !self::configurado()) {
            return false;
        }
        $algoritmo = 'sha1';
        if (strpos($firma, 'sha256:') === 0) {
            $algoritmo = 'sha256';
            $firma = substr($firma, 7);
        }
        $local = hash($algoritmo, ($cuerpo['requestId'] ?? '') . ($cuerpo['status']['status'] ?? '') . ($cuerpo['status']['date'] ?? '') . self::cred()['secret']);
        return hash_equals($local, $firma);
    }

    /** Lo que importa de una consulta, igual que en ICA. */
    public static function interpretar(array $r): array
    {
        $estado = (string) ($r['status']['status'] ?? 'PENDING');
        // La transacción aprobada si la hay (payment[0] puede ser un intento
        // rechazado anterior en la misma sesión, con otro banco y sin CUS).
        $tx = $r['payment'][0] ?? [];
        foreach ((array) ($r['payment'] ?? []) as $p) {
            if (($p['status']['status'] ?? '') === 'APPROVED') {
                $tx = $p;
                break;
            }
        }
        $valor = $tx['amount']['to']['total'] ?? $tx['amount']['from']['total'] ?? $r['request']['payment']['amount']['total'] ?? null;
        return [
            'estado'       => $estado,
            'aprobado'     => $estado === 'APPROVED',
            'sinIntento'   => $estado === 'PENDING' && empty($r['payment']),
            'banco'        => (string) ($tx['issuerName'] ?? $tx['franchise'] ?? 'PSE'),
            'autorizacion' => (string) ($tx['authorization'] ?? $tx['receipt'] ?? ''),
            'fecha'        => (string) ($r['status']['date'] ?? date('c')),
            'valor'        => is_numeric($valor) ? (int) round((float) $valor) : null,
            'mensaje'      => mb_substr((string) ($tx['status']['message'] ?? $r['status']['message'] ?? ''), 0, 100),
        ];
    }

    /** Firma del enlace de retorno, para que nadie consulte intentos ajenos. */
    public static function firmaRetorno(int $idIntento): string
    {
        return substr(hash_hmac('sha256', 'retorno|' . $idIntento, self::cred()['secret']), 0, 32);
    }
}
