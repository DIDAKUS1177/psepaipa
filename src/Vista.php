<?php
/**
 * Plantilla común. Estructura del portal tributario de Duitama: encabezado
 * con el logo de la Alcaldía y el acceso, menú horizontal, franja del trámite con "Regresar", y pie con los
 * datos de contacto. La identidad (colores, tipografía, logo) es la del
 * Portal Tributario de Paipa, para que el contribuyente no sienta que salió
 * del sitio.
 */

declare(strict_types=1);

final class Vista
{
    const ICONOS = [
        'casa'      => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
        'buscar'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'salir'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'entrar'    => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
        'atras'     => '<path d="M19 12H5"/><path d="m11 18-6-6 6-6"/>',
        'ir'        => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'abajo'     => '<path d="m6 9 6 6 6-6"/>',
        'usuario'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'documento' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
        'pagar'     => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19"/><path d="M6.5 15h4"/>',
        'banco'     => '<path d="M3 21h18"/><path d="M4 10h16"/><path d="M12 3 3 8h18z"/><path d="M6 10v8M10 10v8M14 10v8M18 10v8"/>',
        'imprimir'  => '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/>',
        'chequeo'   => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'alerta'    => '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
        'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
        'telefono'  => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
        'correo'    => '<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m3 6 9 7 9-7"/>',
        'direccion' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
        'ojo'       => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'escudo'    => '<path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6l-8-3z"/><path d="m9 12 2 2 4-4"/>',
        'reloj'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'predio'    => '<path d="M3 21h18"/><path d="M5 21V10l7-6 7 6v11"/><path d="M10 21v-5h4v5"/>',
        'pregunta'  => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7v.5"/><path d="M12 17h.01"/>',
        'contraste' => '<circle cx="12" cy="12" r="9"/><path d="M12 3v18a9 9 0 0 0 0-18z" fill="currentColor"/>',
        'calendario' => '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M3 9.5h18M8 3v3M16 3v3"/>',
        'lista'     => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
    ];

    public static function icono(string $nombre, string $clase = ''): string
    {
        return '<svg' . ($clase !== '' ? ' class="' . $clase . '"' : '')
            . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . (self::ICONOS[$nombre] ?? '') . '</svg>';
    }

    private static function color(string $clave, string $porDefecto): string
    {
        $c = (string) config('municipio.' . $clave, $porDefecto);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : $porDefecto;
    }

    private static function mezclar(string $a, string $b, float $t): string
    {
        $x = sscanf($a, '#%02x%02x%02x');
        $y = sscanf($b, '#%02x%02x%02x');
        $c = [];
        for ($i = 0; $i < 3; $i++) {
            $c[] = (int) round($x[$i] * (1 - $t) + $y[$i] * $t);
        }
        return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
    }

    private static function rgb(string $hex): string
    {
        return implode(', ', sscanf($hex, '#%02x%02x%02x'));
    }

