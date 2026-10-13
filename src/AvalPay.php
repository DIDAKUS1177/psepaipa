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
 */

declare(strict_types=1);

final class AvalPay
{
    const MINUTOS_SESION = 30;          // la certificación exige entre 10 y 30
    const ESTADOS_EN_TRAMITE = ['COMENZADA', 'PENDING'];

    public static function configurado(): bool
    {
        return (string) config('avalpay.url') !== '' && (string) config('avalpay.login') !== '' && (string) config('avalpay.secret') !== '';
    }

    /** ¿Este usuario ve el pago en línea? (modo prueba: solo los de la lista). */
    public static function disponiblePara(?array $u): bool
    {
        if ($u === null || !self::configurado() || !config('avalpay.activo', false)) {
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
            'login'   => (string) config('avalpay.login'),
            'tranKey' => base64_encode(hash('sha256', $nonce . $seed . (string) config('avalpay.secret'), true)),
            'nonce'   => base64_encode($nonce),
            'seed'    => $seed,
        ];
    }

    private static function post(string $ruta, array $payload): array
    {
        $ch = curl_init(rtrim((string) config('avalpay.url'), '/') . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
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
        $local = hash($algoritmo, ($cuerpo['requestId'] ?? '') . ($cuerpo['status']['status'] ?? '') . ($cuerpo['status']['date'] ?? '') . config('avalpay.secret'));
        return hash_equals($local, $firma);
    }

    /** Lo que importa de una consulta, igual que en ICA. */
    public static function interpretar(array $r): array
    {
        $estado = (string) ($r['status']['status'] ?? 'PENDING');
        $tx = $r['payment'][0] ?? [];
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
        return substr(hash_hmac('sha256', 'retorno|' . $idIntento, (string) config('avalpay.secret')), 0, 32);
    }
}
