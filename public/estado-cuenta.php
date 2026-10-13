<?php
/**
 * Estado de cuenta en PDF: lo mismo que se ve en pantalla para las vigencias
 * elegidas, calculado al día de hoy. Es INFORMATIVO: no lleva número de
 * factura ni código de barras, y no escribe nada en la base (usa la
 * cotización que se deshace).
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';
require_once RAIZ . '/src/PdfPredial.php';

$u = exigirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValido($_POST['csrf'] ?? null)) {
    redirigir('inicio.php');
}
$id = (int) ($_POST['id'] ?? 0);
$desde = (int) ($_POST['desde'] ?? 0);
$hasta = (int) ($_POST['hasta'] ?? 0);
$anio = (int) date('Y');

try {
    $p = Predios::permitido($id, $u['documento']) ? Predios::detalle($id) : null;
    if ($p === null || $desde < 1900 || $hasta > $anio || $desde > $hasta) {
        throw new AvisoUsuario('No pudimos preparar el estado de cuenta de esas vigencias.');
    }
    $c = Predios::cotizar($id, $desde, $hasta);
    if (!$c['rango']) {
        throw new AvisoUsuario('Las vigencias elegidas ya no están pendientes de pago.');
    }
} catch (AvisoUsuario $e) {
    PdfPredial::error($e->getMessage(), $id);
} catch (Throwable $t) {
    error_log('[psepaipa] estado de cuenta ' . $id . ': ' . $t->getMessage());
    PdfPredial::error('No pudimos preparar el estado de cuenta. Intenta de nuevo en unos minutos.', $id);
}

$pdf = PdfPredial::crear('Estado de cuenta predial ' . $p['codigo_predio']);
PdfPredial::encabezado($pdf, ['ESTADO DE CUENTA', date('d/m/Y'), 'Calculado al ' . date('d/m/Y h:i a'), 'Documento informativo'], [79, 98, 93]);
$pdf->SetFont('helvetica', '', 8);
$pdf->MultiCell(PdfPredial::ANCHO, 3.6,
    'Valores del Impuesto Predial Unificado de las vigencias ' . $desde . ($hasta !== $desde ? ' a ' . $hasta : '')
    . ', con los intereses y descuentos calculados a la fecha. Este documento NO sirve para pagar: para pagar, genere la factura con código de barras en el portal.', 0, 'J');
PdfPredial::predio($pdf, $p);
PdfPredial::vigencias($pdf, $c['rango']);

$pdf->SetY($pdf->GetY() + 5);
$pdf->SetFillColor(232, 244, 242);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(PdfPredial::ANCHO, 10, 'TOTAL A PAGAR HOY: ' . PdfPredial::pesos($c['total']) . '      FECHA LÍMITE: ' . date('d/m/Y', strtotime($c['hasta'])), 0, 1, 'C', true);
$pdf->SetY($pdf->GetY() + 3);
$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(79, 98, 93);
$pdf->MultiCell(PdfPredial::ANCHO, 3.5, 'El total puede diferir de la suma de las filas: los descuentos dependen de las vigencias que se paguen juntas.', 0, 'L');

$pdf->Output('Estado_de_cuenta_' . preg_replace('/\D/', '', $p['codigo_predio']) . '.pdf', 'I');
