<?php
/**
 * Entrada al portal: iniciar sesión, inscribirse o pedir ayuda con la
 * contraseña. Misma cuenta del PSE anterior (usuarios_pse).
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';

if (usuario() !== null) {
    redirigir('inicio.php');
}

$vista = ($_GET['v'] ?? '') === 'inscribirse' ? 'inscribirse' : 'entrar';
$error = null;
$errores = [];
$datos = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta mucho tiempo. Vuelve a intentarlo.';
        if (($_POST['accion'] ?? '') === 'inscribir') {
            $vista = 'inscribirse';
            $datos = array_map(fn($v) => is_string($v) ? $v : '', $_POST);
            unset($datos['clave'], $datos['clave2']);
            $errores = ['general' => $error];
        }
    } elseif (($_POST['accion'] ?? '') === 'entrar') {
        try {
            $error = Cuentas::entrar((string) ($_POST['documento'] ?? ''), (string) ($_POST['clave'] ?? ''));
        } catch (Throwable $t) {
            error_log('[psepaipa] login: ' . $t->getMessage());
            $error = 'No pudimos conectarnos con el sistema de predial. Intenta de nuevo en unos minutos.';
        }
        if ($error === null) {
            redirigir('inicio.php');
        }
        $datos['documento'] = (string) ($_POST['documento'] ?? '');
    } elseif (($_POST['accion'] ?? '') === 'inscribir') {
        $vista = 'inscribirse';
        $datos = array_map(fn($v) => is_string($v) ? $v : '', $_POST);
        try {
            $errores = Cuentas::inscribir($_POST);
        } catch (Throwable $t) {
            error_log('[psepaipa] inscripción: ' . $t->getMessage());
            $errores = ['general' => 'No pudimos crear la cuenta en este momento. Intenta de nuevo en unos minutos.'];
        }
        if (!$errores) {
            aviso('Tu cuenta quedó creada. Ya puedes iniciar sesión.', 'exito');
            redirigir('./');
        }
    }
}

function campoError(array $errores, string $campo): string
{
    return isset($errores[$campo]) ? '<p class="campo-error" id="e-' . e($campo) . '">' . e($errores[$campo]) . '</p>' : '';
}
function attrError(array $errores, string $campo): string
{
    return isset($errores[$campo]) ? ' aria-invalid="true" aria-describedby="e-' . e($campo) . '"' : '';
}

Vista::abrir($vista === 'entrar' ? 'Iniciar sesión' : 'Inscribirse', null, 'pagina-entrada');
$ciudad = (string) config('municipio.ciudad');
?>
<section class="entrada">
    <div class="entrada-foto" aria-hidden="true"></div>
    <div class="contenedor entrada-fila">
        <div class="entrada-texto">
            <p class="ceja">Secretaría de Hacienda · <?= e($ciudad) ?></p>
            <h1>Impuesto Predial <em>Unificado</em></h1>
            <p class="bajada">Consulta el estado de cuenta de tus predios, genera la factura con código de barras y paga en línea, sin hacer filas.</p>
            <ol class="pasos">
                <li><span>1</span>Inicia sesión con tu documento.</li>
                <li><span>2</span>Revisa tus predios y las vigencias que debes.</li>
                <li><span>3</span>Elige los años y paga, o descarga la factura para el banco.</li>
            </ol>
            <p class="entrada-nota"><?= Vista::icono('escudo') ?>¿Vas a pagar el predio de otra persona? También puedes: búscalo por su referencia catastral después de entrar.</p>
        </div>

        <div class="tarjeta-acceso">
            <div class="pestanas" role="tablist">
                <a role="tab" href="./" class="<?= $vista === 'entrar' ? 'activa' : '' ?>" aria-selected="<?= $vista === 'entrar' ? 'true' : 'false' ?>">Iniciar sesión</a>
                <a role="tab" href="./?v=inscribirse" class="<?= $vista === 'inscribirse' ? 'activa' : '' ?>" aria-selected="<?= $vista === 'inscribirse' ? 'true' : 'false' ?>">Inscribirme</a>
            </div>

            <?php if ($vista === 'entrar'): ?>
            <form method="post" class="formulario" novalidate>
                <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                <input type="hidden" name="accion" value="entrar">
                <h2>Bienvenido</h2>
                <p class="formulario-ayuda">Usa la misma cuenta del pago en línea de predial.</p>
                <?php if ($error): ?><div class="aviso aviso-error" role="alert"><?= Vista::icono('alerta') ?><span><?= e($error) ?></span></div><?php endif; ?>
                <label class="campo">
                    <span>Número de documento</span>
                    <input name="documento" inputmode="numeric" autocomplete="username" required maxlength="20" value="<?= e($datos['documento'] ?? '') ?>" autofocus>
                </label>
                <label class="campo">
                    <span>Contraseña</span>
                    <span class="campo-clave">
                        <input name="clave" type="password" autocomplete="current-password" required maxlength="100">
                        <button type="button" class="ver-clave" aria-label="Mostrar contraseña"><?= Vista::icono('ojo') ?></button>
                    </span>
                </label>
                <button class="boton boton-primario boton-ancho" type="submit">Iniciar sesión<?= Vista::icono('ir') ?></button>
                <details class="olvido">
                    <summary>¿Olvidaste tu contraseña?</summary>
                    <p>Comunícate con la Dirección de Impuestos y te ayudan a restablecerla:</p>
                    <ul>
                        <?php if (config('municipio.telefono')): ?><li><?= Vista::icono('telefono') ?><?= e(config('municipio.telefono')) ?></li><?php endif; ?>
                        <?php if (config('municipio.correo')): ?><li><?= Vista::icono('correo') ?><?= e(config('municipio.correo')) ?></li><?php endif; ?>
                    </ul>
                </details>
                <p class="formulario-pie">¿Primera vez? <a href="./?v=inscribirse">Crea tu cuenta</a></p>
            </form>
            <?php else: ?>
            <form method="post" class="formulario" novalidate>
                <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                <input type="hidden" name="accion" value="inscribir">
                <h2>Crea tu cuenta</h2>
                <p class="formulario-ayuda">Con ella verás los predios que están a tu nombre.</p>
                <?php if (isset($errores['general'])): ?><div class="aviso aviso-error" role="alert"><?= Vista::icono('alerta') ?><span><?= e($errores['general']) ?></span></div><?php endif; ?>
                <div class="rejilla-2">
                    <label class="campo">
                        <span>Tipo de documento</span>
                        <select name="tipo" required<?= attrError($errores, 'tipo') ?>>
                            <?php foreach (Cuentas::TIPOS as $id => $nombre): ?>
                                <option value="<?= $id ?>" <?= (string) ($datos['tipo'] ?? '1') === (string) $id ? 'selected' : '' ?>><?= e($nombre) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= campoError($errores, 'tipo') ?>
                    </label>
                    <label class="campo">
                        <span>Número de documento</span>
                        <input name="documento" required maxlength="20" value="<?= e($datos['documento'] ?? '') ?>"<?= attrError($errores, 'documento') ?>>
                        <?= campoError($errores, 'documento') ?>
                    </label>
                    <label class="campo">
                        <span>Nombres</span>
                        <input name="nombres" required maxlength="60" autocomplete="given-name" value="<?= e($datos['nombres'] ?? '') ?>"<?= attrError($errores, 'nombres') ?>>
                        <?= campoError($errores, 'nombres') ?>
                    </label>
                    <label class="campo">
                        <span>Apellidos</span>
                        <input name="apellidos" required maxlength="60" autocomplete="family-name" value="<?= e($datos['apellidos'] ?? '') ?>"<?= attrError($errores, 'apellidos') ?>>
                        <?= campoError($errores, 'apellidos') ?>
                    </label>
                    <label class="campo">
                        <span>Fecha de nacimiento</span>
                        <input name="nacimiento" type="date" required value="<?= e($datos['nacimiento'] ?? '') ?>" max="<?= e(date('Y-m-d', strtotime('-14 years'))) ?>"<?= attrError($errores, 'nacimiento') ?>>
                        <?= campoError($errores, 'nacimiento') ?>
                    </label>
                    <label class="campo">
                        <span>Teléfono celular</span>
                        <input name="telefono" inputmode="tel" required maxlength="14" autocomplete="tel" value="<?= e($datos['telefono'] ?? '') ?>"<?= attrError($errores, 'telefono') ?>>
                        <?= campoError($errores, 'telefono') ?>
                    </label>
                </div>
                <label class="campo">
                    <span>Correo electrónico</span>
                    <input name="correo" type="email" required maxlength="128" autocomplete="email" value="<?= e($datos['correo'] ?? '') ?>"<?= attrError($errores, 'correo') ?>>
                    <?= campoError($errores, 'correo') ?>
                </label>
                <label class="campo">
                    <span>Dirección</span>
                    <input name="direccion" required maxlength="128" autocomplete="street-address" value="<?= e($datos['direccion'] ?? '') ?>"<?= attrError($errores, 'direccion') ?>>
                    <?= campoError($errores, 'direccion') ?>
                </label>
                <div class="rejilla-2">
                    <label class="campo">
                        <span>Contraseña</span>
                        <input name="clave" type="password" required minlength="8" maxlength="100" autocomplete="new-password"<?= attrError($errores, 'clave') ?>>
                        <?= campoError($errores, 'clave') ?: '<p class="campo-nota">Mínimo 8 caracteres.</p>' ?>
                    </label>
                    <label class="campo">
                        <span>Repite la contraseña</span>
                        <input name="clave2" type="password" required minlength="8" maxlength="100" autocomplete="new-password"<?= attrError($errores, 'clave2') ?>>
                        <?= campoError($errores, 'clave2') ?>
                    </label>
                </div>
                <button class="boton boton-primario boton-ancho" type="submit">Crear mi cuenta<?= Vista::icono('ir') ?></button>
                <p class="formulario-pie">¿Ya tienes cuenta? <a href="./">Inicia sesión</a></p>
            </form>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php
Vista::cerrar();
