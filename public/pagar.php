<?php
/**
 * Pago en línea de las vigencias elegidas, con una de las dos pasarelas
 * (pasarela = 'avalpay', PSE del Banco de Bogotá, o '101', Bancolombia con
 * 1cero1pay; 2026-10-21).
 *
 * 1. Desde el predio (POST id, desde, hasta): resumen con el valor exacto de
 *    hoy y la aceptación del tratamiento de datos (lo exige la certificación
 *    de AvalPay, igual que en ICA). No guarda nada.
 * 2. Con acepto = 1: genera (o reutiliza) la factura, anota el intento, crea
 *    la sesión en AvalPay y manda al contribuyente a pagar. Si el valor cambió
 *    desde el resumen, vuelve al resumen con el valor nuevo.
 *
 * Mientras un pago del predio está en curso no se abre otro (PagoLinea::enTramite).
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';

$u = exigirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValido($_POST['csrf'] ?? null)) {
    redirigir('inicio.php');
}
$id = (int) ($_POST['id'] ?? 0);
$desde = (int) ($_POST['desde'] ?? 0);
$hasta = (int) ($_POST['hasta'] ?? 0);
$volver = 'predio.php?id=' . $id;
$uno = ($_POST['pasarela'] ?? '') === '101';

if ($uno ? !UnoCeroUno::disponiblePara($u) : !AvalPay::disponiblePara($u)) {
    aviso('El pago en línea todavía no está disponible. Puedes pagar en el banco con la factura.', 'info');
    redirigir($volver);
}

try {
    $p = Predios::permitido($id, $u['documento']) ? Predios::detalle($id) : null;
    if ($p === null) {
        aviso('Vuelve a buscar el predio por su referencia catastral.', 'info');
        redirigir('inicio.php?vista=otros');
    }
    PagoLinea::refrescar($id);
    $tramite = PagoLinea::enTramite($id);
    if ($tramite !== null) {
        redirigir(PagoLinea::urlRetorno((int) $tramite['IdGeneraPado']));
    }
    if (PagoLinea::porConciliar($id) !== null) {
        throw new AvisoUsuario('Este predio tiene un pago en línea aprobado que la Alcaldía está revisando. Antes de volver a pagar, comunícate con la Dirección de Impuestos.');
    }
    if ($uno ? !UnoCeroUno::configurado() : !AvalPay::configurado()) {
        throw new AvisoUsuario('El pago en línea no está disponible en este momento. Puedes pagar en el banco con la factura.');
    }
    // En el ambiente de pruebas solo pagan los usuarios de prueba (y el pago
    // no se registra: PagoLinea lo deja en PRUEBAS).
    if ($uno ? (UnoCeroUno::esPruebas() && !config('unocerouno.usuarios_prueba'))
             : (AvalPay::esPruebas() && !config('avalpay.usuarios_prueba'))) {
        throw new AvisoUsuario('El pago en línea no está disponible en este momento. Puedes pagar en el banco con la factura.');
    }
    $anio = (int) date('Y');
    if ($desde < 1900 || $hasta > $anio || $desde > $hasta) {
        throw new AvisoUsuario('Las vigencias elegidas no son válidas.');
    }

    $cambio = null;
    if (($_POST['acepto'] ?? '') === '1') {
        $f = Factura::generar($id, $desde, $hasta, (int) $u['id']);
        $valor = (int) $f['cupones'][0]['valor'];
        if ($valor === (int) ($_POST['valor'] ?? -1)) {
            $intento = PagoLinea::crearIntento($id, $f['numero'], $valor, (string) $u['documento'], $desde, $hasta, $uno);
            if ($intento === null) {
                // Otra pestaña abrió el pago un instante antes: a ese.
                $otro = PagoLinea::enTramite($id);
                redirigir($otro !== null ? PagoLinea::urlRetorno((int) $otro['IdGeneraPado']) : $volver);
            }
            $vigencias = $desde === $hasta ? (string) $desde : $desde . '-' . $hasta;
            try {
                if ($uno) {
                    [$requestId, $url] = UnoCeroUno::crear($f['numero'], $id, $valor, $u);
                    if (!UnoCeroUno::urlDePagoValida($url)) {
                        throw new RuntimeException('enlace de pago inesperado: ' . parse_url($url, PHP_URL_HOST));
                    }
                    PagoLinea::anotarSesion($intento, $requestId, $url);
                    redirigir($url);
                }
                [$requestId, $url] = AvalPay::crearSesion(
                    $f['numero'],
                    $valor,
                    'Impuesto predial ' . config('municipio.ciudad') . ' - Factura ' . $f['numero'] . ' - Vigencias ' . $vigencias,
                    PagoLinea::urlRetorno($intento),
                    [],   // como en ICA: AvalPay pide los datos del titular en su pantalla
                    [
                        ['keyword' => 'Concepto', 'value' => 'Impuesto Predial Unificado', 'displayOn' => 'both'],
                        ['keyword' => 'Referencia catastral', 'value' => (string) $p['codigo_predio'], 'displayOn' => 'both'],
                        ['keyword' => 'Vigencias', 'value' => $vigencias, 'displayOn' => 'both'],
                    ]
                );
                if (!AvalPay::urlDePagoValida($url)) {
                    throw new RuntimeException('enlace de pago inesperado: ' . parse_url($url, PHP_URL_HOST));
                }
            } catch (Throwable $t) {
                PagoLinea::fallo($intento, $t->getMessage());
                error_log('[psepaipa pago] crear sesión, intento ' . $intento . ': ' . $t->getMessage());
                throw new AvisoUsuario('No pudimos conectar con el botón de pagos en este momento. Intenta de nuevo en unos minutos o paga en el banco con la factura.');
            }
            PagoLinea::anotarSesion($intento, $requestId, $url);
            redirigir($url);
        }
        $cambio = 'El valor cambió desde que abriste el resumen. Revisa el nuevo valor antes de continuar.';
        $total = $valor;
        $hastaFecha = $f['cupones'][0]['hasta'];
    } else {
        $c = Predios::cotizar($id, $desde, $hasta);
        if (!$c['ok']) {
            throw new AvisoUsuario('No pudimos calcular el valor de hoy. Intenta de nuevo en unos minutos.');
        }
        $total = (int) $c['total'];
        $hastaFecha = $c['hasta'];
    }
} catch (AvisoUsuario $e) {
    aviso($e->getMessage(), 'error');
    redirigir($volver);
} catch (Throwable $t) {
    error_log('[psepaipa pago] predio ' . $id . ': ' . $t->getMessage());
    aviso('No pudimos preparar el pago en este momento. Intenta de nuevo en unos minutos.', 'error');
    redirigir($volver);
}

$politica = (string) config('avalpay.politica_datos', '');
$propio = Predios::esPropietario($id, $u['documento']);
Vista::abrir('Pagar en línea', ['titulo' => 'Pago en línea', 'volver' => $volver, 'icono' => 'pagar'], '', $propio ? 'predios' : 'otro');
?>
<div class="contenedor pagina pago-resumen">
    <?php if ($cambio !== null): ?>
        <div class="aviso aviso-alerta" role="alert"><?= Vista::icono('alerta') ?><span><?= e($cambio) ?></span></div>
    <?php endif; ?>
    <section class="tarjeta" aria-labelledby="t-resumen">
        <h2 id="t-resumen" class="tarjeta-titulo">Resumen del pago</h2>
        <dl class="pago-filas">
            <div><dt>Concepto</dt><dd>Impuesto Predial Unificado</dd></div>
            <div><dt>Referencia catastral</dt><dd class="mono"><?= e($p['codigo_predio']) ?></dd></div>
            <div><dt>Dirección</dt><dd><?= e($p['direccion'] ?? '') ?></dd></div>
            <div><dt>Vigencias</dt><dd><?= $desde === $hasta ? e($desde) : e($desde . ' a ' . $hasta) ?></dd></div>
            <div><dt>Medio de pago</dt><dd><?= $uno ? 'PSE, débito desde tu cuenta Bancolombia' : 'PSE, débito desde tu cuenta bancaria (Banco de Bogotá)' ?></dd></div>
            <div class="pago-total"><dt>Total a pagar</dt><dd><?= pesos($total) ?> <small>COP</small></dd></div>
        </dl>
        <p class="valor-nota"><?= Vista::icono('info') ?><span>Valor liquidado hoy, <?= e(fechaCorta(hoy())) ?>. El pago queda registrado en cuanto el banco lo confirme; si se demora, no inicies otro.</span></p>

        <form method="post" action="pagar.php" class="pago-form" data-ocupado>
            <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <input type="hidden" name="desde" value="<?= (int) $desde ?>">
            <input type="hidden" name="hasta" value="<?= (int) $hasta ?>">
            <input type="hidden" name="valor" value="<?= (int) $total ?>">
            <input type="hidden" name="pasarela" value="<?= $uno ? '101' : 'avalpay' ?>">
            <label class="pago-acepto">
                <input type="checkbox" name="acepto" value="1" required>
                <span>Acepto el tratamiento de mis datos personales<?php if ($politica !== ''): ?> según la <a href="<?= e($politica) ?>" target="_blank" rel="noopener">política de tratamiento de datos</a><?php endif; ?> para procesar este pago.</span>
            </label>
            <div class="pago-botones">
                <a class="boton boton-contorno" href="<?= e($volver) ?>">Cancelar</a>
                <button type="submit" class="boton boton-primario"><?= Vista::icono('pagar') ?><?= $uno ? 'Ir a pagar con Bancolombia' : 'Ir a pagar con PSE' ?></button>
            </div>
        </form>
    </section>
</div>
<?php
Vista::cerrar();
