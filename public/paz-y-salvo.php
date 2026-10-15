<?php
/**
 * PDF del certificado de paz y salvo del predial (ver src/PazYSalvo.php).
 * Solo por POST desde la página del predio: expedirlo gasta un número del
 * consecutivo oficial.
 *
 * Formato: el mismo del certificado oficial de Paipa (pazPDF_pai; muestra que
 * mandó la Tesorería el 2026-10-14), en A4 como el oficial: encabezado con
 * escudo y QR, "LA TESORERÍA MUNICIPAL DE PAIPA CERTIFICA", datos del predio y
 * de sus propietarios (completos, como el oficial) y firma del Secretario de
 * Hacienda. La imagen de la firma NO va en el repositorio (es público): está en
 * el servidor y config paz_salvo.firma apunta a ella (JPEG, sin canal alfa).
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';
require_once RAIZ . '/src/PdfPredial.php';

$u = exigirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValido($_POST['csrf'] ?? null)) {
    redirigir('inicio.php');
}
$id = (int) ($_POST['id'] ?? 0);
$destino = trim(preg_replace('/\s+/', ' ', (string) ($_POST['destino'] ?? '')) ?? '');
$destino = mb_strtoupper(mb_substr($destino !== '' ? $destino : 'Quien interese', 0, 80), 'UTF-8');

try {
    $p = Predios::permitido($id, $u['documento']) ? Predios::detalle($id) : null;
    if ($p === null) {
        throw new AvisoUsuario('No encontramos ese predio. Búscalo de nuevo por su referencia catastral.');
    }
    PagoLinea::refrescar($id);
    $c = PazYSalvo::expedir($id, (int) $u['id']);
    $ultimo = PazYSalvo::ultimoPago($id);
    $actual = PazYSalvo::avaluoActual($id);
    $param = fn(string $n) => trim((string) Db::valor("SELECT TOP 1 valor FROM parametros WHERE nombre = ? ORDER BY CASE WHEN estado = 1 THEN 0 ELSE 1 END, id", [$n]));
    $nit = $param('nit');
    $codigoPostal = $param('codigo_postal');
} catch (AvisoUsuario $e) {
    PdfPredial::error($e->getMessage(), $id);
} catch (Throwable $t) {
    error_log('[psepaipa] paz y salvo predio ' . $id . ': ' . $t->getMessage());
    PdfPredial::error('No pudimos generar el paz y salvo en este momento. Intenta de nuevo en unos minutos.', $id);
}

try {
    $pdf = PazYSalvo::pdf($p, $c, $ultimo, $actual, $nit, $codigoPostal, $destino);
} catch (Throwable $t) {
    // El número ya se expidió: al reintentar el mismo día se repite (sesión).
    error_log('[psepaipa] PDF del paz y salvo ' . $c['numero'] . ': ' . $t->getMessage());
    PdfPredial::error('No pudimos armar el PDF del paz y salvo. Intenta de nuevo en unos minutos.', $id);
}
$pdf->Output($c['numero'] . '_' . date('Y-m-d_H-i-s') . '.pdf', 'I');
