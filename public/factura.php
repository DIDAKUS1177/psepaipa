<?php
/**
 * PDF de la factura (liquidación oficial) para las vigencias elegidas, en el formato de la
 * Alcaldía de Paipa (A4, una hoja, con dos cupones y código de barras GS1-128; ver
 * src/FacturaPdf.php). Solo por POST desde la página del predio: generarla asigna número
 * (ver src/Factura.php).
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';
require_once RAIZ . '/src/PdfPredial.php';
require_once RAIZ . '/src/FacturaPdf.php';

$u = exigirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValido($_POST['csrf'] ?? null)) {
    redirigir('inicio.php');
}
$id = (int) ($_POST['id'] ?? 0);
$desde = (int) ($_POST['desde'] ?? 0);
$hasta = (int) ($_POST['hasta'] ?? 0);

try {
    $p = Predios::permitido($id, $u['documento']) ? Predios::detalle($id) : null;
    if ($p === null) {
        throw new AvisoUsuario('No encontramos ese predio. Búscalo de nuevo por su referencia catastral.');
    }
    PagoLinea::refrescar($id);
    $f = Factura::generar($id, $desde, $hasta, (int) $u['id']);
} catch (AvisoUsuario $e) {
    PdfPredial::error($e->getMessage(), $id);
} catch (Throwable $t) {
    error_log('[psepaipa] factura predio ' . $id . ': ' . $t->getMessage());
    PdfPredial::error('No pudimos generar la factura en este momento. Intenta de nuevo en unos minutos.', $id);
}

// La factura ya quedó numerada y guardada; si el dibujo falla, al reintentar se reutiliza el número.
// Formato: la hoja de la Alcaldía de Paipa (por defecto, la de siempre) o la general del portal,
// para los municipios que no tienen una acordada ('factura.formato' => 'general' en su perfil).
try {
    if ((string) config('factura.formato', 'paipa') === 'general') {
        require_once RAIZ . '/src/FacturaGeneral.php';
        $pdf = FacturaGeneral::dibujar($f, $p, $desde, $hasta);
    } else {
        $pdf = FacturaPdf::dibujar([
            'numero'   => $f['numero'],
            'emision'  => $f['emision'],
            'predio'   => $p,
            'ultimo'   => PazYSalvo::ultimoPago($id),
            'filas'    => $f['filas'],
            'desde'    => $desde,
            'hasta'    => $hasta,
            'cupon'    => $f['cupon'],
            'ean'      => $f['ean'],
            'tasa'     => Factura::tasaMoraAnual(),
            'firma'    => (string) config('paz_salvo.firma', ''),
            'firmante' => (string) config('factura.firmante', 'William Niño Echeverría'),
            'cargo'    => (string) config('factura.cargo', 'Secretario de Hacienda Municipal'),
        ]);
    }
} catch (Throwable $t) {
    error_log('[psepaipa] factura ' . $f['numero'] . ' (PDF): ' . $t->getMessage());
    PdfPredial::error('No pudimos armar el PDF de la factura. Intenta de nuevo en unos minutos.', $id);
}

$pdf->Output('Factura_predial_' . $f['numero'] . '.pdf', 'I');
