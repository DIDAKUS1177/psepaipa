<?php
/**
 * Wompi (Bancolombia), tercer botón de pago del predial: Web Checkout por
 * redirección. Misma integración del módulo de ICA (business/class.wompi.php y
 * class.pagoWompi.php), adaptada a este portal.
 *
 * CÓMO SE COBRA. Aquí no se escribe nada de la tarjeta ni del banco: el
 * contribuyente va a https://checkout.wompi.co/p/ con la referencia del
 * intento, el valor en centavos y la FIRMA DE INTEGRIDAD
 *     SHA-256(referencia + centavos + "COP" + vencimiento + llave de integridad)
 * (el vencimiento entra en la firma porque se manda expiration-time: el enlace
 * vale 30 minutos, como la sesión de AvalPay). La llave nunca sale del servidor.
 *
 * CÓMO SE CONFIRMA. Wompi devuelve al contribuyente a retorno.php y agrega
 * "id=<transacción>". Ese id NO se cree: podría ser de otro comercio de Wompi
 * con la misma referencia y el mismo valor. Lo que se aplica sale SIEMPRE de
 * la búsqueda por referencia con la llave privada
 *     GET {api}/transactions?reference=REF   (Authorization: Bearer prv_...)
 * que solo trae transacciones de ESTE comercio. La hacen el retorno y la tarea
 * herramientas/verificar-pagos.php. No hay URL de eventos en este portal.
 *
 * La referencia es única por intento (Wompi no deja reutilizarla):
 * "PRED<factura>-<8 hex>". Se guarda en generacion_pagos.IDTransaccion; el id
 * de la transacción de Wompi queda en CUS cuando se conoce.
 *
 * Configuración en config.local.php (nunca en el repositorio):
 *   'wompi' => [
 *       'public_key'    => 'pub_prod_...',
 *       'private_key'   => 'prv_prod_...',
 *       'integrity_key' => 'prod_integrity_...',
 *       'activo'        => true,
 *       'usuarios_prueba' => [],   // documentos; vacío = todos ven el botón
 *       'proveedor'     => 7,      // generacion_pagos.proveedor
 *   ]
 * y lo que falte, del portal anterior del municipio:
 *   'wompi_pse_anterior' => ruta de su assets/config.json
 * Sin ninguna de las dos claves este archivo no hace nada: ni botón, ni
 * proveedor, ni consultas.
 */

declare(strict_types=1);

final class Wompi
{
    const MEDIO          = 'WOMPI';
    const URL_CHECKOUT   = 'https://checkout.wompi.co/p/';
    const API_PRODUCCION = 'https://production.wompi.co/v1';
    const API_PRUEBAS    = 'https://sandbox.wompi.co/v1';
    const MONEDA         = 'COP';
    const MINUTOS_ENLACE = 30;
    const PREFIJO        = 'PRED';

    /** Forma de cada llave: [config, expresión]. El ambiente es test o prod. */
    const LLAVES = [
        'publica'    => ['public_key',    '/^pub_(test|prod)_[A-Za-z0-9]{8,120}$/'],
        'privada'    => ['private_key',   '/^prv_(test|prod)_[A-Za-z0-9]{8,120}$/'],
        'integridad' => ['integrity_key', '/^(test|prod)_integrity_[A-Za-z0-9]{8,120}$/'],
    ];

    private static ?array $cred = null;
    private static string $notaAnterior = '';

    private static function c(string $clave, $defecto = '')
    {
        return config('wompi.' . $clave, $defecto);
    }

    /** ¿El municipio tiene algo de Wompi en su configuración? Sin esto, nada cambia. */
    public static function previsto(): bool
    {
        return (is_array(config('wompi')) && config('wompi')) || (string) config('wompi_pse_anterior', '') !== '';
    }

    public static function proveedor(): int
    {
        return (int) self::c('proveedor', 7);
    }

