<?php
/**
 * Botón de pago con 1cero1pay (Bancolombia), el segundo botón del predial
 * (pedido de Paipa, 2026-10-21: "uno con 101 para Bancolombia y el otro el
 * nuestro").
 *
 * Es la misma integración que usaba el PSE anterior (Angular + .NET), tal como
 * se leyó del Angular compilado el 2026-10-11:
 *   POST {url}/Login/authenticate            {Username, Password} -> el cuerpo
 *                                             entero es el token ("Bearer " + token)
 *   POST {url}/transaction/InsertTransaction  {Referencia, Factura, Total,
 *        CodigoEntidad, IDImpuesto, FuentePago, TipoImplementacion,
 *        Pagador:{Identificacion, TipoDocumento, Nombre, Telefono, Email}}
 *                                             -> {IDTransaccion, Mensaje, URL}
 *   GET  {url}/transaction/?CodigoEntidad=&Factura=&IDImpuesto=
 *                                             -> {TransactionID, CUS, EstadoTransaccion,
 *                                                 MedioPago, FechaTransaccion, Referencia}
 * El sistema viejo no recibía avisos: consultaba ("Validar pago"). Aquí lo
 * hacen el retorno y la tarea herramientas/verificar-pagos.php.
 *
 * Las credenciales son LAS MISMAS del sistema viejo (Diego, 2026-10-26) y no
 * se copian: el viejo las leía de la base de predial (conf_medios_pagos:
 * User_Name_TK, User_Password_TK, Codigo_Entidad, por su API
 * Predios/GetDataEntidad) y cada intento suyo dejó en generacion_pagos la
 * fuente de pago, el impuesto y el tipo de implementación. Con 'desde_bd'
 * (por defecto) se leen de ahí; lo que se escriba en config.local.php
 * ('unocerouno') manda sobre la base. Sin credenciales el botón se ve
 * deshabilitado ("Próximamente"). Lo que no se sabe todavía de su API (los
 * valores exactos de EstadoTransaccion, si devuelve el valor pagado) se
 * interpreta con cuidado: lo dudoso nunca se registra como pagado.
 */

declare(strict_types=1);

final class UnoCeroUno
{
    const MEDIO = '1CERO1PAY';

    /** @var string|null token de la petición en curso */
    private static $token = null;

    /** @var array|null lo leído de la base del sistema viejo (una vez por petición) */
    private static $bd = null;

    private static function c(string $clave, $defecto = '')
    {
        return config('unocerouno.' . $clave, $defecto);
    }

    /**
     * Un dato de la integración: el de config.local.php si está escrito; si no,
     * el que usaba el sistema viejo (base de predial). usuario, clave,
     * codigo_entidad, id_impuesto, fuente_pago, tipo_implementacion.
     */
    private static function dato(string $clave): string
    {
        $v = trim((string) self::c($clave));
        if ($v !== '' || !self::c('desde_bd', true)) {
            return $v;
        }
        return (string) (self::delSistemaViejo()[$clave] ?? '');
    }

