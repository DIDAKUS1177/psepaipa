<?php
/**
 * Un predio: información, estado financiero por vigencia (con casillas para
 * elegir qué años pagar) y el valor a pagar.
 *
 * Al abrirlo se ponen al día los intereses con el procedimiento oficial (como
 * mucho una vez al día por predio). A quien no es propietario se le ocultan
 * el nombre y el documento del dueño, pero puede pagar.
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';

$u = exigirSesion();
$id = (int) ($_GET['id'] ?? 0);

try {
    $p = $id > 0 ? Predios::detalle($id) : null;
} catch (Throwable $t) {
    error_log('[psepaipa] detalle: ' . $t->getMessage());
    aviso('No pudimos cargar el predio en este momento. Intenta de nuevo en unos minutos.', 'error');
    redirigir('inicio.php');
}
if ($p === null) {
    aviso('No encontramos ese predio.', 'error');
    redirigir('inicio.php');
}

$propio = Predios::esPropietario($id, $u['documento']);
$cotizacion = Predios::cotizar($id);
$alDia = $cotizacion['ok'];
$vigencias = $cotizacion['filas'];
$pagos = [];
try {
    $pagos = Predios::historicoPagos($id);
} catch (Throwable $t) {
    error_log('[psepaipa] histórico: ' . $t->getMessage());
}

$pagables = array_values(array_filter($vigencias, fn($v) => $v['pagable']));
// El valor a pagar es el del cupón oficial del rango (no la suma de filas:
// el procedimiento aplica descuentos según los años que se pagan juntos).
$totalTodo = $cotizacion['total'];
$principal = $p['propietarios'][0] ?? null;
$nombreDueno = $principal ? (string) $principal['nombre'] : '';
$docDueno = $principal ? (string) $principal['identificacion'] : '';
if (!$propio) {
    $nombreDueno = Predios::enmascararNombre($nombreDueno);
    $docDueno = Predios::enmascararDocumento($docDueno);
}
$rango = $pagables ? ($pagables[0]['anio'] === end($pagables)['anio'] ? (string) $pagables[0]['anio'] : $pagables[0]['anio'] . ' – ' . end($pagables)['anio']) : '';

Vista::abrir('Predio ' . $p['codigo_predio'], ['titulo' => 'Impuesto Predial Unificado', 'volver' => 'inicio.php']);
?>
<div class="contenedor pagina">
    <?php if (!$alDia): ?>
        <div class="aviso aviso-alerta" role="status"><?= Vista::icono('alerta') ?><span>No pudimos actualizar los intereses de hoy. Las cifras son las del último cálculo; la factura se recalcula al generarla.</span></div>
    <?php endif; ?>

    <div class="predio-rejilla">
        <section class="tarjeta info-predio" aria-labelledby="t-info">
            <h2 id="t-info" class="tarjeta-titulo">Información del predio</h2>
            <div class="info-referencia">
                <p class="etiqueta">Referencia catastral</p>
                <p class="referencia referencia-grande"><?= e($p['codigo_predio']) ?></p>
            </div>
            <dl class="datos">
                <div><dt>Propietario</dt><dd><?= e($nombreDueno ?: '—') ?><?= $propio ? ' <span class="chip chip-ok chip-mini">Tú</span>' : '' ?></dd></div>
                <div><dt>No. de identificación</dt><dd><?= e($docDueno ?: '—') ?></dd></div>
                <div><dt>Dirección del predio</dt><dd><?= e($p['direccion'] ?: '—') ?></dd></div>
                <div><dt>Matrícula inmobiliaria</dt><dd><?= e($p['matricula'] ?: '—') ?></dd></div>
            </dl>
            <details class="mas-info">
                <summary>Ver información detallada</summary>
                <dl class="datos">
                    <div><dt>Referencia anterior</dt><dd><?= e($p['codigo_predio_anterior'] ?: '—') ?></dd></div>
                    <div><dt>Zona</dt><dd><?= e($p['zona'] ?: '—') ?></dd></div>
                    <div><dt>Avalúo vigente</dt><dd><?= pesos($p['avaluo']) ?></dd></div>
                    <div><dt>Tarifa</dt><dd><?= e(rtrim(rtrim(number_format((float) $p['tarifa_actual'], 2, ',', '.'), '0'), ',')) ?> por mil</dd></div>
                    <div><dt>Área del terreno</dt><dd><?= e(number_format((float) $p['area_metros'], 0, ',', '.')) ?> m²<?= (float) $p['area_hectareas'] > 0 ? ' · ' . e(number_format((float) $p['area_hectareas'], 2, ',', '.')) . ' ha' : '' ?></dd></div>
                    <div><dt>Área construida</dt><dd><?= e(number_format((float) $p['area_construida'], 0, ',', '.')) ?> m²</dd></div>
                    <div><dt>Último año pagado</dt><dd><?= e($p['ultimo_anio_pago'] ?: '—') ?></dd></div>
                    <?php if (count($p['propietarios']) > 1): ?>
                    <div class="datos-ancho"><dt>Copropietarios</dt><dd>
                        <?php foreach (array_slice($p['propietarios'], 1) as $co): ?>
                            <span class="copropietario"><?= e($propio ? $co['nombre'] : Predios::enmascararNombre((string) $co['nombre'])) ?></span>
                        <?php endforeach; ?>
                    </dd></div>
                    <?php endif; ?>
                </dl>
            </details>
        </section>

        <aside class="tarjeta valor-pagar" aria-labelledby="t-valor">
            <h2 id="t-valor" class="tarjeta-titulo">Valor a pagar</h2>
            <?php if ($pagables): ?>
                <p class="valor-vigencias">Pagar vigencia<span data-plural>s</span> <strong data-rango><?= e($rango) ?></strong></p>
                <p class="etiqueta">Total a pagar hoy</p>
                <p class="valor-total" data-total><?= pesos($totalTodo) ?></p>
                <p class="valor-fecha"><?= Vista::icono('reloj') ?>Válido para pagar hasta el <span data-fecha><?= e(fechaCorta($cotizacion['hasta'])) ?></span></p>
                <form method="post" action="factura.php" class="valor-acciones" data-form-pago data-predio="<?= (int) $id ?>">
                    <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <input type="hidden" name="desde" value="<?= (int) $pagables[0]['anio'] ?>" data-desde>
                    <input type="hidden" name="hasta" value="<?= (int) end($pagables)['anio'] ?>" data-hasta>
                    <button class="boton boton-claro" type="submit" name="accion" value="factura"><?= Vista::icono('imprimir') ?>Descargar factura</button>
                    <button class="boton boton-primario" type="button" disabled title="El pago en línea se habilita cuando la Alcaldía active la pasarela"><?= Vista::icono('pagar') ?>Pagar en línea</button>
                </form>
                <p class="valor-nota">La factura trae el código de barras para pagar en los bancos autorizados. El pago en línea estará disponible muy pronto.</p>
            <?php else: ?>
                <div class="al-dia-caja">
                    <?= Vista::icono('escudo') ?>
                    <p><strong>Este predio no tiene vigencias pendientes de pago en línea.</strong></p>
                    <?php if (array_filter($vigencias, fn($v) => $v['estado'] === 'acuerdo')): ?>
                        <p>Tiene vigencias en acuerdo de pago: se pagan con las cuotas del acuerdo.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </aside>
    </div>

    <section class="tarjeta pestanas-detalle" data-pestanas>
        <div class="pestanas pestanas-linea" role="tablist">
            <button role="tab" aria-selected="true" aria-controls="p-estado" id="b-estado">Estado financiero</button>
            <button role="tab" aria-selected="false" aria-controls="p-historico" id="b-historico" tabindex="-1">Histórico de pagos</button>
        </div>

        <div role="tabpanel" id="p-estado" aria-labelledby="b-estado">
            <?php if (!$vigencias): ?>
                <div class="vacio vacio-chico"><?= Vista::icono('chequeo') ?><h3>Sin deudas registradas</h3><p>No hay vigencias pendientes de pago para este predio.</p></div>
            <?php else: ?>
                <p class="texto-tenue tabla-ayuda">Marca las vigencias que vas a pagar. Si dejas un año en medio, se incluye: se pagan años seguidos.</p>
                <div class="tabla-caja">
                    <table class="tabla" data-vigencias>
                        <thead>
                            <tr>
                                <th scope="col" class="col-casilla"><input type="checkbox" aria-label="Seleccionar todas" data-todas <?= $pagables ? 'checked' : 'disabled' ?>></th>
                                <th scope="col">Estado</th>
                                <th scope="col">Vigencia</th>
                                <th scope="col" class="num">Avalúo</th>
                                <th scope="col" class="num">Impuesto</th>
                                <th scope="col" class="num">Intereses</th>
                                <th scope="col" class="num">Sobretasa ambiental</th>
                                <th scope="col" class="num">Descuentos</th>
                                <th scope="col" class="num">Otros</th>
                                <th scope="col" class="num">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vigencias as $v): ?>
                            <tr data-anio="<?= $v['anio'] ?>" data-total-fila="<?= (int) round($v['total']) ?>" class="<?= $v['pagable'] ? 'seleccionada' : 'no-pagable' ?>">
                                <td class="col-casilla">
                                    <?php if ($v['pagable']): ?>
                                        <input type="checkbox" checked aria-label="Pagar vigencia <?= $v['anio'] ?>" data-casilla>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($v['estado'] === 'acuerdo'): ?><span class="chip chip-info">En acuerdo</span>
                                    <?php elseif ($v['estado'] === 'prescrita'): ?><span class="chip chip-gris">Prescrita</span>
                                    <?php else: ?><span class="chip chip-pendiente">Por pagar</span><?php endif; ?>
                                </td>
                                <td class="vigencia"><?= $v['anio'] ?></td>
                                <td class="num"><?= pesos($v['avaluo']) ?></td>
                                <td class="num" data-campo="impuesto"><?= pesos($v['impuesto']) ?></td>
                                <td class="num" data-campo="intereses"><?= pesos($v['intereses']) ?></td>
                                <td class="num" data-campo="sobretasa"><?= pesos($v['sobretasa']) ?></td>
                                <td class="num descuento" data-campo="descuento"><?= pesos($v['descuento']) ?></td>
                                <td class="num" data-campo="otros"><?= pesos($v['otros']) ?></td>
                                <td class="num total-fila" data-campo="total"><?= pesos($v['total']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($pagables): ?>
                        <tfoot>
                            <tr><td colspan="9" class="num">Total seleccionado</td><td class="num total-fila" data-total-pie><?= pesos($totalTodo) ?></td></tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div role="tabpanel" id="p-historico" aria-labelledby="b-historico" hidden>
            <?php if (!$pagos): ?>
                <div class="vacio vacio-chico"><?= Vista::icono('documento') ?><h3>Sin pagos registrados</h3><p>Aquí aparecen los pagos del predio cuando el banco los reporta.</p></div>
            <?php else: ?>
                <div class="tabla-caja">
                    <table class="tabla">
                        <thead><tr><th scope="col">Fecha de pago</th><th scope="col">Factura</th><th scope="col">Vigencia</th><th scope="col">Banco</th><th scope="col" class="num">Valor pagado</th></tr></thead>
                        <tbody>
                            <?php foreach ($pagos as $pg): ?>
                            <tr>
                                <td><?= e(fechaCorta($pg['fecha_pago'])) ?></td>
                                <td><?= e($pg['numero_recibo']) ?></td>
                                <td><?= e($pg['anio_pago']) ?></td>
                                <td><?= e($pg['banco'] ?: '—') ?></td>
                                <td class="num"><?= pesos($pg['valor_facturado']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php
Vista::cerrar();
