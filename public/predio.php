<?php
/**
 * Un predio, con la estructura del portal de Duitama: información del predio y
 * valor a pagar arriba; pestañas de estado de cuenta (con casillas para
 * elegir los años), recibos emitidos e histórico de pagos.
 *
 * Las cifras son una COTIZACIÓN del procedimiento oficial con la fecha de hoy
 * que no se guarda (Predios::cotizar). A quien no es propietario se le ocultan
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

try {
    if (!Predios::permitido($id, $u['documento'])) {
        aviso('Para pagar el predio de otra persona, búscalo por su referencia catastral en «Otros predios».', 'info');
        redirigir('inicio.php?vista=otros');
    }
    $propio = Predios::esPropietario($id, $u['documento']);
    $cotizacion = Predios::cotizar($id, null, null, true);
} catch (Throwable $t) {
    error_log('[psepaipa] predio ' . $id . ': ' . $t->getMessage());
    aviso('No pudimos calcular el estado de cuenta de este predio en este momento. Intenta de nuevo en unos minutos.', 'error');
    redirigir('inicio.php');
}
$alDia = $cotizacion['ok'];
$vigencias = $cotizacion['filas'];
$pagos = $recibos = [];
try {
    $pagos = Predios::historicoPagos($id);
} catch (Throwable $t) {
    error_log('[psepaipa] histórico de pagos: ' . $t->getMessage());
}
try {
    $recibos = Predios::recibosEmitidos($id);
} catch (Throwable $t) {
    error_log('[psepaipa] recibos emitidos: ' . $t->getMessage());
}

$pagoEnLinea = AvalPay::disponiblePara($u);
$tramite = null;
try {
    PagoLinea::refrescar($id);
    $tramite = PagoLinea::enTramite($id);
    $conciliar = PagoLinea::porConciliar($id);
} catch (Throwable $t) {
    error_log('[psepaipa] pago en trámite: ' . $t->getMessage());
}
$conciliar ??= null;
// Con un pago aprobado en revisión no se ofrece otro pago en línea.
$pagoEnLinea = $pagoEnLinea && $conciliar === null;

$pagables = array_values(array_filter($vigencias, fn($v) => $v['pagable']));
// Con un pago en curso no hay nada que elegir (ni formulario de pago).
$seleccionable = $pagables && $tramite === null;
$motivoPaz = 'No pudimos revisar el estado del predio.';
if (!$pagables && $tramite === null && $alDia) {
    // Solo con el cálculo de hoy hecho ($alDia = cotización correcta): con
    // cifras guardadas un año sin calcular podía verse "al día".
    try {
        $motivoPaz = PazYSalvo::motivo($id);
    } catch (Throwable $t) {
        error_log('[psepaipa] paz y salvo: ' . $t->getMessage());
    }
}
$enAcuerdo = array_values(array_filter($vigencias, fn($v) => $v['estado'] === 'acuerdo'));
// El valor a pagar es el del cupón oficial del rango, no la suma de filas: el
// procedimiento aplica descuentos según los años que se pagan juntos.
$totalTodo = $cotizacion['total'];
$desglose = $cotizacion['desglose'];
// Los datos del propietario van ocultos para TODOS: la cuenta no prueba que
// quien se inscribió con un documento sea su dueño (no hay verificación).
$principal = $p['propietarios'][0] ?? null;
$nombreDueno = $principal ? Predios::enmascararNombre((string) $principal['nombre']) : '';
$docDueno = $principal ? Predios::enmascararDocumento((string) $principal['identificacion']) : '';

// Bloques de vigencias seguidas: un pago no puede saltar un año que no está
// pendiente. Al abrir queda marcado el bloque que se cotizó.
$bloque = 0;
$anterior = null;
foreach ($vigencias as $k => $v) {
    if (!$v['pagable']) {
        continue;
    }
    if ($anterior !== null && $v['anio'] !== $anterior + 1) {
        $bloque++;
    }
    $vigencias[$k]['bloque'] = $bloque;
    $vigencias[$k]['marcada'] = $cotizacion['desde'] !== null && $v['anio'] >= $cotizacion['desde'] && $v['anio'] <= $cotizacion['hastaAnio'];
    $anterior = $v['anio'];
}
$hayBloques = $bloque > 0;
$pagables = array_values(array_filter($vigencias, fn($v) => $v['pagable']));
$marcadas = array_values(array_filter($pagables, fn($v) => $v['marcada']));
$primero = $marcadas[0]['anio'] ?? null;
$ultimo = $marcadas ? end($marcadas)['anio'] : null;
$rango = $primero === null ? '' : ($primero === $ultimo ? (string) $primero : $primero . ' – ' . $ultimo);
$etiquetasDesglose = [
    'impuesto'  => 'Impuesto predial',
    'intereses' => 'Intereses de mora',
    'sobretasa' => 'Sobretasa ambiental',
    'descuento' => 'Descuentos',
    'otros'     => 'Otros conceptos',
];

Vista::abrir('Predio ' . $p['codigo_predio'], ['titulo' => 'Impuesto Predial Unificado', 'volver' => $propio ? 'inicio.php' : 'inicio.php?vista=otros'], '', $propio ? 'predios' : 'otro');
?>
<div class="contenedor pagina">
    <?php if (!$alDia): ?>
        <div class="aviso aviso-alerta" role="status"><?= Vista::icono('alerta') ?><span>No pudimos calcular los intereses de hoy. Las cifras son las del último cálculo; la factura se recalcula al generarla.</span></div>
    <?php endif; ?>


    <div class="predio-rejilla">
        <section class="tarjeta info-predio" aria-labelledby="t-info">
            <div class="tarjeta-cabeza">
                <h2 id="t-info" class="tarjeta-titulo">Información del predio</h2>
                <?php if ($propio): ?>
                    <span class="chip chip-ok"><?= Vista::icono('chequeo') ?>Tu predio</span>
                <?php else: ?>
                    <span class="chip chip-info" title="Por privacidad, los datos del propietario aparecen ocultos"><?= Vista::icono('ojo') ?>Predio de otra persona</span>
                <?php endif; ?>
            </div>
            <div class="info-referencia">
                <p class="etiqueta">Referencia catastral</p>
                <p class="referencia referencia-grande"><?= e($p['codigo_predio']) ?></p>
            </div>
            <dl class="datos">
                <div><dt>Propietario</dt><dd><?= e($nombreDueno ?: '—') ?></dd></div>
                <div><dt>No. de identificación</dt><dd><?= e($docDueno ?: '—') ?></dd></div>
                <div><dt>Dirección del predio</dt><dd><?= e($p['direccion'] ?: '—') ?></dd></div>
                <div><dt>Matrícula inmobiliaria</dt><dd><?= e($p['matricula'] ?: '—') ?></dd></div>
            </dl>
            <ul class="datos-clave">
                <li><span>Avalúo vigente</span><strong><?= pesos($p['avaluo']) ?></strong></li>
                <li><span>Zona</span><strong><?= e($p['zona'] ?: '—') ?></strong></li>
                <li><span>Último año pagado</span><strong><?= e($p['ultimo_anio_pago'] ?: '—') ?></strong></li>
            </ul>
            <details class="mas-info">
                <summary>Ver información detallada<?= Vista::icono('abajo') ?></summary>
                <dl class="datos">
                    <div><dt>Referencia anterior</dt><dd><?= e($p['codigo_predio_anterior'] ?: '—') ?></dd></div>
                    <div><dt>Tarifa</dt><dd><?= e(rtrim(rtrim(number_format((float) $p['tarifa_actual'], 2, ',', '.'), '0'), ',')) ?> por mil</dd></div>
                    <div><dt>Área del terreno</dt><dd><?= e(number_format((float) $p['area_metros'], 0, ',', '.')) ?> m²<?= (float) $p['area_hectareas'] > 0 ? ' · ' . e(number_format((float) $p['area_hectareas'], 2, ',', '.')) . ' ha' : '' ?></dd></div>
                    <div><dt>Área construida</dt><dd><?= e(number_format((float) $p['area_construida'], 0, ',', '.')) ?> m²</dd></div>
                    <?php if (count($p['propietarios']) > 1): ?>
                    <div class="datos-ancho"><dt>Copropietarios</dt><dd>
                        <?php foreach (array_slice($p['propietarios'], 1) as $co): ?>
                            <span class="copropietario"><?= e(Predios::enmascararNombre((string) $co['nombre'])) ?></span>
                        <?php endforeach; ?>
                    </dd></div>
                    <?php endif; ?>
                </dl>
            </details>
        </section>

        <aside class="tarjeta valor-pagar" aria-labelledby="t-valor">
            <p class="oculto" role="status" data-anuncio></p>
            <h2 id="t-valor" class="tarjeta-titulo">Valor a pagar</h2>
            <?php if ($conciliar !== null && $tramite === null): ?>
                <div class="aviso aviso-alerta aviso-conciliar" role="status"><?= Vista::icono('alerta') ?><span>El <?= e(fechaCorta($conciliar['created_at'])) ?> el banco aprobó un pago en línea de <?= pesos($conciliar['total']) ?> (factura <?= e(trim((string) $conciliar['factura'])) ?>) que la Alcaldía está revisando. Antes de volver a pagar, comunícate con la Dirección de Impuestos.</span></div>
            <?php endif; ?>
            <?php if ($tramite !== null): ?>
                <div class="pago-curso">
                    <?= Vista::icono('reloj') ?>
                    <p class="al-dia-titulo">Pago en línea en curso</p>
                    <p>La factura <?= e(trim((string) $tramite['factura'])) ?> (<?= e($tramite['Impuesto']) ?>, <span class="sin-cortar"><?= pesos($tramite['total']) ?></span>) está en proceso con el banco. Cuando se confirme, se verá aquí; mientras tanto no se puede generar otra factura de este predio.</p>
                    <a class="boton boton-contorno" href="<?= e(PagoLinea::urlRetorno((int) $tramite['IdGeneraPado'])) ?>"><?= Vista::icono('reloj') ?>Ver estado del pago</a>
                </div>
            <?php elseif ($pagables): ?>
                <p class="valor-vigencias" data-vigencias-texto>Pagar vigencia<span data-plural <?= $primero === $ultimo ? 'hidden' : '' ?>>s</span> <strong data-rango><?= e($rango) ?></strong></p>
                <div class="valor-bloque">
                    <div>
                        <p class="etiqueta">Fecha límite de pago</p>
                        <p class="valor-fecha-grande" data-fecha><?= e(fechaCorta($cotizacion['hasta'])) ?></p>
                    </div>
                    <div class="valor-bloque-total">
                        <p class="etiqueta">Total a pagar</p>
                        <p class="valor-total" data-total><?= pesos($totalTodo) ?></p>
                    </div>
                </div>

                <details class="desglose">
                    <summary>¿Qué estoy pagando?<?= Vista::icono('abajo') ?></summary>
                    <dl>
                        <?php foreach ($etiquetasDesglose as $clave => $texto): ?>
                            <div class="<?= $clave === 'descuento' ? 'desglose-descuento' : '' ?>"><dt><?= e($texto) ?></dt><dd data-desglose="<?= $clave ?>"><?= pesos($desglose[$clave]) ?></dd></div>
                        <?php endforeach; ?>
                        <div class="desglose-total"><dt>Total</dt><dd data-total-desglose><?= pesos($totalTodo) ?></dd></div>
                    </dl>
                </details>

                <form method="post" action="factura.php" target="_blank" class="valor-acciones" data-form-pago data-predio="<?= (int) $id ?>">
                    <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <input type="hidden" name="desde" value="<?= (int) $primero ?>" data-desde>
                    <input type="hidden" name="hasta" value="<?= (int) $ultimo ?>" data-hasta>

                    <div class="menu-boton" data-menu>
                        <button type="button" class="boton boton-contorno" aria-haspopup="true" aria-expanded="false" data-menu-boton data-requiere-cotizacion><?= Vista::icono('imprimir') ?>Imprimir<?= Vista::icono('abajo', 'flecha') ?></button>
                        <div class="menu-lista" role="menu" hidden>
                            <button type="submit" role="menuitem" formaction="factura.php" data-requiere-cotizacion>
                                <?= Vista::icono('banco') ?><span><strong>Factura para pagar en banco</strong><small>Con código de barras. Asigna número de factura.</small></span>
                            </button>
                            <button type="submit" role="menuitem" formaction="estado-cuenta.php" data-requiere-cotizacion>
                                <?= Vista::icono('documento') ?><span><strong>Estado de cuenta</strong><small>Informativo, no sirve para pagar.</small></span>
                            </button>
                        </div>
                    </div>
                    <div class="menu-boton" data-menu>
                        <button type="button" class="boton boton-primario" aria-haspopup="true" aria-expanded="false" data-menu-boton data-requiere-cotizacion><?= Vista::icono('pagar') ?>Pagar<?= Vista::icono('abajo', 'flecha') ?></button>
                        <div class="menu-lista menu-derecha" role="menu" hidden>
                            <?php if ($pagoEnLinea): ?>
                            <button type="submit" role="menuitem" formaction="pagar.php" formtarget="_self" data-requiere-cotizacion data-pago-linea>
                                <?= Vista::icono('pagar') ?><span><strong>Pagar en línea (PSE)</strong><small>Débito desde tu cuenta bancaria.</small></span>
                            </button>
                            <?php else: ?>
                            <button type="button" role="menuitem" disabled>
                                <?= Vista::icono('pagar') ?><span><strong>Pagar en línea (PSE)</strong><small>Muy pronto en este portal.</small></span>
                            </button>
                            <?php endif; ?>
                            <button type="submit" role="menuitem" formaction="factura.php" data-requiere-cotizacion>
                                <?= Vista::icono('banco') ?><span><strong>Pagar en banco</strong><small>Descarga la factura con código de barras.</small></span>
                            </button>
                        </div>
                    </div>
                </form>
                <p class="valor-nota"><?= Vista::icono('info') ?><span>La factura se abre en una pestaña nueva. Págala hasta la fecha límite; después genera otra aquí, sin costo.</span></p>
            <?php else: ?>
                <div class="al-dia-caja">
                    <?= Vista::icono('escudo') ?>
                    <p class="al-dia-titulo">Este predio está al día</p>
                    <?php if ($enAcuerdo): ?>
                        <p>Tiene <?= count($enAcuerdo) ?> vigencia<?= count($enAcuerdo) === 1 ? '' : 's' ?> en acuerdo de pago, que se paga<?= count($enAcuerdo) === 1 ? '' : 'n' ?> con las cuotas del acuerdo en la Alcaldía.</p>
                    <?php elseif ($motivoPaz === null): ?>
                        <p>No tiene vigencias pendientes de pago. Descarga aquí el certificado de paz y salvo.</p>
                    <?php else: ?>
                        <p>No tiene vigencias pendientes de pago en línea. <?= e($motivoPaz) ?></p>
                    <?php endif; ?>
                </div>
                <?php if (!$enAcuerdo && $motivoPaz === null): ?>
                <form method="post" action="paz-y-salvo.php" target="_blank" class="paz-form" id="form-paz" data-form-paz>
                    <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <label class="campo">
                        <span>Con destino a <small>(opcional)</small></span>
                        <input type="text" name="destino" maxlength="80" placeholder="A quien interese" autocomplete="off">
                    </label>
                    <button type="submit" class="boton boton-primario boton-ancho"><?= Vista::icono('documento') ?>Descargar paz y salvo</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </aside>
    </div>

    <section class="tarjeta pestanas-detalle" data-pestanas aria-label="Detalle del predio">
        <div class="pestanas pestanas-linea" role="tablist">
            <button role="tab" aria-selected="true" aria-controls="p-estado" id="b-estado"><?= Vista::icono('lista') ?>Estado de cuenta</button>
            <button role="tab" aria-selected="false" aria-controls="p-recibos" id="b-recibos" tabindex="-1"><?= Vista::icono('documento') ?>Recibos emitidos<?= $recibos ? ' <span class="contador">' . count($recibos) . '</span>' : '' ?></button>
            <button role="tab" aria-selected="false" aria-controls="p-historico" id="b-historico" tabindex="-1"><?= Vista::icono('reloj') ?>Histórico de pagos</button>
        </div>

        <div role="tabpanel" id="p-estado" aria-labelledby="b-estado" tabindex="0">
            <?php if (!$vigencias): ?>
                <div class="vacio vacio-chico"><?= Vista::icono('chequeo') ?><h3>Sin saldos pendientes</h3><p>Este predio no tiene vigencias por pagar.</p>
                    <?php if (!$enAcuerdo && $motivoPaz === null): ?>
                        <p class="vacio-accion"><button type="submit" form="form-paz" class="boton boton-primario"><?= Vista::icono('documento') ?>Descargar paz y salvo</button></p>
                    <?php elseif (!$enAcuerdo && $tramite === null): ?>
                        <p class="texto-tenue"><?= e($motivoPaz) ?></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="tabla-ayuda">
                    <?php if ($tramite !== null): ?>
                    <p><?= Vista::icono('reloj') ?><span>Hay un pago en línea en curso para este predio. Cuando el banco lo confirme, las vigencias pagadas saldrán de esta lista.</span></p>
                    <?php else: ?>
                    <p><?= Vista::icono('info') ?><span>Marca las vigencias que vas a pagar. Se pagan años seguidos: si dejas uno en medio, se incluye. El total se recalcula con cada cambio.<?= $hayBloques ? ' Este predio tiene vigencias no seguidas: se pagan por grupos, en facturas separadas.' : '' ?></span></p>
                    <?php endif; ?>
                    <?php if ($seleccionable): ?>
                    <p class="tabla-seleccion" data-resumen-seleccion><?= count($marcadas) ?> de <?= count($pagables) ?> vigencias seleccionadas</p>
                    <?php endif; ?>
                </div>
                <div class="tabla-caja">
                    <table class="tabla tabla-vigencias" data-vigencias>
                        <caption class="oculto">Estado de cuenta por vigencia, en pesos</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="col-casilla"><?php if (!$hayBloques && $tramite === null): ?><input type="checkbox" aria-label="Seleccionar todas las vigencias" data-todas <?= $pagables ? 'checked' : 'disabled' ?>><?php endif; ?></th>
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
                            <tr data-anio="<?= $v['anio'] ?>" data-bloque="<?= (int) ($v['bloque'] ?? -1) ?>" class="<?= $v['pagable'] ? (!empty($v['marcada']) ? 'seleccionada' : '') : 'no-pagable' ?>">
                                <td class="col-casilla">
                                    <?php if ($v['pagable'] && $seleccionable): ?>
                                        <input type="checkbox" <?= !empty($v['marcada']) ? 'checked' : '' ?> aria-label="Pagar la vigencia <?= $v['anio'] ?>" data-casilla>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($v['estado'] === 'acuerdo'): ?><span class="chip chip-info">En acuerdo</span>
                                    <?php elseif ($v['estado'] === 'prescrita'): ?><span class="chip chip-gris">Prescrita</span>
                                    <?php else: ?><span class="chip chip-pendiente">Por pagar</span><?php endif; ?>
                                </td>
                                <th scope="row" class="vigencia"><?= $v['anio'] ?></th>
                                <td class="num" data-label="Avalúo"><?= pesos($v['avaluo']) ?></td>
                                <td class="num" data-label="Impuesto" data-campo="impuesto"><?= pesos($v['impuesto']) ?></td>
                                <td class="num" data-label="Intereses" data-campo="intereses"><?= pesos($v['intereses']) ?></td>
                                <td class="num" data-label="Sobretasa amb." data-campo="sobretasa"><?= pesos($v['sobretasa']) ?></td>
                                <td class="num descuento" data-label="Descuentos" data-campo="descuento"><?= pesos($v['descuento']) ?></td>
                                <td class="num" data-label="Otros" data-campo="otros"><?= pesos($v['otros']) ?></td>
                                <td class="num total-fila celda-total" data-label="Total" data-campo="total"><?= pesos($v['total']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($seleccionable): ?>
                        <tfoot>
                            <tr><th scope="row" colspan="9" class="num">Total a pagar por las vigencias seleccionadas</th><td class="num total-fila" data-total-pie><?= pesos($totalTodo) ?></td></tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <details class="glosario">
                    <summary><?= Vista::icono('pregunta') ?>¿Qué significa cada columna?<?= Vista::icono('abajo') ?></summary>
                    <dl>
                        <div><dt>Avalúo</dt><dd>Valor catastral del predio en ese año. Sobre él se liquida el impuesto.</dd></div>
                        <div><dt>Impuesto</dt><dd>Impuesto predial del año: avalúo por la tarifa (por mil) que fija el Concejo.</dd></div>
                        <div><dt>Intereses</dt><dd>Intereses de mora por pagar después de la fecha límite, calculados al día de hoy.</dd></div>
                        <div><dt>Sobretasa ambiental</dt><dd>Porcentaje que la ley ordena cobrar con el predial para la autoridad ambiental (Ley 99 de 1993).</dd></div>
                        <div><dt>Descuentos</dt><dd>Descuentos por pronto pago u otros beneficios vigentes. Restan del total.</dd></div>
                        <div><dt>Otros</dt><dd>Los demás conceptos que el municipio liquida junto con el predial.</dd></div>
                        <div><dt>Total</dt><dd>Lo que se debe de ese año hoy. El total a pagar puede variar según los años que pagues juntos.</dd></div>
                    </dl>
                </details>
            <?php endif; ?>
        </div>

        <div role="tabpanel" id="p-recibos" aria-labelledby="b-recibos" tabindex="0" hidden>
            <?php if (!$recibos): ?>
                <div class="vacio vacio-chico"><?= Vista::icono('documento') ?><h3>Sin recibos emitidos</h3><p>Cuando generes una factura de este predio, aparecerá aquí.</p></div>
            <?php else: ?>
                <p class="tabla-ayuda-texto">Facturas generadas para este predio, en la Alcaldía o en línea. Una factura vencida no se paga: genera una nueva con el valor de hoy.</p>
                <div class="tabla-caja">
                    <table class="tabla tabla-compacta tabla-responsiva">
                        <thead><tr><th scope="col">No. factura</th><th scope="col">Vigencias</th><th scope="col">Emitida</th><th scope="col">Estado</th><th scope="col" class="num">Valor</th></tr></thead>
                        <tbody>
                            <?php foreach ($recibos as $r):
                                $pagada = (int) $r['todo_pagado'] === 1; ?>
                            <tr>
                                <th scope="row" class="mono" data-label="No. factura"><?= e($r['numero']) ?></th>
                                <td data-label="Vigencias"><?= (int) $r['desde'] === (int) $r['hasta'] ? (int) $r['desde'] : (int) $r['desde'] . ' – ' . (int) $r['hasta'] ?></td>
                                <td data-label="Emitida"><?= e(fechaCorta($r['emision'])) ?></td>
                                <td data-label="Estado"><?= $pagada ? '<span class="chip chip-ok">Pagada</span>' : '<span class="chip chip-gris">Sin pagar</span>' ?></td>
                                <td class="num" data-label="Valor"><?= pesos($pagada && (float) $r['pagado'] > 0 ? $r['pagado'] : $r['valor']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div role="tabpanel" id="p-historico" aria-labelledby="b-historico" tabindex="0" hidden>
            <?php if (!$pagos): ?>
                <div class="vacio vacio-chico"><?= Vista::icono('reloj') ?><h3>Sin pagos registrados</h3><p>Aquí aparecen los pagos del predio cuando el banco los reporta.</p></div>
            <?php else: ?>
                <div class="tabla-caja">
                    <table class="tabla tabla-compacta tabla-responsiva">
                        <thead><tr><th scope="col">Fecha de pago</th><th scope="col">Factura</th><th scope="col">Vigencia</th><th scope="col">Banco</th><th scope="col" class="num">Valor pagado</th></tr></thead>
                        <tbody>
                            <?php foreach ($pagos as $pg): ?>
                            <tr>
                                <td data-label="Fecha de pago"><?= e(fechaCorta($pg['fecha_pago'])) ?></td>
                                <td class="mono" data-label="Factura"><?= e($pg['numero_recibo']) ?></td>
                                <td data-label="Vigencia"><?= e($pg['anio_pago']) ?></td>
                                <td data-label="Banco"><?= e($pg['banco'] ?: '—') ?></td>
                                <td class="num" data-label="Valor pagado"><?= pesos($pg['valor_facturado']) ?></td>
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