    /**
     * Lo que usaba el PSE viejo: credenciales y entidad de conf_medios_pagos
     * (fila 'id_medio', la 1 por defecto, como su GetdataToken(1)) y los
     * códigos del último intento suyo en generacion_pagos (no los de este
     * portal). Si la base no los tiene, vacío: el botón queda en "Próximamente".
     */
    private static function delSistemaViejo(): array
    {
        if (self::$bd !== null) {
            return self::$bd;
        }
        self::$bd = [];
        try {
            $m = Db::fila('SELECT User_Name_TK AS u, User_Password_TK AS p, Codigo_Entidad AS e FROM conf_medios_pagos WHERE Id = ?',
                [(int) self::c('id_medio', 1)]);
            if ($m === null) {
                return self::$bd;
            }
            self::$bd = ['usuario' => trim((string) $m['u']), 'clave' => trim((string) $m['p']), 'codigo_entidad' => trim((string) $m['e'])];
            $nuestros = PagoLinea::proveedores();
            $g = Db::fila('SELECT TOP 1 fuente_pago AS f, ID_impuesto AS i, tipo_implementacion AS t FROM generacion_pagos
                            WHERE proveedor NOT IN (' . implode(', ', array_fill(0, count($nuestros), '?')) . ')
                              AND fuente_pago IS NOT NULL AND ID_impuesto IS NOT NULL AND tipo_implementacion IS NOT NULL
                            ORDER BY IdGeneraPado DESC', $nuestros);
            if ($g !== null) {
                self::$bd += ['fuente_pago' => (string) $g['f'], 'id_impuesto' => (string) $g['i'], 'tipo_implementacion' => (string) $g['t']];
            }
        } catch (Throwable $e) {
            error_log('[1cero1pay] no se pudo leer la configuración del sistema viejo: ' . $e->getMessage());
        }
        return self::$bd;
    }

    /** Para herramientas/verificar.php: qué hay (sin mostrar usuario ni clave). */
    public static function resumen(): array
    {
        $r = [];
        foreach (['usuario', 'clave', 'codigo_entidad', 'id_impuesto', 'fuente_pago', 'tipo_implementacion'] as $k) {
            $v = self::dato($k);
            $r[$k] = $v === '' ? 'FALTA' : (in_array($k, ['usuario', 'clave'], true) ? 'sí' : $v);
        }
        return $r;
    }

    public static function proveedor(): int
    {
        return (int) self::c('proveedor', 6);
    }

    public static function configurado(): bool
    {
        if (trim((string) self::c('url')) === '') {
            return false;
        }
        foreach (['usuario', 'clave', 'codigo_entidad', 'id_impuesto', 'fuente_pago', 'tipo_implementacion'] as $k) {
            if (self::dato($k) === '') {
                return false;
            }
        }
        return true;
    }

    /** Credenciales de pruebas (lo dice el config: su URL no lo distingue). */
    public static function esPruebas(): bool
    {
        return (bool) self::c('pruebas', false);
    }

    /** ¿Este usuario ve el botón activo? (modo prueba: solo los de la lista). */
    public static function disponiblePara(?array $u): bool
    {
        if ($u === null || !self::c('activo', false) || !self::configurado()) {
            return false;
        }
        $prueba = array_map('strval', (array) self::c('usuarios_prueba', []));
        return !$prueba || in_array((string) $u['documento'], $prueba, true);
    }

    /** Secreto para firmar los enlaces de retorno cuando no hay AvalPay. */
    public static function secreto(): string
    {
        return self::dato('clave');
    }

    /** ¿El enlace de pago es de 1cero1pay? (https y su dominio, o uno permitido). */
    public static function urlDePagoValida(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $https = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
        $extra = array_map('strtolower', (array) self::c('hosts_permitidos', []));
        if ($host === '') {
            return false;
        }
        if (in_array($host, $extra, true)) {
            return true;
        }
        $api = strtolower((string) parse_url((string) self::c('url'), PHP_URL_HOST));
        return $https && ($host === $api || $host === '1cero1pay.com' || substr($host, -strlen('.1cero1pay.com')) === '.1cero1pay.com');
    }

    private static function pedir(string $metodo, string $ruta, ?array $cuerpo, bool $conToken)
    {
        $ch = curl_init(rtrim((string) self::c('url'), '/') . $ruta);
        $cabeceras = ['Accept: application/json'];
        if ($cuerpo !== null) {
            $cabeceras[] = 'Content-Type: application/json';
        }
        if ($conToken) {
            $cabeceras[] = 'Authorization: Bearer ' . self::token();
        }
        $op = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CUSTOMREQUEST  => $metodo,
        ];
        if ($cuerpo !== null) {
            $op[CURLOPT_POSTFIELDS] = json_encode($cuerpo);
        }
        curl_setopt_array($ch, $op);
        $r = curl_exec($ch);
        if ($r === false) {
            $e = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Sin conexión con 1cero1pay: ' . $e);
        }
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($codigo >= 400) {
            throw new RuntimeException('1cero1pay respondió HTTP ' . $codigo);
        }
        $d = json_decode((string) $r, true);
        return $d === null ? trim((string) $r) : $d;
    }

    /** El token de la sesión con 1cero1pay (una vez por petición). */
    private static function token(): string
    {
        if (self::$token === null) {
            $r = self::pedir('POST', '/Login/authenticate',
                ['Username' => self::dato('usuario'), 'Password' => self::dato('clave')], false);
            // El viejo usaba el cuerpo entero como token; si viene en un objeto, se toma el campo.
            if (is_array($r)) {
                $r = $r['token'] ?? $r['Token'] ?? $r['access_token'] ?? '';
            }
            $t = trim((string) $r, " \t\n\r\"");
            if ($t === '') {
                throw new RuntimeException('1cero1pay no entregó el token');
            }
            self::$token = $t;
        }
        return self::$token;
    }

    /** Quita el sobre de la respuesta: {data:{value}}, {data}, o una lista. */
    private static function contenido($d): array
    {
        if (!is_array($d)) {
            return [];
        }
        if (isset($d['data']) && is_array($d['data'])) {
            $d = $d['data']['value'] ?? $d['data'];
        } elseif (isset($d['value']) && is_array($d['value'])) {
            $d = $d['value'];
        }
        return is_array($d) ? $d : [];
    }

