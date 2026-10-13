<?php
/**
 * A donde AvalPay devuelve al contribuyente. Consulta el estado real de la
 * sesión (nada de la URL se cree), lo aplica y muestra el detalle de la
 * transacción: referencia, estado, fecha, valor y moneda (certificación).
 *
 * GET i = intento, t = firma del enlace (AvalPay::firmaRetorno). No exige la
 * sesión del portal: pudo vencer mientras pagaba en el banco.
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';

$id = (int) ($_GET['i'] ?? 0);
$firma = (string) ($_GET['t'] ?? '');
$intento = null;
if ($id > 0 && AvalPay::configurado() && hash_equals(AvalPay::firmaRetorno($id), $firma)) {
    try {
        $intento = PagoLinea::intento($id);
    } catch (Throwable $t) {
        error_log('[psepaipa retorno] ' . $id . ': ' . $t->getMessage());
    }
}

$info = null;
$fallaConsulta = false;
if ($intento !== null) {
    try {
        $r = PagoLinea::actualizar($intento);
        $intento = $r['intento'] ?? $intento;
        $info = $r['info'];
    } catch (Throwable $t) {
        $fallaConsulta = true;
        error_log('[psepaipa retorno] intento ' . $id . ': ' . $t->getMessage());
    }
}

$estado = $intento !== null ? (string) $intento['EstadoTransaccion'] : '';
$sinIntentoBanco = $info !== null && !empty($info['sinIntento']);
switch (true) {
    case $intento === null:
        $clase = 'error'; $titulo = 'Enlace no válido';
        $mensaje = 'No pudimos verificar este enlace de pago. Consulta el estado desde la página del predio.';
        break;
    case $estado === 'APPROVED':
        $clase = 'ok'; $titulo = 'Pago aprobado';
        $mensaje = 'El pago quedó registrado. Las vigencias aparecen pagadas en el estado financiero del predio.';
        break;
    case $estado === 'DOBLE':
        $clase = 'alerta'; $titulo = 'Pago repetido';
        $mensaje = 'El banco aprobó el pago, pero la factura ya estaba pagada. La Alcaldía revisará la devolución; conserva el comprobante del banco.';
        break;
    case $estado === 'REVISAR':
        $clase = 'alerta'; $titulo = 'Pago en revisión';
        $mensaje = 'El banco aprobó el pago y la Alcaldía lo va a aplicar manualmente. Conserva el comprobante y la referencia.';
        break;
    case $fallaConsulta:
        $clase = 'pendiente'; $titulo = 'Estamos confirmando el pago';
        $mensaje = 'No pudimos consultar el banco en este momento. Si el pago se hizo, quedará registrado automáticamente en las próximas horas.';
        break;
    case $sinIntentoBanco:
        $clase = 'pendiente'; $titulo = 'Pago sin terminar';
        $mensaje = 'Todavía no se ha hecho el pago. Puedes volver al botón de pagos con el enlace de abajo; si no lo terminas, podrás iniciar otro cuando venza (' . AvalPay::MINUTOS_SESION . ' minutos después de iniciado).';
        break;
    case in_array($estado, ['PENDING', 'COMENZADA'], true):
        $clase = 'pendiente'; $titulo = 'Pago en proceso';
        $mensaje = 'El banco todavía no confirma el pago. Se actualizará solo en cuanto lo haga (puede tardar unos minutos); no inicies otro pago mientras tanto.';
        break;
    default:
        $clase = 'error'; $titulo = 'Pago no aprobado';
        $mensaje = 'El pago no se realizó. Puedes intentarlo de nuevo desde la página del predio.';
        if ($info !== null && $info['mensaje'] !== '') {
            $mensaje .= ' (' . $info['mensaje'] . ')';
        }
}

$nombresEstado = ['APPROVED' => 'Aprobado', 'PENDING' => 'Pendiente', 'COMENZADA' => 'Iniciado', 'REJECTED' => 'Rechazado',
                  'EXPIRED' => 'Vencido', 'FAILED' => 'Fallido', 'DOBLE' => 'Pago repetido', 'REVISAR' => 'En revisión'];
$chip = ['ok' => 'chip-ok', 'pendiente' => 'chip-info', 'alerta' => 'chip-pendiente', 'error' => 'chip-pendiente'][$clase];
$icono = ['ok' => 'chequeo', 'pendiente' => 'reloj', 'alerta' => 'alerta', 'error' => 'alerta'][$clase];
$fecha = $intento !== null ? date('d/m/Y h:i a', strtotime((string) ($info['fecha'] ?? $intento['created_at']))) : '';
$idPredio = $intento !== null ? (int) $intento['referencia'] : 0;
$u = usuario();
$volver = $u !== null && $idPredio > 0 && Predios::permitido($idPredio, $u['documento']) ? 'predio.php?id=' . $idPredio : ($u !== null ? 'inicio.php' : './');

Vista::abrir('Resultado del pago', ['titulo' => 'Pago en línea', 'volver' => $volver, 'icono' => 'pagar'], '', 'predios');
?>
<div class="contenedor pagina pago-resumen">
    <section class="tarjeta pago-resultado pago-<?= $clase ?>" aria-labelledby="t-resultado">
        <div class="pago-cabeza">
            <span class="pago-icono"><?= Vista::icono($icono) ?></span>
            <div>
                <h2 id="t-resultado" class="tarjeta-titulo"><?= e($titulo) ?></h2>
                <p><?= e($mensaje) ?></p>
            </div>
        </div>
        <?php if ($intento !== null): ?>
        <dl class="pago-filas">
            <div><dt>Referencia (factura)</dt><dd class="mono"><?= e(trim((string) $intento['factura'])) ?></dd></div>
            <div><dt>Estado</dt><dd><span class="chip <?= $chip ?>"><?= e($nombresEstado[$estado] ?? $estado) ?></span></dd></div>
            <div><dt>Fecha y hora</dt><dd><?= e($fecha) ?></dd></div>
            <div><dt>Concepto</dt><dd><?= e($intento['Impuesto']) ?></dd></div>
            <?php if (trim((string) $intento['CUS']) !== '' && trim((string) $intento['CUS']) !== '0'): ?>
            <div><dt>Autorización / CUS</dt><dd class="mono"><?= e($intento['CUS']) ?></dd></div>
            <?php endif; ?>
            <div class="pago-total"><dt>Valor</dt><dd><?= pesos($info['valor'] ?? $intento['total']) ?> <small>COP</small></dd></div>
            <div><dt>Entidad</dt><dd>Alcaldía de <?= e(config('municipio.ciudad')) ?></dd></div>
        </dl>
        <?php endif; ?>
        <div class="pago-botones">
            <?php if ($sinIntentoBanco && trim((string) $intento['URL']) !== ''): ?>
                <a class="boton boton-contorno" href="<?= e($intento['URL']) ?>">Volver al botón de pagos</a>
            <?php endif; ?>
            <a class="boton boton-primario" href="<?= e($volver) ?>"><?= $u === null ? 'Iniciar sesión' : (strpos($volver, 'predio.php') === 0 ? 'Volver al predio' : 'Ir a mis predios') ?></a>
        </div>
    </section>
</div>
<?php
Vista::cerrar();
