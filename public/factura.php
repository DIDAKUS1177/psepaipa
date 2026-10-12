<?php
/**
 * PDF de la factura (liquidación oficial) para las vigencias elegidas, con
 * cupones de pago y código de barras GS1-128. Solo por POST desde la página
 * del predio: generarla asigna número (ver src/Factura.php).
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

try {
    $p = Predios::detalle($id);
    if ($p === null) {
        throw new RuntimeException('No encontramos ese predio.');
    }
    $f = Factura::generar($id, $desde, $hasta);
} catch (RuntimeException $e) {
    aviso($e->getMessage(), 'error');
    redirigir('predio.php?id=' . $id);
} catch (Throwable $t) {
    error_log('[psepaipa] factura predio ' . $id . ': ' . $t->getMessage());
    aviso('No pudimos generar la factura en este momento. Intenta de nuevo en unos minutos.', 'error');
    redirigir('predio.php?id=' . $id);
}

$propio = Predios::esPropietario($id, $u['documento']);
$numero = $f['numero'];
$ciudad = (string) config('municipio.ciudad');

$pdf = PdfPredial::crear('Factura impuesto predial ' . $numero);
PdfPredial::encabezado($pdf, ['LIQUIDACIÓN OFICIAL No.', $numero, 'Emitida: ' . date('d/m/Y h:i a', strtotime($f['emision'])), 'Generada en línea']);
$pdf->SetFont('helvetica', '', 7.5);
$pdf->MultiCell(PdfPredial::ANCHO, 3.4,
    'La Dirección de Impuestos del Municipio de ' . $ciudad . ', según el Acuerdo 019 de 2022, Decreto 009 de 2017, Decreto 011 de 2017, '
    . 'Resolución 090 de 2017 y Resolución 004 de 2024, determina oficialmente los siguientes periodos gravables del Impuesto Predial '
    . 'Unificado, según los valores e información siguiente:', 0, 'J');
PdfPredial::predio($pdf, $p, $propio);
PdfPredial::vigencias($pdf, $f['filas']);

// ---------- Cupones con código de barras ----------
$ancho = PdfPredial::ANCHO;
$bancos = (array) config('puntos_pago', []);
$altoCupon = 42;
if ($pdf->GetY() + count($f['cupones']) * ($altoCupon + 3) + 22 > 279.4 - 12) {
    $pdf->AddPage();
}
$pdf->SetY($pdf->GetY() + 4);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell($ancho, 5, 'AÑOS A PAGAR: ' . $desde . ($hasta !== $desde ? ' A ' . $hasta : '') . '      VALOR A PAGAR: ' . PdfPredial::pesos($f['cupones'][0]['valor']), 0, 1, 'L');

foreach ($f['cupones'] as $c) {
    $y = $pdf->GetY() + 2;
    $pdf->SetDrawColor(120, 140, 135);
    $pdf->SetLineStyle(['width' => 0.2, 'dash' => '2,1.5']);
    $pdf->Line(12, $y - 1, 12 + $ancho, $y - 1);
    $pdf->SetLineStyle(['width' => 0.25, 'dash' => 0]);
    $pdf->RoundedRect(12, $y + 1, $ancho, $altoCupon, 1.5, '1111', 'D');

    $pdf->SetXY(15, $y + 3);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(60, 4, '- BANCO -   Factura No. ' . $numero, 0, 2, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(60, 4, 'Ref. catastral ' . $p['codigo_predio'], 0, 2, 'L');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(60, 6, 'Pague hasta ' . date('d/m/Y', strtotime($c['hasta'])), 0, 2, 'L');
    if ($c['descuento'] > 0) {
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(60, 4, 'Descuento ' . rtrim(rtrim(number_format($c['descuento'], 2, ',', ''), '0'), ',') . ' %', 0, 2, 'L');
    }
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(60, 7, PdfPredial::pesos($c['valor']), 0, 2, 'L');

    $xb = 80;
    $anchoB = 12 + $ancho - $xb - 4;
    if ($f['ean'] !== null) {
        [$contenido, $legible] = Factura::codigoBarras($f['ean'], $numero, $c['valor'], $c['hasta']);
        $pdf->write1DBarcode($contenido, 'C128', $xb, $y + 4, $anchoB, 24, '',
            ['position' => '', 'border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false, 'text' => false, 'stretch' => true], 'N');
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY($xb, $y + 29);
        $pdf->Cell($anchoB, 3.5, $legible, 0, 0, 'C');
    } else {
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetXY($xb, $y + 14);
        $pdf->MultiCell($anchoB, 4, 'Código de barras no disponible: falta el EAN de recaudo en los parámetros de predial.', 0, 'C');
    }
    if ($bancos) {
        $pdf->SetFont('helvetica', '', 6.5);
        $pdf->SetXY(15, $y + 35);
        $pdf->MultiCell($ancho - 6, 3, 'PUNTOS DE PAGO: ' . implode('  ·  ', array_map(fn($b, $n) => $b . ' ' . $n, array_keys($bancos), $bancos)), 0, 'L');
    }
    $pdf->SetY($y + $altoCupon + 2);
}

$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(79, 98, 93);
$pdf->MultiCell($ancho, 3.5, 'Factura generada en el portal de pago en línea del impuesto predial. Los valores incluyen los intereses calculados a la fecha de emisión; '
    . 'después de la fecha límite debe generarse una nueva factura.', 0, 'L');

$pdf->Output('Factura_predial_' . $numero . '.pdf', 'I');