    /**
     * Las tres llaves y de dónde salió cada una ('config', 'anterior' o '').
     * Primero config.local.php; lo que falte, del portal anterior.
     */
    private static function cred(): array
    {
        if (self::$cred !== null) {
            return self::$cred;
        }
        $c = ['publica' => '', 'privada' => '', 'integridad' => '', 'fuente' => ['publica' => '', 'privada' => '', 'integridad' => '']];
        foreach (self::LLAVES as $k => [$clave]) {
            $c[$k] = trim((string) self::c($clave));
            $c['fuente'][$k] = $c[$k] !== '' ? 'config' : '';
        }
        $viejo = (string) config('wompi_pse_anterior', '');
        if ($viejo !== '' && ($c['publica'] === '' || $c['privada'] === '' || $c['integridad'] === '')) {
            try {
                $v = self::dePseAnterior($viejo);
                foreach (array_keys(self::LLAVES) as $k) {
                    if ($c[$k] === '' && $v[$k] !== '') {
                        $c[$k] = $v[$k];
                        $c['fuente'][$k] = 'anterior';
                    }
                }
            } catch (Throwable $t) {
                self::$notaAnterior = $t->getMessage();
                error_log('[psepaipa] no se pudieron leer las llaves de Wompi del PSE anterior: ' . $t->getMessage());
            }
        }
        return self::$cred = $c;
    }

