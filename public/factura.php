<?php
/**
 * PDF de la factura (liquidación oficial) para las vigencias elegidas.
 * Solo por POST desde la página del predio: generarla asigna número.
 *
 * TCPDF con write1DBarcode (vectorial). Nunca imágenes con canal alfa ni
 * códigos como imagen: en Plesk TCPDF no puede escribir temporales (ver las
 * trampas del proyecto de ICA).
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';
require_once RAIZ . '/src/Factura.php';

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
$principal = $p['propietarios'][0] ?? ['nombre' => '', 'identificacion' => ''];
$nombres = array_map(fn($x) => (string) $x['nombre'], $p['propietarios']);
$documentos = array_map(fn($x) => (string) $x['identificacion'], $p['propietarios']);
if (!$propio) {
    $nombres = array_map([Predios::class, 'enmascararNombre'], $nombres);
    $documentos = array_map([Predios::class, 'enmascararDocumento'], $documentos);
}
$nit = (string) Db::valor("SELECT TOP 1 valor FROM parametros WHERE nombre = 'nit' ORDER BY CASE WHEN estado = 1 THEN 0 ELSE 1 END, id");
$ciudad = (string) config('municipio.ciudad');
$numero = $f['numero'];

require_once RAIZ . '/lib/tcpdf/tcpdf.php';

$pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
$pdf->SetCreator('ERPSOFT');
$pdf->SetAuthor('Alcaldía de ' . $ciudad);
$pdf->SetTitle('Factura impuesto predial ' . $numero);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(12, 10, 12);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();
$ancho = 215.9 - 24;

// ---------- Encabezado ----------
$escudo = RAIZ . '/public/assets/img/escudo-paipa-pdf.png';
if (is_file($escudo)) {
    $pdf->Image($escudo, 12, 10, 0, 24);
}
$pdf->SetTextColor(18, 32, 29);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetXY(36, 11);
$pdf->Cell(100, 6, mb_strtoupper('Alcaldía Municipal de ' . $ciudad, 'UTF-8'), 0, 2, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(100, 4.5, 'NIT ' . $nit, 0, 2, 'L');
$pdf->Cell(100, 4.5, (string) config('municipio.secretaria'), 0, 2, 'L');
$pdf->SetFont('helvetica', 'B', 9.5);
$pdf->Cell(100, 5, 'IMPUESTO PREDIAL UNIFICADO', 0, 2, 'L');

$pdf->SetFillColor(23, 117, 111);
$pdf->SetTextColor(255, 255, 255);
$pdf->RoundedRect(140, 10, 63.9, 26, 2, '1111', 'F');
$pdf->SetXY(140, 12);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(63.9, 4, 'LIQUIDACIÓN OFICIAL No.', 0, 2, 'C');
$pdf->SetFont('helvetica', 'B', 15);
$pdf->Cell(63.9, 8, $numero, 0, 2, 'C');
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(63.9, 4, 'Fecha de emisión: ' . date('d/m/Y h:i a', strtotime($f['emision'])), 0, 2, 'C');
$pdf->Cell(63.9, 4, 'Generada en línea', 0, 2, 'C');
$pdf->SetTextColor(18, 32, 29);

$pdf->SetXY(12, 38);
$pdf->SetFont('helvetica', '', 7.5);
$pdf->MultiCell($ancho, 3.4,
    'La Dirección de Impuestos del Municipio de ' . $ciudad . ', según el Acuerdo 019 de 2022, Decreto 009 de 2017, Decreto 011 de 2017, '
    . 'Resolución 090 de 2017 y Resolución 004 de 2024, determina oficialmente los siguientes periodos gravables del Impuesto Predial '
    . 'Unificado, según los valores e información siguiente:', 0, 'J');

// ---------- Predio ----------
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$fila = function ($a, $b, $c, $d) use ($h) {
    return '<tr><td width="18%" style="background-color:#e8f4f2;"><b>' . $a . '</b></td><td width="32%">' . $h($b) . '</td>'
         . '<td width="18%" style="background-color:#e8f4f2;"><b>' . $c . '</b></td><td width="32%">' . $h($d) . '</td></tr>';
};
$html = '<table cellpadding="3" border="0.2" style="font-size:8px;">'
    . $fila('REFERENCIA CATASTRAL', $p['codigo_predio'], 'REFERENCIA ANTERIOR', $p['codigo_predio_anterior'] ?: '—')
    . '<tr><td width="18%" style="background-color:#e8f4f2;"><b>PROPIETARIO(S)</b></td><td width="82%" colspan="3">' . $h(implode(' · ', $nombres)) . '</td></tr>'
    . $fila('C.C. O NIT', implode(' · ', $documentos), 'MATRÍCULA', $p['matricula'] ?: '—')
    . $fila('DIRECCIÓN', $p['direccion'] ?: '—', 'ZONA', $p['zona'] ?: '—')
    . $fila('ÁREA TERRENO', number_format((float) $p['area_metros'], 0, ',', '.') . ' m²', 'ÁREA CONSTRUIDA', number_format((float) $p['area_construida'], 0, ',', '.') . ' m²')
    . '</table>';
$pdf->SetY($pdf->GetY() + 2);
$pdf->writeHTML($html, false, false, true, false, '');

// ---------- Vigencias ----------
$m = function ($v) { return number_format((float) round((float) $v), 0, ',', '.'); };
$cab = ['VIGENCIA', 'AVALÚO', 'TARIFA ‰', 'IMPUESTO', 'INTERESES', 'SOBRETASA AMB.', 'INT. SOBRETASA', 'DESCUENTOS', 'OTROS', 'TOTAL'];
$anchos = [8, 11, 6, 10, 10, 11, 11, 12, 8, 13];
$tabla = '<table cellpadding="2.5" border="0.2" style="font-size:7.5px;"><tr style="background-color:#17756f;color:#ffffff;font-weight:bold;">';
foreach ($cab as $i => $c) {
    $tabla .= '<td width="' . $anchos[$i] . '%" align="center">' . $c . '</td>';
}
$tabla .= '</tr>';
$sum = array_fill(0, 10, 0.0);
foreach ($f['filas'] as $i => $r) {
    $impuesto = (float) $r['valor_concepto1'];
    $int1 = (float) $r['valor_concepto2'];
    $sob = (float) $r['valor_concepto3'];
    $int2 = (float) $r['valor_concepto4'];
    $desc = (float) $r['valor_concepto13'] + (float) $r['valor_concepto15'];
    $tot = (float) $r['total_calculo'];
    $otros = round($tot - $impuesto - $int1 - $sob - $int2 - $desc);
    $vals = [$impuesto, $int1, $sob, $int2, $desc, $otros, $tot];
    foreach ($vals as $k => $v) {
        $sum[$k] += $v;
    }
    $fondo = $i % 2 ? ' style="background-color:#f4f8f7;"' : '';
    $tabla .= '<tr' . $fondo . '><td align="center"><b>' . (int) $r['ultimo_anio'] . '</b></td>'
        . '<td align="right">' . $m($r['avaluo']) . '</td>'
        . '<td align="center">' . rtrim(rtrim(number_format((float) $r['tarifa'] * 1000, 2, ',', ''), '0'), ',') . '</td>';
    foreach ($vals as $k => $v) {
        $tabla .= '<td align="right"' . ($k === 6 ? ' style="font-weight:bold;"' : '') . '>' . $m($v) . '</td>';
    }
    $tabla .= '</tr>';
}
$tabla .= '<tr style="background-color:#e8f4f2;font-weight:bold;"><td colspan="3" align="right">TOTALES</td>';
for ($k = 0; $k < 7; $k++) {
    $tabla .= '<td align="right">' . $m($sum[$k]) . '</td>';
}
$tabla .= '</tr></table>';
$pdf->SetY($pdf->GetY() + 3);
$pdf->writeHTML($tabla, false, false, true, false, '');

// ---------- Cupones con código de barras ----------
$bancos = (array) config('puntos_pago', []);
$altoCupon = 42;
$necesario = count($f['cupones']) * ($altoCupon + 3) + 22;
if ($pdf->GetY() + $necesario > 279.4 - 12) {
    $pdf->AddPage();
}
$pdf->SetY($pdf->GetY() + 4);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell($ancho, 5, 'AÑOS A PAGAR: ' . $desde . ($hasta !== $desde ? ' A ' . $hasta : '') . '      VALOR A PAGAR: $ ' . $m($f['cupones'][0]['valor']), 0, 1, 'L');

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
    $pdf->Cell(60, 7, '$ ' . $m($c['valor']), 0, 2, 'L');

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
