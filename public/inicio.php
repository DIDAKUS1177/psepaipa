<?php
/**
 * Dos pestañas: "Mis predios" (los que están a nombre de quien entró) y
 * "Otros predios" (buscar el predio de otra persona por su referencia
 * catastral y pagarlo). La pestaña va en la URL (?vista=otros) para que
 * "Regresar" y el menú lleguen a la correcta.
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';

$u = exigirSesion();

$ref = trim((string) ($_GET['ref'] ?? ''));
$vista = ($_GET['vista'] ?? '') === 'otros' || $ref !== '' ? 'otros' : 'mios';
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
        if ($resultados === []) {
            $errorBusqueda = 'No encontramos un predio con la referencia ' . Predios::normalizarReferencia($ref)
                . '. Revisa el número en una factura anterior o en el certificado catastral (puede ser la referencia nueva o la anterior).';
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
$idsMios = array_map(fn($p) => (int) $p['id'], $mios);
foreach ($idsMios as $idPropio) {
    Predios::permitir($idPropio);
}
foreach ($resultados ?? [] as $r) {
    Predios::permitir((int) $r['id']);
}

$conDeuda = array_values(array_filter($mios, fn($p) => (int) $p['vigencias_debe'] > 0));
$totalDeuda = array_sum(array_map(fn($p) => (float) $p['total_debe'], $conDeuda));

/** Tarjeta de un predio (la misma para los propios y los de otros). */
function tarjetaPredio(array $p, bool $ajeno): void
{
    $debe = (int) $p['vigencias_debe'];
    ?>
    <li class="predio-tarjeta <?= $debe > 0 ? 'con-deuda' : 'al-dia' ?>" data-texto="<?= e(mb_strtolower($p['codigo_predio'] . ' ' . $p['codigo_predio_anterior'] . ' ' . $p['direccion'], 'UTF-8')) ?>">
        <a href="predio.php?id=<?= (int) $p['id'] ?>">
            <div class="fp-estado">
                <span class="chip <?= $debe > 0 ? 'chip-pendiente' : 'chip-ok' ?>"><?= $debe > 0 ? 'Por pagar' : 'Al día' ?></span>
                <span class="predio-zona"><?= e($p['zona'] ?: 'Predio') ?></span>
            </div>
            <div class="fp-ref">
                <p class="etiqueta">Referencia catastral</p>
                <p class="referencia"><?= e($p['codigo_predio']) ?></p>
            </div>
            <div class="fp-dir">
                <?php if ($ajeno && !empty($p['propietario'])): ?>
                    <p class="predio-direccion"><?= Vista::icono('usuario') ?><?= e(Predios::enmascararNombre((string) $p['propietario'])) ?></p>
                <?php endif; ?>
                <p class="predio-direccion"><?= Vista::icono('direccion') ?><?= e($p['direccion'] ?: 'Sin dirección registrada') ?></p>
            </div>
            <div class="fp-deuda">
                <?php if ($debe > 0): ?>
                    <p class="etiqueta"><?= $debe === 1 ? 'Debe la vigencia ' . (int) $p['debe_desde'] : 'Debe ' . $debe . ' vigencias · desde ' . (int) $p['debe_desde'] ?></p>
                    <p class="monto"><?= pesos($p['total_debe']) ?></p>
                <?php else: ?>
                    <p class="etiqueta">Último año pagado</p>
                    <p class="monto"><?= e($p['ultimo_anio_pago'] ?: '—') ?></p>
                <?php endif; ?>
            </div>
            <span class="predio-ir"><?= $debe > 0 ? 'Ver y pagar' : 'Ver detalle' ?><?= Vista::icono('ir') ?></span>
        </a>
    </li>
    <?php
}

