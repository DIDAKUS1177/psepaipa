<?php
/**
 * Primero los predios a nombre de quien entró; debajo, el buscador para pagar
 * el predio de otra persona por su referencia catastral.
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';

$u = exigirSesion();

$ref = trim((string) ($_GET['ref'] ?? ''));
$resultados = null;
$errorBusqueda = null;
$errorDatos = null;

if ($ref !== '') {
    if (strlen(Predios::normalizarReferencia($ref)) < 8) {
        $errorBusqueda = 'Escribe la referencia catastral completa (solo cuentan los números).';
    } else {
        try {
            $resultados = Predios::buscar($ref);
        } catch (Throwable $t) {
            error_log('[psepaipa] buscar: ' . $t->getMessage());
            $errorBusqueda = 'No pudimos buscar en este momento. Intenta de nuevo en unos minutos.';
        }
        if ($resultados !== null && count($resultados) === 1) {
            redirigir('predio.php?id=' . (int) $resultados[0]['id']);
        }
        if ($resultados === []) {
            $errorBusqueda = 'No encontramos un predio con la referencia ' . Predios::normalizarReferencia($ref)
                . '. Revisa el número en tu factura anterior o en el certificado catastral (puede ser la referencia nueva o la anterior).';
        }
    }
}

try {
    $mios = Predios::delDocumento($u['documento']);
} catch (Throwable $t) {
    error_log('[psepaipa] mis predios: ' . $t->getMessage());
    $mios = [];
    $errorDatos = 'No pudimos cargar tus predios en este momento. Intenta de nuevo en unos minutos.';
}

Vista::abrir('Mis predios', ['titulo' => 'Impuesto Predial Unificado', 'volver' => null]);
?>
<div class="contenedor pagina">
    <section class="saludo">
        <div>
            <p class="ceja ceja-oscura">Hola, <?= e(mb_convert_case(mb_strtolower($u['nombres']), MB_CASE_TITLE)) ?></p>
            <h2 class="titulo-seccion">Tus predios</h2>
            <p class="texto-tenue">Predios registrados a nombre del documento <?= e($u['documento']) ?>, como propietario o copropietario.</p>
        </div>
    </section>

    <?php if ($errorDatos): ?>
        <div class="aviso aviso-error" role="alert"><?= Vista::icono('alerta') ?><span><?= e($errorDatos) ?></span></div>
    <?php elseif (!$mios): ?>
        <div class="vacio">
            <?= Vista::icono('predio') ?>
            <h3>No hay predios a tu nombre</h3>
            <p>Si eres propietario y no lo ves aquí, el predio puede estar registrado con otro documento. Comunícate con la Dirección de Impuestos. Mientras tanto puedes pagarlo con su referencia catastral.</p>
            <a class="boton boton-primario" href="#buscar"><?= Vista::icono('buscar') ?>Buscar por referencia catastral</a>
        </div>
    <?php else: ?>
        <ul class="predios">
            <?php foreach ($mios as $p):
                $debe = (int) $p['vigencias_debe'];
            ?>
            <li class="predio-tarjeta <?= $debe > 0 ? 'con-deuda' : 'al-dia' ?>">
                <a href="predio.php?id=<?= (int) $p['id'] ?>">
                    <div class="predio-cabeza">
                        <span class="chip <?= $debe > 0 ? 'chip-pendiente' : 'chip-ok' ?>"><?= $debe > 0 ? 'Por pagar' : 'Al día' ?></span>
                        <span class="predio-zona"><?= e($p['zona'] ?: 'Predio') ?></span>
                    </div>
                    <p class="etiqueta">Referencia catastral</p>
                    <p class="referencia"><?= e($p['codigo_predio']) ?></p>
                    <p class="predio-direccion"><?= Vista::icono('direccion') ?><?= e($p['direccion'] ?: 'Sin dirección registrada') ?></p>
                    <div class="predio-pie">
                        <?php if ($debe > 0): ?>
                            <div>
                                <p class="etiqueta">Debe <?= $debe ?> vigencia<?= $debe === 1 ? '' : 's' ?> <?= $p['debe_desde'] ? '(desde ' . (int) $p['debe_desde'] . ')' : '' ?></p>
                                <p class="monto"><?= pesos($p['total_debe']) ?></p>
                            </div>
                        <?php else: ?>
                            <div>
                                <p class="etiqueta">Último año pagado</p>
                                <p class="monto"><?= e($p['ultimo_anio_pago'] ?: '—') ?></p>
                            </div>
                        <?php endif; ?>
                        <span class="predio-ir"><?= $debe > 0 ? 'Ver y pagar' : 'Ver detalle' ?><?= Vista::icono('ir') ?></span>
                    </div>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="nota-cifras"><?= Vista::icono('reloj') ?>El valor de cada tarjeta es el último calculado. Al abrir el predio se actualiza con los intereses de hoy.</p>
    <?php endif; ?>

    <section class="buscador" id="buscar">
        <div class="buscador-texto">
            <h2 class="titulo-seccion">Pagar el predio de otra persona</h2>
            <p class="texto-tenue">Escribe la referencia catastral (la nueva o la anterior). La encuentras en la factura del predial o en el certificado catastral.</p>
        </div>
        <form method="get" action="inicio.php#buscar" class="buscador-form" role="search">
            <label class="oculto" for="ref">Referencia catastral</label>
            <input id="ref" name="ref" inputmode="numeric" placeholder="Ej. 0100000000450015000000000" value="<?= e($ref) ?>" maxlength="40"<?= $errorBusqueda ? ' aria-invalid="true" aria-describedby="error-busqueda"' : '' ?>>
            <button class="boton boton-primario" type="submit"><?= Vista::icono('buscar') ?>Buscar</button>
        </form>
        <?php if ($errorBusqueda): ?>
            <p class="campo-error" id="error-busqueda" role="alert"><?= e($errorBusqueda) ?></p>
        <?php elseif ($resultados && count($resultados) > 1): ?>
            <ul class="resultados">
                <?php foreach ($resultados as $r): ?>
                    <li><a href="predio.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['codigo_predio']) ?></strong><span><?= e($r['direccion']) ?></span><?= Vista::icono('ir') ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
<?php
Vista::cerrar();