    /**
     * Llaves de Wompi del portal anterior (Angular): su assets/config.json trae
     * configuraciones[0].Plataformas[] y la de Wompi se llama "WOMPI". Solo si
     * estaba activa. La pública es configuracion.publickey (o Llavepublica), la
     * privada Llaveprivada y la de integridad integritykey; la que falte vuelve
     * vacía (el botón no sale hasta completarla en 'wompi').
     */
    public static function dePseAnterior(string $ruta): array
    {
        if (!is_file($ruta) || !is_readable($ruta)) {
            throw new RuntimeException('no existe o no se puede leer el config.json del portal anterior');
        }
        $texto = (string) file_get_contents($ruta);
        $j = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $texto) ?? '', true);
        if (!is_array($j)) {
            throw new RuntimeException('el config.json del portal anterior no es JSON válido');
        }
        foreach ((array) ($j['configuraciones'][0]['Plataformas'] ?? []) as $p) {
            if (is_array($p) && stripos((string) ($p['Nombre'] ?? ''), 'wompi') !== false) {
                if (empty($p['Activo'])) {
                    throw new RuntimeException('en el portal anterior Wompi no estaba activo');
                }
                $k = array_change_key_case((array) ($p['configuracion'] ?? []), CASE_LOWER);
                $dato = fn(string ...$nombres) => trim((string) current(array_filter(
                    array_map(fn($n) => is_scalar($k[$n] ?? null) ? trim((string) $k[$n]) : '', $nombres),
                    fn($v) => $v !== ''
                )));
                return ['publica' => $dato('publickey', 'llavepublica'), 'privada' => $dato('llaveprivada'), 'integridad' => $dato('integritykey')];
            }
        }
        throw new RuntimeException('el portal anterior no tiene la plataforma Wompi');
    }

    /** 'test', 'prod' o '' (sin llaves). Manda la privada, que es la que viaja al API. */
    public static function entorno(): string
    {
        $c = self::cred();
        foreach (['privada' => 'prv_', 'publica' => 'pub_'] as $k => $inicio) {
            if ($c[$k] !== '') {
                return strpos($c[$k], $inicio . 'prod_') === 0 ? 'prod' : 'test';
            }
        }
        return '';
    }

    /**
     * ¿Están las tres llaves, bien formadas y del MISMO ambiente? Mezclar una
     * de pruebas con una de producción no da error en ninguna parte: Wompi
     * rechaza la firma o no encuentra los pagos. Mejor no ofrecer el botón.
     */
    public static function configurado(): bool
    {
        return self::previsto() && self::problema() === null;
    }

    /** Qué le falta a la configuración (nombres, nunca valores), o null si está completa. */
    public static function problema(): ?string
    {
        $c = self::cred();
        $amb = self::entorno();
        $nombres = ['publica' => 'la llave pública', 'privada' => 'la llave privada', 'integridad' => 'la llave de integridad'];
        $faltan = array_values(array_intersect_key($nombres, array_filter(['publica' => $c['publica'] === '', 'privada' => $c['privada'] === '', 'integridad' => $c['integridad'] === ''])));
        if ($faltan) {
            return 'falta ' . implode(' y ', $faltan) . (self::$notaAnterior !== '' ? ' (' . self::$notaAnterior . ')' : '');
        }
        foreach (self::LLAVES as $k => [, $forma]) {
            if (!preg_match($forma, $c[$k], $m)) {
                return $nombres[$k] . ' no tiene la forma de una llave de Wompi';
            }
            if ($m[1] !== $amb) {
                return 'las llaves mezclan pruebas y producción';
            }
        }
        return null;
    }

    /** ¿Las llaves son del ambiente de pruebas (sandbox)? Un aprobado ahí no es plata real. */
    public static function esPruebas(): bool
    {
        return self::entorno() !== 'prod';
    }

    public static function ambiente(): string
    {
        return ['prod' => 'PRODUCCIÓN', 'test' => 'PRUEBAS (sandbox)'][self::entorno()] ?? 'sin configurar';
    }

    /** De dónde salieron las llaves (para la verificación; nunca los valores). */
    public static function fuente(): string
    {
        $f = array_unique(array_filter(self::cred()['fuente']));
        sort($f);
        return ['config' => 'escritas en config.local.php', 'anterior' => 'portal anterior (su config.json)',
                'anterior|config' => 'config.local.php y portal anterior'][implode('|', $f)] ?? 'sin fuente';
    }

    /**
     * Dirección del API según el ambiente de las llaves. 'wompi.api' (solo para
     * el simulador local) la reemplaza únicamente con llaves de pruebas: la
     * llave privada real solo viaja a production.wompi.co.
     */
    public static function api(): string
    {
        if (self::entorno() === 'prod') {
            return self::API_PRODUCCION;
        }
        $propia = rtrim(trim((string) self::c('api')), '/');
        return $propia !== '' ? $propia : self::API_PRUEBAS;
    }

    /**
     * ¿Este usuario ve el botón? Activo, completo y, en modo prueba, solo los
     * de la lista. Con llaves de pruebas y la lista vacía no lo ve nadie.
     */
    public static function disponiblePara(?array $u): bool
    {
        if ($u === null || !self::c('activo', false) || !self::configurado()) {
            return false;
        }
        $prueba = array_map('strval', (array) self::c('usuarios_prueba', []));
        if (!$prueba) {
            return !self::esPruebas();
        }
        return in_array((string) $u['documento'], $prueba, true);
    }

    public static function rotulo(): string
    {
        $r = trim((string) self::c('rotulo'));
        return $r !== '' ? $r : 'Wompi · Tarjeta, Nequi y más';
    }

    /** Secreto para firmar los enlaces de retorno cuando no hay otra pasarela. */
    public static function secreto(): string
    {
        return self::cred()['integridad'];
    }

    /** Referencia nueva para un intento de pago de la factura. */
    public static function referencia(string $factura): string
    {
        return self::PREFIJO . preg_replace('/[^A-Za-z0-9]/', '', $factura) . '-' . bin2hex(random_bytes(4));
    }

    /** ¿La referencia es de un intento de ESA factura? */
    public static function referenciaDeFactura(string $referencia, string $factura): bool
    {
        return (bool) preg_match('/^' . self::PREFIJO . preg_quote(preg_replace('/[^A-Za-z0-9]/', '', $factura), '/') . '-[0-9a-f]{8}$/', $referencia)
            && trim($factura) !== '';
    }

    /** SHA-256(referencia + centavos + moneda [+ vencimiento] + llave de integridad). */
    public static function firmaIntegridad(string $referencia, int $centavos, ?string $vencimiento = null, ?string $llave = null): string
    {
        return hash('sha256', $referencia . $centavos . self::MONEDA . ($vencimiento ?? '') . ($llave ?? self::cred()['integridad']));
    }

    /** Vencimiento del enlace en el formato de Wompi (ISO 8601 en UTC). */
    public static function vencimiento(?int $desde = null): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', ($desde ?? time()) + self::MINUTOS_ENLACE * 60);
    }

    /** La página de pago de Wompi para un intento. $valor en pesos enteros. */
    public static function urlCheckout(string $referencia, int $valor, string $retorno, ?string $vencimiento = null): string
    {
        if ($valor <= 0) {
            throw new RuntimeException('valor no válido para Wompi');
        }
        $centavos = $valor * 100;
        $vencimiento ??= self::vencimiento();
        return self::URL_CHECKOUT . '?' . http_build_query([
            'public-key'          => self::cred()['publica'],
            'currency'            => self::MONEDA,
            'amount-in-cents'     => (string) $centavos,
            'reference'           => $referencia,
            'signature:integrity' => self::firmaIntegridad($referencia, $centavos, $vencimiento),
            'redirect-url'        => $retorno,
            'expiration-time'     => $vencimiento,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** ¿El enlace de pago es de Wompi? (https y su dominio, o uno permitido para pruebas). */
    public static function urlDePagoValida(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $https = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
        if ($host === '') {
            return false;
        }
        if (in_array($host, array_map('strtolower', (array) self::c('hosts_permitidos', [])), true)) {
            return true;
        }
        return $https && $host === 'checkout.wompi.co';
    }

    /** ¿Tiene la forma de un id de transacción de Wompi? (lo que llega en la URL del retorno) */
    public static function idValido(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9-]{1,50}$/', $id);
    }

    /** GET al API con la llave privada. Ni la llave ni la respuesta van al mensaje de error. */
    private static function get(string $ruta): array
    {
        if (!self::configurado()) {
            throw new RuntimeException('Wompi no está configurado');
        }
        $ch = curl_init(self::api() . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . self::cred()['privada']],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $r = curl_exec($ch);
        if ($r === false) {
            $e = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Sin conexión con Wompi: ' . $e);
        }
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $d = json_decode((string) $r, true);
        if ($codigo < 200 || $codigo >= 300 || !is_array($d)) {
            throw new RuntimeException('Wompi respondió HTTP ' . $codigo . ' a la consulta');
        }
        return $d;
    }

    /**
     * Las transacciones de ESTE comercio con esa referencia (puede haber varias:
     * en la página de Wompi se puede reintentar). Lanza si Wompi no contesta la
     * búsqueda: un 404 o un 401 es una llave equivocada, NO "no hay pagos".
     */
    public static function transacciones(string $referencia): array
    {
        $d = self::get('/transactions?reference=' . rawurlencode($referencia));
        if (!array_key_exists('data', $d) || !is_array($d['data'])) {
            throw new RuntimeException('Wompi no respondió la búsqueda por referencia');
        }
        $lista = isset($d['data']['id']) ? [$d['data']] : array_values(array_filter($d['data'], 'is_array'));
        // Solo las de ESA referencia, por si el filtro de Wompi fuera más amplio.
        return array_values(array_filter($lista, fn($t) => (string) ($t['reference'] ?? '') === $referencia));
    }

    /**
     * ¿Wompi acepta la llave privada? Busca una referencia que no existe: no
     * crea ni cobra nada. null = la acepta; si no, el motivo.
     */
    public static function probarLlaves(): ?string
    {
        try {
            self::transacciones(self::PREFIJO . 'VERIFICAR-' . bin2hex(random_bytes(4)));
            return null;
        } catch (Throwable $t) {
            return $t->getMessage();
        }
    }

    /**
     * Lo que importa de la búsqueda, con las mismas claves que
     * AvalPay::interpretar. De varias transacciones manda la aprobada; si no
     * hay, la que sigue pendiente; si no, la del id del retorno o la más
     * reciente. 'revisar' trae el motivo cuando Wompi aprobó algo que no es
     * exactamente el valor del intento en pesos colombianos: ese pago no se
     * registra solo.
     *
     * @param array  $lista         transacciones de la referencia (self::transacciones)
     * @param int    $valorEsperado valor del intento, en pesos
     * @param string $idRetorno     id que trajo la URL del retorno (solo para elegir cuál mostrar)
     */
    public static function interpretar(array $lista, int $valorEsperado, string $idRetorno = ''): array
    {
        $estadoDe = fn(array $t) => strtoupper(trim((string) ($t['status'] ?? 'PENDING')));
        $peso = fn(array $t) => ['APPROVED' => 3, 'PENDING' => 2][$estadoDe($t)] ?? ((string) ($t['id'] ?? '') === $idRetorno && $idRetorno !== '' ? 1 : 0);
        usort($lista, fn($a, $b) => $peso($b) <=> $peso($a) ?: strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $tx = $lista[0] ?? null;
        if ($tx === null) {
            return ['estado' => 'PENDING', 'aprobado' => false, 'sinIntento' => true, 'banco' => self::MEDIO, 'autorizacion' => '',
                    'fecha' => date('c'), 'valor' => null, 'mensaje' => '', 'revisar' => null];
        }
        $estado = $estadoDe($tx);
        if (!in_array($estado, ['APPROVED', 'DECLINED', 'VOIDED', 'ERROR', 'PENDING'], true)) {
            $estado = 'PENDING';   // un estado que no se conoce nunca cuenta como pagado ni cierra el intento
        }
        $medio = strtoupper(trim((string) ($tx['payment_method_type'] ?? '')));
        if ($medio !== '' && !preg_match('/^[A-Z_]{1,30}$/', $medio)) {
            $medio = 'OTRO';
        }
        $centavos = isset($tx['amount_in_cents']) && is_numeric($tx['amount_in_cents']) ? (int) $tx['amount_in_cents'] : null;
        $moneda = strtoupper((string) ($tx['currency'] ?? ''));
        $revisar = null;
        if ($estado === 'APPROVED' && ($moneda !== self::MONEDA || $centavos !== $valorEsperado * 100)) {
            $revisar = 'Wompi aprobó ' . ($centavos === null ? '?' : number_format($centavos / 100, 2, ',', '.')) . ' ' . ($moneda ?: '?')
                     . ' y el intento era por ' . $valorEsperado . ' COP';
        }
        $aprobadas = count(array_filter($lista, fn($t) => $estadoDe($t) === 'APPROVED'));
        if ($aprobadas > 1) {
            error_log('[psepaipa pago] ATENCIÓN: Wompi tiene ' . $aprobadas . ' transacciones aprobadas con la referencia '
                . (string) ($tx['reference'] ?? '') . ': revisar la devolución');
        }
        return [
            'estado'       => $estado,
            'aprobado'     => $estado === 'APPROVED',
            'sinIntento'   => false,
            'banco'        => (self::esPruebas() ? 'Wompi PRUEBAS' : 'Wompi') . ($medio !== '' ? ' - ' . $medio : ''),
            'autorizacion' => (string) ($tx['id'] ?? ''),
            'fecha'        => (string) ($tx['finalized_at'] ?? $tx['created_at'] ?? date('c')),
            'valor'        => $centavos === null ? null : (int) round($centavos / 100),
            'mensaje'      => mb_substr((string) ($tx['status_message'] ?? ''), 0, 100),
            'revisar'      => $revisar,
        ];
    }
}
