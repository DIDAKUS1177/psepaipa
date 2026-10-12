<?php
/**
 * Partes comunes de los PDF del predial (factura y estado de cuenta):
 * encabezado con escudo y recuadro del documento, datos del predio y tabla de
 * vigencias. TCPDF, papel carta.
 *
 * Trampas heredadas del proyecto de ICA: nada de PNG con canal alfa ni
 * códigos de barras como imagen (en Plesk TCPDF no puede escribir
 * temporales); SetAutoPageBreak no avisa si algo se sale de la hoja.
 */

declare(strict_types=1);

final class PdfPredial
{
    const ANCHO = 215.9 - 24; // carta menos márgenes de 12 mm

    public static function crear(string $titulo): TCPDF
    {
        require_once RAIZ . '/lib/tcpdf/tcpdf.php';
        $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->SetCreator('ERPSOFT');
        $pdf->SetAuthor('Alcaldía de ' . config('municipio.ciudad'));
        $pdf->SetTitle($titulo);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 10, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();
        return $pdf;
    }

    /** Encabezado. $caja = [rótulo, valor grande, línea 1, línea 2]. */
    public static function encabezado(TCPDF $pdf, array $caja, array $colorCaja = [23, 117, 111]): void
    {
        $ciudad = (string) config('municipio.ciudad');
        $nit = (string) Db::valor("SELECT TOP 1 valor FROM parametros WHERE nombre = 'nit' ORDER BY CASE WHEN estado = 1 THEN 0 ELSE 1 END, id");
        $escudo = RAIZ . '/public/assets/img/escudo-paipa-pdf.png';
        if (is_file($escudo)) {
            $pdf->Image($escudo, 12, 10, 0, 24);
        }
        $pdf->SetTextColor(18, 32, 29);
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetXY(36, 11);
        $pdf->Cell(100, 6, mb_strtoupper('Alcaldía Municipal de ' . $ciudad, 'UTF-8'), 0, 2, 'L');
        $pdf->SetFont('helvetica', '', 9);
        if ($nit !== '') {
            $pdf->Cell(100, 4.5, 'NIT ' . $nit, 0, 2, 'L');
        }
        $pdf->Cell(100, 4.5, (string) config('municipio.secretaria'), 0, 2, 'L');
        $pdf->SetFont('helvetica', 'B', 9.5);
        $pdf->Cell(100, 5, 'IMPUESTO PREDIAL UNIFICADO', 0, 2, 'L');

        $pdf->SetFillColor($colorCaja[0], $colorCaja[1], $colorCaja[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->RoundedRect(140, 10, 63.9, 26, 2, '1111', 'F');
        $pdf->SetXY(140, 12);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(63.9, 4, $caja[0], 0, 2, 'C');
        $pdf->SetFont('helvetica', 'B', 15);
        $pdf->Cell(63.9, 8, $caja[1], 0, 2, 'C');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(63.9, 4, $caja[2] ?? '', 0, 2, 'C');
        $pdf->Cell(63.9, 4, $caja[3] ?? '', 0, 2, 'C');
        $pdf->SetTextColor(18, 32, 29);
        $pdf->SetXY(12, 38);
    }

    /** Datos del predio; oculta propietarios si quien lo pide no es dueño. */
    public static function predio(TCPDF $pdf, array $p, bool $propio): void
    {
        $nombres = array_map(fn($x) => (string) $x['nombre'], $p['propietarios']);
        $documentos = array_map(fn($x) => (string) $x['identificacion'], $p['propietarios']);
        if (!$propio) {
            $nombres = array_map([Predios::class, 'enmascararNombre'], $nombres);
            $documentos = array_map([Predios::class, 'enmascararDocumento'], $documentos);
        }
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
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
    }

    /**
     * Tabla de vigencias a partir de filas crudas de predios_pagos.
     * Devuelve la suma de total_calculo.
     */
    public static function vigencias(TCPDF $pdf, array $filas): float
    {
        $m = fn($v) => number_format((float) round((float) $v), 0, ',', '.');
        $cab = ['VIGENCIA', 'AVALÚO', 'TARIFA ‰', 'IMPUESTO', 'INTERESES', 'SOBRETASA AMB.', 'INT. SOBRETASA', 'DESCUENTOS', 'OTROS', 'TOTAL'];
        $anchos = [8, 11, 6, 10, 10, 11, 11, 12, 8, 13];
        $t = '<table cellpadding="2.5" border="0.2" style="font-size:7.5px;"><tr style="background-color:#17756f;color:#ffffff;font-weight:bold;">';
        foreach ($cab as $i => $c) {
            $t .= '<td width="' . $anchos[$i] . '%" align="center">' . $c . '</td>';
        }
        $t .= '</tr>';
        $sum = array_fill(0, 7, 0.0);
        foreach ($filas as $i => $r) {
            $imp = (float) $r['valor_concepto1'];
            $int1 = (float) $r['valor_concepto2'];
            $sob = (float) $r['valor_concepto3'];
            $int2 = (float) $r['valor_concepto4'];
            $desc = (float) $r['valor_concepto13'] + (float) $r['valor_concepto15'];
            $tot = (float) $r['total_calculo'];
            $vals = [$imp, $int1, $sob, $int2, $desc, round($tot - $imp - $int1 - $sob - $int2 - $desc), $tot];
            foreach ($vals as $k => $v) {
                $sum[$k] += $v;
            }
            $t .= '<tr' . ($i % 2 ? ' style="background-color:#f4f8f7;"' : '') . '><td align="center"><b>' . (int) $r['ultimo_anio'] . '</b></td>'
                . '<td align="right">' . $m($r['avaluo']) . '</td>'
                . '<td align="center">' . rtrim(rtrim(number_format((float) $r['tarifa'] * 1000, 2, ',', ''), '0'), ',') . '</td>';
            foreach ($vals as $k => $v) {
                $t .= '<td align="right"' . ($k === 6 ? ' style="font-weight:bold;"' : '') . '>' . $m($v) . '</td>';
            }
            $t .= '</tr>';
        }
        $t .= '<tr style="background-color:#e8f4f2;font-weight:bold;"><td colspan="3" align="right">TOTALES</td>';
        foreach ($sum as $v) {
            $t .= '<td align="right">' . $m($v) . '</td>';
        }
        $t .= '</tr></table>';
        $pdf->SetY($pdf->GetY() + 3);
        $pdf->writeHTML($t, false, false, true, false, '');
        return $sum[6];
    }

    public static function pesos($v): string
    {
        return '$ ' . number_format((float) round((float) $v), 0, ',', '.');
    }
}