    /**
     * Crea la transacción. Devuelve [IDTransaccion, URL]. La referencia es el
     * predio y la factura es la que se paga, como en el sistema anterior.
     */
    public static function crear(string $factura, int $idPredio, int $valor, array $u): array
    {
        // id_tipo_identificacion => código de 1cero1pay. Vacío: el mismo código de
        // usuarios_pse, que es la tabla de cuentas del sistema viejo.
        $tipos = (array) self::c('tipos_documento', []);
        $pagador = [
            'Identificacion' => (string) $u['documento'],
            'TipoDocumento'  => (string) ($tipos[(int) ($u['tipo'] ?? 0)] ?? ($u['tipo'] ?? '')),
            'Nombre'         => trim(($u['nombres'] ?? '') . ' ' . ($u['apellidos'] ?? '')),
            'Telefono'       => (string) ($u['telefono'] ?? ''),
            'Email'          => (string) ($u['correo'] ?? ''),
        ];
        $d = self::contenido(self::pedir('POST', '/transaction/InsertTransaction', [
            'Referencia'         => (string) $idPredio,
            'Factura'            => $factura,
            'Total'              => $valor,
            'CodigoEntidad'      => self::dato('codigo_entidad'),
            'IDImpuesto'         => self::dato('id_impuesto'),
            'FuentePago'         => self::dato('fuente_pago'),
            'TipoImplementacion' => self::dato('tipo_implementacion'),
            'Pagador'            => $pagador,
        ], true));
        $id = (string) ($d['IDTransaccion'] ?? $d['IdTransaccion'] ?? $d['TransactionID'] ?? '');
        $url = (string) ($d['URL'] ?? $d['Url'] ?? $d['url'] ?? '');
        if ($id === '' || $url === '') {
            throw new RuntimeException('1cero1pay no creó la transacción: ' . mb_substr((string) ($d['Mensaje'] ?? 'sin detalle'), 0, 120));
        }
        return [$id, $url];
    }

    /** Las transacciones de una factura (lista, la más reciente al final). */
    public static function consultar(string $factura): array
    {
        $q = http_build_query([
            'CodigoEntidad' => self::dato('codigo_entidad'),
            'Factura'       => $factura,
            'IDImpuesto'    => self::dato('id_impuesto'),
        ]);
        $d = self::contenido(self::pedir('GET', '/transaction/?' . $q, null, true));
        if ($d === []) {
            return [];
        }
        return array_key_exists(0, $d) ? array_values(array_filter($d, 'is_array')) : [$d];
    }

    /** Estado normalizado al de AvalPay (APPROVED, PENDING, REJECTED, EXPIRED). */
    public static function estado(string $texto): string
    {
        $t = strtoupper(trim($texto));
        if ($t === '' ) {
            return 'PENDING';
        }
        if (in_array($t, ['OK', 'APPROVED'], true) || strpos($t, 'APROB') !== false || strpos($t, 'EXITOS') !== false) {
            return 'APPROVED';
        }
        if (strpos($t, 'RECHAZ') !== false || strpos($t, 'REJECT') !== false || strpos($t, 'FALL') !== false
            || strpos($t, 'FAIL') !== false || strpos($t, 'DECLIN') !== false || strpos($t, 'ANUL') !== false
            || strpos($t, 'NOT_AUTH') !== false || strpos($t, 'ERROR') !== false) {
            return 'REJECTED';
        }
        if (strpos($t, 'EXPIR') !== false || strpos($t, 'VENC') !== false) {
            return 'EXPIRED';
        }
        return 'PENDING';
    }

    /**
     * Lo que importa de una consulta, con las mismas claves que
     * AvalPay::interpretar. La transacción aprobada si hay alguna; si no, la
     * del intento (su IDTransaccion) o la última.
     */
    public static function interpretar(array $lista, string $idTransaccion): array
    {
        $tx = null;
        foreach ($lista as $t) {
            if (self::estado((string) ($t['EstadoTransaccion'] ?? '')) === 'APPROVED') {
                $tx = $t;
                break;
            }
        }
        if ($tx === null) {
            foreach ($lista as $t) {
                if ((string) ($t['TransactionID'] ?? $t['IDTransaccion'] ?? '') === $idTransaccion) {
                    $tx = $t;
                }
            }
        }
        if ($tx === null && $lista) {
            $tx = end($lista);
        }
        $estado = $tx === null ? 'PENDING' : self::estado((string) ($tx['EstadoTransaccion'] ?? ''));
        $valor = $tx['Total'] ?? $tx['Valor'] ?? $tx['ValorPagado'] ?? null;
        return [
            'estado'       => $estado,
            'aprobado'     => $estado === 'APPROVED',
            'sinIntento'   => $tx === null,
            'banco'        => (string) ($tx['MedioPago'] ?? 'Bancolombia'),
            'autorizacion' => (string) ($tx['CUS'] ?? ''),
            'fecha'        => (string) ($tx['FechaTransaccion'] ?? date('c')),
            'valor'        => is_numeric($valor) ? (int) round((float) $valor) : null,
            'mensaje'      => mb_substr((string) ($tx['Mensaje'] ?? $tx['EstadoTransaccion'] ?? ''), 0, 100),
        ];
    }
}
