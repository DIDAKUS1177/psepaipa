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
$errorRecuperar = null;   // "¿Olvidaste tu contraseña?" (2026-10-27)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta mucho tiempo. Vuelve a intentarlo.';
        if (($_POST['accion'] ?? '') === 'inscribir') {
            $vista = 'inscribirse';
            $datos = array_map(fn($v) => is_string($v) ? $v : '', $_POST);
            unset($datos['clave'], $datos['clave2']);
            $errores = ['general' => $error];
        }
    } elseif (($_POST['accion'] ?? '') === 'recuperar') {
        try {
            $r = Cuentas::recuperar((string) ($_POST['documento_rec'] ?? ''));
        } catch (Throwable $t) {
            error_log('[psepaipa] recuperar contraseña: ' . $t->getMessage());
            $r = ['error' => 'No pudimos conectarnos con el sistema de predial. Intenta de nuevo en unos minutos.'];
        }
        if (isset($r['correo'])) {
            aviso('Te enviamos una contraseña temporal a ' . $r['correo'] . '. Revisa tu correo, también el spam, e inicia sesión con ella.', 'exito');
            redirigir('./');
        }
        $errorRecuperar = (string) $r['error'];
        $datos['documento_rec'] = (string) ($_POST['documento_rec'] ?? '');
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
/** El primer campo con error recibe el foco (para no buscarlo a ciegas). */
$GLOBALS['__foco'] = false;
function attrError(array $errores, string $campo): string
{
    if (isset($errores[$campo]) && !$GLOBALS['__foco']) {
        $GLOBALS['__foco'] = true;
        return ' aria-invalid="true" aria-describedby="e-' . e($campo) . '" autofocus';
    }
    return isset($errores[$campo]) ? ' aria-invalid="true" aria-describedby="e-' . e($campo) . '"' : '';
}

Vista::abrir($vista === 'entrar' ? 'Iniciar sesión' : 'Registrarse', null, 'pagina-entrada', 'entrada');
$ciudad = (string) config('municipio.ciudad');
?>
<section class="entrada">
    <div class="entrada-foto" aria-hidden="true"></div>
    <div class="contenedor entrada-fila">
        <div class="entrada-texto">
            <p class="ceja">Secretaría de Hacienda · <?= e($ciudad) ?></p>
            <h1>Impuesto Predial <em>Unificado</em></h1>
            <p class="bajada">Consulta lo que debes de tus predios, elige los años que vas a pagar y descarga la factura con código de barras, sin hacer filas.</p>
            <ul class="beneficios">
                <li><?= Vista::icono('reloj') ?><span><strong>Al día de hoy.</strong> Intereses y descuentos calculados al momento.</span></li>
                <li><?= Vista::icono('calendario') ?><span><strong>Tú eliges los años.</strong> Paga una vigencia o todas.</span></li>
                <li><?= Vista::icono('usuario') ?><span><strong>También de otras personas.</strong> Con la referencia catastral.</span></li>
            </ul>
        </div>

        <div class="tarjeta-acceso">
            <nav class="pestanas" aria-label="Acceso">
                <a href="./" class="<?= $vista === 'entrar' ? 'activa' : '' ?>" <?= $vista === 'entrar' ? 'aria-current="page"' : '' ?>>Iniciar sesión</a>
                <a href="./?v=inscribirse" class="<?= $vista === 'inscribirse' ? 'activa' : '' ?>" <?= $vista === 'inscribirse' ? 'aria-current="page"' : '' ?>>Registrarse</a>
            </nav>

            <?php if ($vista === 'entrar'): ?>
            <form method="post" class="formulario" novalidate data-ocupado>
                <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                <input type="hidden" name="accion" value="entrar">
                <h2>Bienvenido</h2>
                <p class="formulario-ayuda">Usa la misma cuenta del pago en línea de predial.</p>
                <?php if ($error): ?><div class="aviso aviso-error" role="alert"><?= Vista::icono('alerta') ?><span><?= e($error) ?></span></div><?php endif; ?>
                <label class="campo">
                    <span>Número de documento</span>
                    <input name="documento" inputmode="numeric" autocomplete="username" required maxlength="13" value="<?= e($datos['documento'] ?? '') ?>"<?= $errorRecuperar === null ? ' autofocus' : '' ?>>
                </label>
                <label class="campo">
                    <span>Contraseña</span>
                    <span class="campo-clave">
                        <input name="clave" type="password" autocomplete="current-password" required maxlength="100">
                        <button type="button" class="ver-clave" aria-label="Mostrar contraseña"><?= Vista::icono('ojo') ?></button>
                    </span>
                </label>
                <button class="boton boton-primario boton-ancho" type="submit">Iniciar sesión<?= Vista::icono('ir') ?></button>
                <details class="olvido"<?= $errorRecuperar !== null ? ' open' : '' ?>>
                    <summary>¿Olvidaste tu contraseña?</summary>
                    <p>Escribe tu número de documento y te enviamos una contraseña temporal al correo de tu cuenta.</p>
                    <?php if ($errorRecuperar !== null): ?><div class="aviso aviso-error" role="alert"><?= Vista::icono('alerta') ?><span><?= e($errorRecuperar) ?></span></div><?php endif; ?>
                    <label class="campo">
                        <span>Número de documento</span>
                        <input name="documento_rec" form="form-recuperar" aria-label="Número de documento para recuperar la contraseña" inputmode="numeric" autocomplete="off" maxlength="13" value="<?= e($datos['documento_rec'] ?? '') ?>"<?= $errorRecuperar !== null ? ' autofocus' : '' ?>>
                    </label>
                    <button class="boton boton-contorno boton-ancho" type="submit" form="form-recuperar">Enviarme una contraseña temporal</button>
                    <p>¿No te llega? Comunícate con la Dirección de Impuestos:</p>
                    <ul>
                        <?php if (config('municipio.telefono')): ?><li><?= Vista::icono('telefono') ?><?= e(config('municipio.telefono')) ?></li><?php endif; ?>
                        <?php if (config('municipio.correo')): ?><li><?= Vista::icono('correo') ?><?= e(config('municipio.correo')) ?></li><?php endif; ?>
                    </ul>
                </details>
                <p class="formulario-pie">¿Primera vez? <a href="./?v=inscribirse">Crea tu cuenta</a></p>
            </form>
            <?php // Formulario de "¿Olvidaste tu contraseña?": sus campos están dentro del de entrar (atributo form=) porque un formulario no se anida en otro. ?>
            <form id="form-recuperar" method="post" novalidate data-ocupado>
                <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                <input type="hidden" name="accion" value="recuperar">
            </form>
            <?php else: ?>
            <form method="post" class="formulario" novalidate data-ocupado>
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
                        <input name="documento" required maxlength="13" inputmode="numeric" autocomplete="off" value="<?= e($datos['documento'] ?? '') ?>"<?= attrError($errores, 'documento') ?>>
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
