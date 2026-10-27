<?php
/**
 * Cambiar la contraseña con la sesión abierta (igual que "Cambiar Contraseña"
 * de Industria y Comercio, 2026-10-27): contraseña actual y la nueva dos veces.
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';

$u = exigirSesion();
$errores = [];
$campos = ['actual', 'nueva', 'nueva2'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido($_POST['csrf'] ?? null)) {
        $errores = ['general' => 'La página estuvo abierta mucho tiempo. Vuelve a intentarlo.'];
    } else {
        try {
            $errores = Cuentas::cambiarClave(
                (int) $u['id'],
                (string) ($_POST['actual'] ?? ''),
                (string) ($_POST['nueva'] ?? ''),
                (string) ($_POST['nueva2'] ?? '')
            );
        } catch (Throwable $t) {
            error_log('[psepaipa] cambiar contraseña: ' . $t->getMessage());
            $errores = ['general' => 'No pudimos cambiar la contraseña en este momento. Intenta de nuevo en unos minutos.'];
        }
        if (!$errores) {
            session_regenerate_id(true);
            aviso('Tu contraseña quedó cambiada.', 'exito');
            redirigir('inicio.php');
        }
    }
}

function errorCampo(array $errores, string $campo): string
{
    return isset($errores[$campo]) ? '<p class="campo-error" id="e-' . e($campo) . '">' . e($errores[$campo]) . '</p>' : '';
}
function marcaCampo(array $errores, string $campo): string
{
    return isset($errores[$campo]) ? ' aria-invalid="true" aria-describedby="e-' . e($campo) . '"' : '';
}

// El foco va al primer campo con error (o al primero).
$foco = 'actual';
foreach ($campos as $k) {
    if (isset($errores[$k])) {
        $foco = $k;
        break;
    }
}
$af = fn(string $k): string => $k === $foco ? ' autofocus' : '';
Vista::abrir('Cambiar contraseña', ['titulo' => 'Cambiar contraseña', 'volver' => 'inicio.php', 'icono' => 'llave'], '', 'predios');
?>
<div class="contenedor pagina pagina-angosta">
    <section class="tarjeta" aria-labelledby="t-clave">
        <h2 id="t-clave" class="tarjeta-titulo">Nueva contraseña</h2>
        <form method="post" class="formulario formulario-plano" novalidate data-ocupado>
            <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
            <input type="text" name="username" value="<?= e($u['documento']) ?>" autocomplete="username" hidden>
            <?php if (isset($errores['general'])): ?><div class="aviso aviso-error" role="alert"><?= Vista::icono('alerta') ?><span><?= e($errores['general']) ?></span></div><?php endif; ?>
            <label class="campo">
                <span>Contraseña actual</span>
                <input name="actual" type="password" autocomplete="current-password" required maxlength="100"<?= $af('actual') ?><?= marcaCampo($errores, 'actual') ?>>
                <?= errorCampo($errores, 'actual') ?>
            </label>
            <label class="campo">
                <span>Contraseña nueva</span>
                <input name="nueva" type="password" autocomplete="new-password" required minlength="8" maxlength="100"<?= $af('nueva') ?><?= marcaCampo($errores, 'nueva') ?>>
                <?= errorCampo($errores, 'nueva') ?: '<p class="campo-nota">Mínimo 8 caracteres.</p>' ?>
            </label>
            <label class="campo">
                <span>Repite la contraseña nueva</span>
                <input name="nueva2" type="password" autocomplete="new-password" required minlength="8" maxlength="100"<?= $af('nueva2') ?><?= marcaCampo($errores, 'nueva2') ?>>
                <?= errorCampo($errores, 'nueva2') ?>
            </label>
            <div class="pago-botones">
                <a class="boton boton-contorno" href="inicio.php">Cancelar</a>
                <button type="submit" class="boton boton-primario"><?= Vista::icono('llave') ?>Cambiar contraseña</button>
            </div>
        </form>
    </section>
</div>
<?php
Vista::cerrar();
