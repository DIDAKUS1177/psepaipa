<?php
/**
 * PDF del certificado de paz y salvo del predial (ver src/PazYSalvo.php).
 * Solo por POST desde la página del predio: expedirlo gasta un número del
 * consecutivo oficial.
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
$destino = mb_strtoupper(mb_substr($destino !== '' ? $destino : 'A quien interese', 0, 80), 'UTF-8');

try {
    $p = Predios::permitido($id, $u['documento']) ? Predios::detalle($id) : null;
    if ($p === null) {
        throw new AvisoUsuario('No encontramos ese predio. Búscalo de nuevo por su referencia catastral.');
    }
    PagoLinea::refrescar($id);
    $c = PazYSalvo::expedir($id, (int) $u['id']);
    $ultimo = PazYSalvo::ultimoPago($id);
} catch (AvisoUsuario $e) {
    PdfPredial::error($e->getMessage(), $id);
} catch (Throwable $t) {
    error_log('[psepaipa] paz y salvo predio ' . $id . ': ' . $t->getMessage());
    PdfPredial::error('No pudimos generar el paz y salvo en este momento. Intenta de nuevo en unos minutos.', $id);
}

$ciudad = (string) config('municipio.ciudad');
$dependencia = (string) config('paz_salvo.dependencia', 'Tesorería Municipal');
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fechaLarga = fn(string $f) => (int) substr($f, 8, 2) . ' de ' . $meses[(int) substr($f, 5, 2) - 1] . ' de ' . substr($f, 0, 4);
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$pdf = PdfPredial::crear('Paz y salvo predial ' . $c['numero']);
PdfPredial::encabezado($pdf, ['PAZ Y SALVO No.', $c['numero'], 'Expedido: ' . date('d/m/Y h:i a'), 'Válido hasta ' . date('d/m/Y', strtotime($c['validez']))]);

$pdf->SetY(44);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(PdfPredial::ANCHO, 6, 'CERTIFICADO DE PAZ Y SALVO - IMPUESTO PREDIAL UNIFICADO', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 10.5);
$pdf->Cell(PdfPredial::ANCHO, 6, mb_strtoupper('La ' . $dependencia . ' de ' . $ciudad, 'UTF-8'), 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(PdfPredial::ANCHO, 9, 'CERTIFICA', 0, 1, 'C');

$nombres = array_map(fn($x) => Predios::enmascararNombre((string) $x['nombre']), $p['propietarios']);
$texto = '<p style="font-size:9.5px;text-align:justify;line-height:1.5;">Que en los archivos de la ' . $h($dependencia)
    . ' aparece inscrito el predio con código catastral número <b>' . $h($p['codigo_predio']) . '</b>'
    . ($nombres ? ', a nombre de <b>' . $h($nombres[0]) . '</b>' . (count($nombres) > 1 ? ' y otros' : '') : '')
    . ', el cual se encuentra a <b>PAZ Y SALVO</b> por concepto de Impuesto Predial Unificado hasta la vigencia <b>' . (int) date('Y') . '</b>.';
if ($ultimo !== null && trim((string) $ultimo['factura_pago']) !== '') {
    $texto .= '<br/><b>Último pago con factura No.:</b> ' . $h(trim((string) $ultimo['factura_pago']))
        . ($ultimo['fecha_pago'] ? '   <b>Fecha:</b> ' . $h(substr((string) $ultimo['fecha_pago'], 0, 10)) : '');
}
$texto .= '</p>';
$pdf->writeHTML($texto, true, false, true, false, '');

// Datos del predio (propietarios ocultos, como en todo el portal).
PdfPredial::predio($pdf, $p);
$pdf->SetY($pdf->GetY() + 2);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(PdfPredial::ANCHO, 5, 'Avalúo vigente: ' . PdfPredial::pesos($ultimo['avaluo'] ?? $p['avaluo']) . '    Año del avalúo: ' . ($ultimo['ultimo_anio'] ?? date('Y')), 0, 1, 'L');

$nota = (string) config('paz_salvo.nota', '');
$cuerpo = '<p style="font-size:9.5px;line-height:1.5;">Expedido el ' . $h($fechaLarga(hoy())) . '.<br/>'
    . 'Se expide con destino a: <b>' . $h($destino) . '</b>.<br/>'
    . 'Válido hasta: <b>' . $h(date('d/m/Y', strtotime($c['validez']))) . '</b>.'
    . ((int) config('paz_salvo.valor', 0) > 0 ? '<br/>Valor: <b>' . $h(PdfPredial::pesos(config('paz_salvo.valor'))) . '</b> pesos m/cte.' : '')
    . '</p>'
    . ($nota !== '' ? '<p style="font-size:9px;text-align:justify;">' . $h($nota) . '</p>' : '')
    . '<p style="font-size:9px;">La presente certificación se expide en relación con el predio identificado con cédula catastral No. <b>'
    . $h($p['codigo_predio']) . '</b>.</p>';
$pdf->SetY($pdf->GetY() + 3);
$pdf->writeHTML($cuerpo, true, false, true, false, '');

// Firma y código QR.
$y = max($pdf->GetY() + 10, 190);
$firma = (string) config('paz_salvo.firma', '');
if ($firma !== '' && is_file($firma)) {
    $pdf->Image($firma, 30, $y - 18, 50, 0);
}
$pdf->Line(22, $y + 8, 98, $y + 8);
$pdf->SetXY(22, $y + 9);
$pdf->SetFont('helvetica', 'B', 8.5);
$pdf->Cell(76, 4.5, (string) config('paz_salvo.cargo', 'SECRETARIO(A) DE HACIENDA MUNICIPAL'), 0, 2, 'C');
$pdf->SetFont('helvetica', '', 7.5);
$pdf->Cell(76, 4, 'Documento expedido en línea en el portal del impuesto predial', 0, 2, 'C');

if ($c['token'] !== '') {
    $url = PazYSalvo::urlValidacion($c['token']);
    $pdf->write2DBarcode($url, 'QRCODE,M', 150, $y - 14, 34, 34, ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false], 'N');
    $pdf->SetXY(135, $y + 21);
    $pdf->SetFont('helvetica', '', 7);
    $pdf->MultiCell(64, 3.4, 'Verifique la autenticidad escaneando el código QR.', 0, 'C');
}

$pdf->SetAutoPageBreak(false);
$pdf->SetY(-24);
$pdf->SetDrawColor(190, 200, 197);
$pdf->Line(12, $pdf->GetY(), 12 + PdfPredial::ANCHO, $pdf->GetY());
$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(79, 98, 93);
$pdf->Cell(PdfPredial::ANCHO, 4, mb_strtoupper($dependencia, 'UTF-8') . ' · ' . config('municipio.correo') . ' · ' . config('municipio.direccion'), 0, 1, 'C');

$pdf->Output('Paz_y_salvo_predial_' . $c['numero'] . '.pdf', 'I');