    /**
     * Abre la página. $franja = ['titulo' => ..., 'volver' => url|null,
     * 'bajada' => texto opcional] pinta la banda del trámite.
     */
    public static function abrir(string $titulo, ?array $franja = null, string $claseCuerpo = '', string $seccion = ''): void
    {
        $primario = self::color('color', '#1fa49d');
        $oscuro   = self::color('colorOscuro', '#17756f');
        $profundo = self::mezclar($oscuro, '#000000', 0.52);
        $suave    = self::mezclar($primario, '#ffffff', 0.9);
        $ciudad   = (string) config('municipio.ciudad', '');
        $u = usuario();
        $v = @filemtime(RAIZ . '/public/assets/app.css') ?: 1;
        $vj = @filemtime(RAIZ . '/public/assets/app.js') ?: 1;
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> | Impuesto Predial · Alcaldía de <?= e($ciudad) ?></title>
    <meta name="description" content="Consulta y paga en línea el Impuesto Predial Unificado de <?= e($ciudad) ?>.">
    <meta name="theme-color" content="<?= $profundo ?>">
    <link rel="icon" type="image/png" href="assets/img/escudo-paipa.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,opsz,wght@0,8..60,600;0,8..60,700;1,8..60,600&family=Inter:wght@400;500;600;700&display=swap">
    <style>
        :root {
            --primario: <?= $primario ?>;
            --primario-rgb: <?= self::rgb($primario) ?>;
            --primario-oscuro: <?= $oscuro ?>;
            --profundo: <?= $profundo ?>;
            --profundo-rgb: <?= self::rgb($profundo) ?>;
            --suave: <?= $suave ?>;
        }
    </style>
    <link rel="stylesheet" href="assets/app.css?v=<?= (int) $v ?>">
</head>
<body class="<?= e($claseCuerpo) ?>">
<a class="saltar" href="#contenido">Saltar al contenido</a>

<header class="cabecera">
    <div class="contenedor cabecera-fila">
        <a class="marca" href="<?= $u ? 'inicio.php' : './' ?>" aria-label="Alcaldía de <?= e($ciudad) ?>, inicio del pago de predial">
            <img class="marca-logo" src="assets/img/logo-paipa-full.png" alt="Alcaldía de <?= e($ciudad) ?>">
            <span class="marca-texto"><strong><?= e(config('municipio.secretaria')) ?></strong><span>Impuesto Predial Unificado</span></span>
        </a>
        <div class="cabecera-derecha">
            <?php if ($u): ?>
                <div class="cuenta">
                    <span class="cuenta-avatar" aria-hidden="true"><?= e(mb_substr($u['nombres'], 0, 1) . mb_substr($u['apellidos'], 0, 1)) ?></span>
                    <span class="cuenta-datos">
                        <span class="cuenta-nombre"><?= e(mb_convert_case(mb_strtolower($u['nombres'] . ' ' . $u['apellidos']), MB_CASE_TITLE)) ?></span>
                        <span class="cuenta-doc">Documento <?= e($u['documento']) ?></span>
                    </span>
                    <form method="post" action="salir.php">
                        <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                        <button class="boton-salir" type="submit" aria-label="Salir"><?= self::icono('salir') ?><span>Salir</span></button>
                    </form>
                </div>
            <?php else: ?>
                <a class="boton boton-contorno boton-chico" href="./?v=inscribirse">Registrarse</a>
                <a class="boton boton-primario boton-chico" href="./"><?= self::icono('entrar') ?>Iniciar sesión</a>
            <?php endif; ?>
        </div>
    </div>
    <nav class="menu" aria-label="Menú principal">
        <div class="contenedor menu-fila">
            <a href="<?= e(config('municipio.portal')) ?>"><?= self::icono('casa') ?>Portal Tributario</a>
            <?php if ($u): ?>
                <a href="inicio.php" class="<?= $seccion === 'predios' ? 'activo' : '' ?>" <?= $seccion === 'predios' ? 'aria-current="page"' : '' ?>><?= self::icono('predio') ?>Mis predios</a>
                <a href="inicio.php?vista=otros" class="<?= $seccion === 'otro' ? 'activo' : '' ?>" <?= $seccion === 'otro' ? 'aria-current="page"' : '' ?>><?= self::icono('buscar') ?>Otros predios</a>
            <?php else: ?>
                <a href="./" class="<?= $seccion === 'entrada' ? 'activo' : '' ?>"><?= self::icono('predio') ?>Impuesto predial</a>
            <?php endif; ?>
            <a href="#contacto"><?= self::icono('telefono') ?>Contáctenos</a>
        </div>
    </nav>
</header>
<?php if ($franja !== null): ?>
<div class="franja">
    <div class="contenedor franja-fila">
        <?php if (!empty($franja['volver'])): ?>
            <a class="volver" href="<?= e($franja['volver']) ?>"><?= self::icono('atras') ?><span>Regresar</span></a>
        <?php endif; ?>
        <h1 class="franja-titulo"><span class="franja-icono"><?= self::icono($franja['icono'] ?? 'predio') ?></span><?= e($franja['titulo']) ?></h1>
    </div>
</div>
<?php endif; ?>
<main id="contenido" tabindex="-1">
<?php
        $a = aviso();
        if ($a !== null): ?>
    <div class="contenedor">
        <div class="aviso aviso-<?= e($a['tipo']) ?> aviso-flotante" role="status"><?= self::icono($a['tipo'] === 'error' ? 'alerta' : 'chequeo') ?><span><?= e($a['texto']) ?></span></div>
    </div>
<?php endif;
        $GLOBALS['__vj'] = $vj;
    }

    public static function cerrar(): void
    {
        $ciudad = (string) config('municipio.ciudad', '');
        $vj = (int) ($GLOBALS['__vj'] ?? 1);
        ?>
</main>
<footer class="pie" id="contacto">
    <div class="contenedor pie-rejilla">
        <div class="pie-marca">
            <img src="assets/img/escudo-paipa.png" alt="" width="48" height="56">
            <div>
                <strong>Alcaldía de <?= e($ciudad) ?></strong>
                <span><?= e(config('municipio.secretaria')) ?></span>
                <span><?= e(config('municipio.dependencia')) ?></span>
            </div>
        </div>
        <div>
            <h2 class="pie-titulo">Contáctenos</h2>
            <ul class="pie-contacto">
                <?php if (config('municipio.telefono')): ?><li><?= self::icono('telefono') ?><a href="tel:+57<?= e(preg_replace('/\D/', '', (string) config('municipio.telefono'))) ?>"><?= e(config('municipio.telefono')) ?></a></li><?php endif; ?>
                <?php if (config('municipio.correo')): ?><li><?= self::icono('correo') ?><a href="mailto:<?= e(config('municipio.correo')) ?>"><?= e(config('municipio.correo')) ?></a></li><?php endif; ?>
                <?php if (config('municipio.direccion')): ?><li><?= self::icono('direccion') ?><span><?= e(config('municipio.direccion')) ?>, <?= e($ciudad) ?>, <?= e(config('municipio.departamento')) ?></span></li><?php endif; ?>
                <?php if (config('municipio.horario')): ?><li><?= self::icono('reloj') ?><span><?= e(config('municipio.horario')) ?></span></li><?php endif; ?>
            </ul>
        </div>
        <div>
            <h2 class="pie-titulo">Enlaces</h2>
            <ul class="pie-enlaces">
                <li><a href="<?= e(config('municipio.portal')) ?>">Portal Tributario de <?= e($ciudad) ?></a></li>
                <?php foreach ((array) config('municipio.enlaces', []) as $texto => $url): ?>
                    <li><a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($texto) ?></a></li>
                <?php endforeach; ?>
                <li><a href="https://www.gov.co/" target="_blank" rel="noopener">Portal del Estado colombiano GOV.CO</a></li>
            </ul>
        </div>
    </div>
    <div class="pie-legal">
        <div class="contenedor pie-legal-fila">
            <span>© <?= date('Y') ?> Alcaldía de <?= e($ciudad) ?> · Pago en línea del Impuesto Predial Unificado</span>
            <span>Desarrollado por ERPSOFT S.A.S.</span>
        </div>
    </div>
</footer>
<script src="assets/app.js?v=<?= $vj ?>"></script>
</body>
</html>
<?php
    }
}
