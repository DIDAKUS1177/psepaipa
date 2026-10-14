<?php
/**
 * Verificación del despliegue, SOLO por línea de comandos (en Plesk: Tareas
 * programadas > Ejecutar un script PHP > "Ejecutar ahora"). No escribe nada:
 * el cálculo de prueba se hace dentro de una transacción que se deshace.
 * No imprime usuario ni clave de la base.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '1');
$_SERVER['REQUEST_METHOD'] = 'GET';

$ok = 0;
$n = 0;
function paso(string $texto, bool $bien, string $detalle = ''): void
{
    global $ok, $n;
    $n++;
    $ok += $bien ? 1 : 0;
    echo ($bien ? '[OK]    ' : '[FALLA] ') . $texto . ($detalle !== '' ? ' — ' . $detalle : '') . PHP_EOL;
}

try {
    require __DIR__ . '/../src/arranque.php';
} catch (Throwable $t) {
    paso('Arranque', false, $t->getMessage());
    exit(1);
}

echo 'PHP ' . PHP_VERSION . PHP_EOL;
paso('Extensión pdo_sqlsrv', extension_loaded('pdo_sqlsrv'));
$fuente = config('db_appsettings') ? 'appsettings.json de la API anterior' : 'config.local.php';
if (config('db_appsettings')) {
    require_once __DIR__ . '/../src/ConexionNet.php';
    $leida = ConexionNet::leer((string) config('db_appsettings'), config('db_appsettings_nombre'));
    paso('Lee la conexión del appsettings.json', $leida !== null, ConexionNet::diagnostico((string) config('db_appsettings')));
}
paso('Conexión configurada (' . $fuente . ')', (string) config('db.base') !== '' && (string) config('db.usuario') !== '', 'base ' . config('db.base') . ' en ' . config('db.servidor'));

try {
    $version = (string) Db::valor('SELECT @@VERSION');
    paso('Conecta a SQL Server', $version !== '', strtok($version, "\n"));
    // Con un login en español, 'AAAA-MM-DD' en un datetime se lee día-mes: por
    // eso el portal manda siempre 'AAAA-MM-DDThh:mm:ss'.
    paso('Idioma del login de SQL Server', true, (string) Db::valor('SELECT @@LANGUAGE'));
} catch (Throwable $t) {
    paso('Conecta a SQL Server', false, $t->getMessage());
    echo PHP_EOL . "$ok de $n" . PHP_EOL;
    exit(1);
}

foreach (['predios', 'predios_pagos', 'propietarios', 'predios_propietarios', 'usuarios_pse', 'anios', 'parametros', 'pagos'] as $tabla) {
    try {
        paso("Tabla $tabla", true, number_format((int) Db::valor("SELECT COUNT(*) FROM $tabla"), 0, ',', '.') . ' filas');
    } catch (Throwable $t) {
        paso("Tabla $tabla", false, $t->getMessage());
    }
}
paso('Carpeta de límites de uso escribible (por este usuario)', Limites::escribible(), Limites::carpeta());
paso('Procedimiento SP_CALCULO_PREDIAL', (int) Db::valor("SELECT COUNT(*) FROM sys.procedures WHERE name = 'SP_CALCULO_PREDIAL'") === 1);
$alcaldia = (string) Db::valor("SELECT TOP 1 valor FROM parametros WHERE nombre = 'alcaldia'");
paso('Es la base del municipio configurado', stripos($alcaldia, (string) config('municipio.ciudad')) !== false, $alcaldia);
$ean = Factura::ean();
paso('EAN de recaudo (código de barras)', $ean !== null, $ean ?? 'sin EAN en parametros');
$anio = (int) date('Y');
paso("Año $anio abierto para facturar", Db::valor('SELECT COUNT(*) FROM anios WHERE anio = ?', [$anio]) > 0);
$usuario = (int) config('usuario_sistema', 1);
paso("usuarios.id $usuario existe (lo usa el cálculo)", (int) Db::valor('SELECT COUNT(*) FROM usuarios WHERE id = ?', [$usuario]) === 1);

$predio = Db::valor('SELECT TOP 1 p.id FROM predios p WHERE p.estado = 1 AND EXISTS (SELECT 1 FROM predios_pagos pp WHERE pp.id_predio = p.id AND ' . Predios::SE_DEBE . ') ORDER BY p.id DESC');
if ($predio !== null) {
    $antes = Db::valor('SELECT CHECKSUM_AGG(CHECKSUM(*)) FROM predios_pagos WHERE id_predio = ?', [(int) $predio]);
    $c = Predios::cotizar((int) $predio);
    $despues = Db::valor('SELECT CHECKSUM_AGG(CHECKSUM(*)) FROM predios_pagos WHERE id_predio = ?', [(int) $predio]);
    paso('Cotiza un predio con el cálculo oficial', $c['ok'] && $c['total'] > 0, 'total ' . pesos($c['total']) . ', ' . count($c['filas']) . ' vigencias');
    paso('La cotización no dejó cambios en la base', $antes === $despues);
} else {
    paso('Hay predios con deuda para probar el cálculo', false);
}

if (config('avalpay_ica')) {
    paso('Convenio AvalPay de ICA legible', AvalPay::configurado(), (string) config('avalpay_ica'));
}
paso('Paz y salvo: tabla de validación QR con sus columnas', (int) Db::valor("SELECT COUNT(*) FROM sys.columns WHERE object_id = OBJECT_ID('predios_validaciones_qr')
    AND name IN ('token','id_predio','certificado_numero','propietario_principal','fecha_expedicion','fecha_validez','is_validated','validated_at','validated_ip','validated_user_agent','estado','max_validations','validation_count')") === 13);
paso('Paz y salvo: columna anios.consecutivo_paz', (int) Db::valor("SELECT COUNT(*) FROM sys.columns WHERE object_id = OBJECT_ID('anios') AND name = 'consecutivo_paz'") === 1);
paso('Acuerdos de pago (predios_acuerdos_pago)', Db::valor("SELECT OBJECT_ID('predios_acuerdos_pago', 'U')") !== null,
    (int) Db::valor('SELECT COUNT(*) FROM predios_acuerdos_pago WHERE estado_acuerdo = 1') . ' activos');

// Pago en línea (AvalPay): solo si está configurado.
if (AvalPay::configurado()) {
    $prov = PagoLinea::proveedor();
    $ajenos = (int) Db::valor('SELECT COUNT(*) FROM generacion_pagos WHERE proveedor = ? AND (Impuesto IS NULL OR Impuesto NOT LIKE ?)', [$prov, 'Predial %']);
    paso("generacion_pagos: proveedor $prov solo lo usa este portal", $ajenos === 0, $ajenos . ' filas ajenas');
    paso('Banco PSE para registrar el pago', (int) config('avalpay.id_banco', 0) > 0 || Db::valor("SELECT COUNT(*) FROM bancos WHERE asobancaria = '999'") > 0);
    $usuarioPagos = (int) Db::valor("SELECT COUNT(*) FROM sys.columns WHERE object_id = OBJECT_ID('pagos') AND name = 'id_usuario'");
    paso('Tabla pagos con las columnas esperadas', (int) Db::valor("SELECT COUNT(*) FROM sys.columns WHERE object_id = OBJECT_ID('pagos') AND name IN ('numero_recibo','valor_facturado','origen','acuerdo')") === 4, $usuarioPagos ? 'con id_usuario' : 'sin id_usuario');
    paso('AvalPay: ambiente', true, AvalPay::ambiente() . (config('avalpay_ica') ? ' — credenciales del convenio de ICA' : ''));
    paso('AvalPay activo para', true, config('avalpay.activo', false) ? (config('avalpay.usuarios_prueba') ? 'solo usuarios de prueba' : 'todos') : 'nadie (activo = false)');
}

echo PHP_EOL . "$ok de $n" . PHP_EOL;
exit($ok === $n ? 0 : 1);