Vista::abrir($vista === 'otros' ? 'Otros predios' : 'Mis predios', ['titulo' => 'Impuesto Predial Unificado', 'volver' => null], '', $vista === 'otros' ? 'otro' : 'predios');
?>
<div class="contenedor pagina">
    <?php if ($vista === 'mios'): ?>
    <section class="saludo">
        <div class="saludo-texto">
            <p class="ceja ceja-oscura">Hola, <?= e(mb_convert_case(mb_strtolower($u['nombres']), MB_CASE_TITLE)) ?></p>
            <h2 class="titulo-seccion">Tus predios</h2>
            <p class="texto-tenue">Paga los predios a tu nombre o, en «Otros predios», el de cualquier persona con su referencia catastral.</p>
        </div>
        <?php if ($mios): ?>
        <dl class="resumen" aria-label="Resumen de tus predios">
            <div class="resumen-item">
                <dt>Predios</dt>
                <dd><?= count($mios) ?></dd>
            </div>
            <div class="resumen-item <?= $conDeuda ? 'resumen-alerta' : 'resumen-ok' ?>">
                <dt>Con saldo pendiente</dt>
                <dd><?= count($conDeuda) ?></dd>
            </div>
            <div class="resumen-item resumen-total">
                <dt>Total pendiente <span class="resumen-nota">(último cálculo)</span></dt>
                <dd><?= pesos($totalDeuda) ?></dd>
            </div>
        </dl>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="tarjeta pestanas-inicio">
        <nav class="pestanas pestanas-linea" aria-label="Predios">
            <a href="inicio.php" class="<?= $vista === 'mios' ? 'activa' : '' ?>" <?= $vista === 'mios' ? 'aria-current="page"' : '' ?>><?= Vista::icono('predio') ?>Mis predios <span class="contador"><?= count($mios) ?></span></a>
            <a href="inicio.php?vista=otros" class="<?= $vista === 'otros' ? 'activa' : '' ?>" <?= $vista === 'otros' ? 'aria-current="page"' : '' ?>><?= Vista::icono('buscar') ?>Otros predios</a>
        </nav>

        <div class="pestana-cuerpo">
        <?php if ($vista === 'mios'): ?>
            <?php if ($errorDatos): ?>
                <div class="aviso aviso-error" role="alert"><?= Vista::icono('alerta') ?><span><?= e($errorDatos) ?></span></div>
            <?php elseif (!$mios): ?>
                <div class="vacio vacio-chico">
                    <?= Vista::icono('predio') ?>
                    <h3>No hay predios a tu nombre</h3>
                    <p>Registrados con el documento <?= e($u['documento']) ?> no encontramos ninguno. Si eres propietario, el predio puede estar a nombre de otro documento: comunícate con la Dirección de Impuestos. Mientras tanto puedes pagarlo en «Otros predios».</p>
                    <p><a class="boton boton-primario" href="inicio.php?vista=otros"><?= Vista::icono('buscar') ?>Ir a Otros predios</a></p>
                </div>
            <?php else: ?>
                <?php if (count($mios) > 6): ?>
                <div class="filtro-predios" data-filtro>
                    <div class="filtro-chips" role="group" aria-label="Mostrar">
                        <button type="button" class="filtro-chip activo" data-filtro-estado="todos" aria-pressed="true">Todos <span><?= count($mios) ?></span></button>
                        <button type="button" class="filtro-chip" data-filtro-estado="con-deuda" aria-pressed="false">Con saldo <span><?= count($conDeuda) ?></span></button>
                        <button type="button" class="filtro-chip" data-filtro-estado="al-dia" aria-pressed="false">Al día <span><?= count($mios) - count($conDeuda) ?></span></button>
                    </div>
                    <label class="filtro-texto">
                        <?= Vista::icono('buscar') ?>
                        <span class="oculto">Filtrar por referencia o dirección</span>
                        <input type="search" placeholder="Filtrar por referencia o dirección" data-filtro-texto autocomplete="off">
                    </label>
                </div>
                <p class="filtro-vacio" data-filtro-vacio hidden>Ningún predio coincide con el filtro.</p>
                <?php endif; ?>
                <ul class="predios">
                    <?php foreach ($mios as $p) { tarjetaPredio($p, false); } ?>
                </ul>
                <p class="nota-cifras"><?= Vista::icono('info') ?>Los valores de las tarjetas son los del último cálculo. Al abrir el predio verás el valor exacto de hoy, con intereses y descuentos.</p>
            <?php endif; ?>

        <?php else: ?>
            <form method="get" action="inicio.php" class="buscador-form" role="search">
                <input type="hidden" name="vista" value="otros">
                <label class="buscador-etiqueta" for="ref">Referencia catastral del predio</label>
                <div class="buscador-fila">
                    <input id="ref" name="ref" inputmode="numeric" placeholder="Ej. 0100000000450015000000000" value="<?= e($ref) ?>" maxlength="40" autofocus<?= $errorBusqueda ? ' aria-invalid="true" aria-describedby="error-busqueda"' : ' aria-describedby="ayuda-ref"' ?>>
                    <button class="boton boton-primario" type="submit"><?= Vista::icono('buscar') ?>Buscar</button>
                </div>
                <?php if ($errorBusqueda): ?>
                    <p class="campo-error" id="error-busqueda" role="alert"><?= e($errorBusqueda) ?></p>
                <?php else: ?>
                    <p class="campo-nota" id="ayuda-ref">La nueva o la anterior, con o sin guiones. Está en una factura anterior del predial o en el certificado catastral.</p>
                <?php endif; ?>
            </form>

            <?php if ($resultados): ?>
                <h3 class="resultados-titulo"><?= count($resultados) === 1 ? 'Predio encontrado' : count($resultados) . ' predios encontrados' ?></h3>
                <ul class="predios">
                    <?php foreach ($resultados as $r) { tarjetaPredio($r, !in_array((int) $r['id'], $idsMios, true)); } ?>
                </ul>
            <?php elseif ($ref === ''): ?>
                <div class="vacio vacio-chico">
                    <?= Vista::icono('buscar') ?>
                    <h3>Busca el predio que vas a pagar</h3>
                    <p>Puedes pagar el predio de cualquier persona. Por privacidad, el nombre y el documento del propietario aparecen ocultos.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </section>
</div>
<?php
Vista::cerrar();
