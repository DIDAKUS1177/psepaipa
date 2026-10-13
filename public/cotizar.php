<?php
/**
 * Cotiza (sin guardar nada) el valor a pagar de un rango de vigencias, con el
 * procedimiento oficial. Lo llama la página del predio cada vez que cambia la
 * selección de años. Responde JSON.
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(array $r, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

if (usuario() === null) {
    aviso('Tu sesión terminó por inactividad. Inicia sesión de nuevo.', 'info');
    responder(['ok' => false, 'sinSesion' => true, 'mensaje' => 'Tu sesión terminó. Inicia sesión de nuevo.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValido($_POST['csrf'] ?? null)) {
    responder(['ok' => false, 'mensaje' => 'Recarga la página e intenta de nuevo.'], 400);
}

$id = (int) ($_POST['id'] ?? 0);
$desde = (int) ($_POST['desde'] ?? 0);
$hasta = (int) ($_POST['hasta'] ?? 0);
$anio = (int) date('Y');
if ($id <= 0 || $desde < 1900 || $hasta > $anio || $desde > $hasta) {
    responder(['ok' => false, 'mensaje' => 'Las vigencias elegidas no son válidas.'], 400);
}

if (!Predios::permitido($id, usuario()['documento'])) {
    responder(['ok' => false, 'mensaje' => 'Vuelve a buscar el predio por su referencia catastral.'], 403);
}
try {
    $c = Predios::cotizar($id, $desde, $hasta);
} catch (AvisoUsuario $e) {
    responder(['ok' => false, 'mensaje' => $e->getMessage()], 400);
} catch (Throwable $t) {
    error_log('[psepaipa] cotizar.php: ' . $t->getMessage());
    responder(['ok' => false, 'mensaje' => 'No pudimos calcular el valor. Intenta de nuevo.'], 500);
}

$filas = [];
foreach ($c['filas'] as $f) {
    if ($f['anio'] < $desde || $f['anio'] > $hasta) {
        continue;
    }
    $filas[] = [
        'anio'      => $f['anio'],
        'impuesto'  => pesos($f['impuesto']),
        'intereses' => pesos($f['intereses']),
        'sobretasa' => pesos($f['sobretasa']),
        'descuento' => pesos($f['descuento']),
        'otros'     => pesos($f['otros']),
        'total'     => pesos($f['total']),
    ];
}
responder([
    'ok'       => true,
    'calculado' => $c['ok'],
    'total'    => pesos($c['total']),
    'hasta'    => fechaCorta($c['hasta']),
    'filas'    => $filas,
    'desglose' => array_map('pesos', $c['desglose']),
]);
