<?php
/**
 * Arranque común de todas las páginas: configuración, sesión, errores y
 * utilidades pequeñas. Cada página de public/ hace require de este archivo.
 */

declare(strict_types=1);

const RAIZ = __DIR__ . '/..';

$rutaConfig = RAIZ . '/config.local.php';
if (!is_file($rutaConfig)) {
    $rutaConfig = RAIZ . '/config/config.local.php';
}
if (!is_file($rutaConfig)) {
    http_response_code(500);
    exit('Falta config.local.php (ver config/config.example.php).');
}
$GLOBALS['CONFIG'] = require $rutaConfig;

// Conexión tomada del appsettings.json de la API .NET anterior (ver
// src/ConexionNet.php), si el config lo indica.
if (!empty($GLOBALS['CONFIG']['db_appsettings'])) {
    require_once __DIR__ . '/ConexionNet.php';
    $db = ConexionNet::leer((string) $GLOBALS['CONFIG']['db_appsettings'], $GLOBALS['CONFIG']['db_appsettings_nombre'] ?? null);
    if ($db !== null) {
        $GLOBALS['CONFIG']['db'] = $db;
    } else {
        error_log('[psepaipa] No se pudo leer la conexión de ' . $GLOBALS['CONFIG']['db_appsettings']);
    }
}

// Un aviso de PHP impreso antes de un JSON lo rompe (lección de ICA): los
// errores van al log, nunca a la pantalla, salvo en desarrollo.
$depurar = !empty($GLOBALS['CONFIG']['depurar']);
ini_set('display_errors', $depurar ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set($GLOBALS['CONFIG']['zona_horaria'] ?? 'America/Bogota');

/** Mensaje pensado para el contribuyente (los demás errores van al log). */
class AvisoUsuario extends RuntimeException
{
}

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Limites.php';
require_once __DIR__ . '/Cuentas.php';
require_once __DIR__ . '/Predios.php';
require_once __DIR__ . '/Factura.php';
require_once __DIR__ . '/AvalPay.php';
require_once __DIR__ . '/PagoLinea.php';
require_once __DIR__ . '/Vista.php';

$esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('psepaipa');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $esHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();
// Las páginas muestran datos de predios: que el navegador no las guarde
// (equipos compartidos, café internet).
if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store, max-age=0');
    header_remove('X-Powered-By');
}

// La sesión vence tras 30 minutos sin actividad.
const MINUTOS_SESION = 30;
if (isset($_SESSION['ultimo']) && time() - (int) $_SESSION['ultimo'] > MINUTOS_SESION * 60) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['ultimo'] = time();

function config(string $clave, $porDefecto = null)
{
    $c = $GLOBALS['CONFIG'];
    foreach (explode('.', $clave) as $parte) {
        if (!is_array($c) || !array_key_exists($parte, $c)) {
            return $porDefecto;
        }
        $c = $c[$parte];
    }
    return $c;
}

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Pesos sin decimales con punto de miles: 1.234.567 */
function pesos($valor): string
{
    return '$ ' . number_format((float) round((float) $valor), 0, ',', '.');
}

/** Fecha corta en español: 30/04/2026 */
function fechaCorta($fecha): string
{
    if ($fecha === null || $fecha === '') {
        return '';
    }
    $t = $fecha instanceof DateTimeInterface ? $fecha->getTimestamp() : strtotime((string) $fecha);
    return $t ? date('d/m/Y', $t) : '';
}

function hoy(): string
{
    return date('Y-m-d');
}

/** Token contra envíos desde otros sitios, uno por sesión. */
function tokenCsrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfValido(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function usuario(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

/** Para las páginas que exigen sesión. */
function exigirSesion(): array
{
    $u = usuario();
    if ($u === null) {
        $_SESSION['aviso'] = 'Inicia sesión para continuar.';
        header('Location: ./');
        exit;
    }
    return $u;
}

function redirigir(string $a): void
{
    header('Location: ' . $a);
    exit;
}

/** Mensaje de una sola lectura para la siguiente página. */
function aviso(?string $texto = null, string $tipo = 'info'): ?array
{
    if ($texto !== null) {
        $_SESSION['aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        return null;
    }
    $a = $_SESSION['aviso'] ?? null;
    unset($_SESSION['aviso']);
    if (is_string($a)) {
        $a = ['texto' => $a, 'tipo' => 'info'];
    }
    return $a;
}
