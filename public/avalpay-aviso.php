<?php
/**
 * URL de notificación que se registra ante AvalPay. Cuando cambia el estado de
 * una sesión, AvalPay hace POST aquí con requestId, status y signature. Se
 * valida la firma y, aun así, el estado que se aplica SIEMPRE sale de una
 * consulta autenticada aparte (PagoLinea::actualizar), nunca del POST.
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';

header('Content-Type: application/json; charset=utf-8');

function responder(array $r, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

$cuerpo = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($cuerpo) || empty($cuerpo['requestId'])) {
    responder(['ok' => false, 'mensaje' => 'Falta requestId'], 400);
}
if (!AvalPay::firmaValida($cuerpo)) {
    responder(['ok' => false, 'mensaje' => 'Firma inválida'], 401);
}

try {
    $intento = PagoLinea::porSesion((string) $cuerpo['requestId']);
    if ($intento === null) {
        // Puede ser de otra integración (ICA usa el mismo comercio): 200 para que no reintente.
        responder(['ok' => true, 'mensaje' => 'Sesión no asociada a este portal']);
    }
    $r = PagoLinea::actualizar($intento);
    responder(['ok' => true, 'estado' => (string) ($r['intento']['EstadoTransaccion'] ?? '')]);
} catch (Throwable $t) {
    // 500: que AvalPay reintente; si nunca llega, la tarea programada lo recoge.
    error_log('[psepaipa aviso] ' . $cuerpo['requestId'] . ': ' . $t->getMessage());
    responder(['ok' => false, 'mensaje' => 'Error interno'], 500);
}
