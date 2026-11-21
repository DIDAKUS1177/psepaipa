<?php
/**
 * Hoja de la factura (liquidación oficial) del Impuesto Predial de Paipa: el formato de la
 * Alcaldía ("Factura_Predial_Paipa_A4"), A4, una sola página. Es la misma hoja que imprime el
 * back-office de predial (resources/views/predios/facturaPDF_pai.blade.php): encabezado, predio,
 * vigencias, competencia, total y fecha límite, notificación, firma y dos cupones (banco y
 * municipio) con el código GS1-128.
 *
 * Un solo total y un solo "pague hasta": los del descuento que aplica hoy (Factura::cuponHoja).
 * Las normas y la notificación son el texto validado por el abogado de la Alcaldía: no se
 * cambian sin su visto bueno, y deben seguir iguales a las del back-office.
 *
 * Dibuja con datos que le pasan (no toca la base), para probarlo con datos inventados sin gastar
 * números de factura. Mismas trampas de PdfPredial: nada de PNG con canal alfa ni códigos de
 * barras como imagen, y SetAutoPageBreak no avisa si algo se sale de la hoja (aquí está apagado
 * y se mide a mano: cabe en una página con hasta 5 vigencias más la fila de "Antes de AAAA").
 */

declare(strict_types=1);

final class FacturaPdf
{
    const X = 9.0;       // margen izquierdo
    const W = 192.0;     // ancho útil (A4 = 210 mm)
    const MAX_FILAS = 5; // vigencias que se listan; las anteriores van sumadas en una fila

    const NIT = '891.801.240-1';

    // Normas y notificación: texto validado por el abogado de la Alcaldía (no cambiar sin su visto bueno).
    const COMPETENCIA = 'El Secretario de Hacienda del Municipio de Paipa, en ejercicio de la competencia de liquidación y recaudo asignada por la Resolución 031 de 2023 (Manual de Funciones), el Acuerdo 019 de 2022 y el Decreto 009 de 2017, determina oficialmente a cargo del sujeto pasivo señalado (art. 24 Acuerdo 019 de 2022 y art. 54 Ley 1430 de 2010) el Impuesto Predial Unificado sobre el avalúo catastral vigente (art. 3 Ley 44 de 1990) a la tarifa del art. 25 del Acuerdo 019 de 2022, la sobretasa ambiental (art. 44 Ley 99 de 1993, Corpoboyacá), el alumbrado público (art. 9 Acuerdo 006 de 2026) y los intereses de mora (arts. 634 y 635 E.T., art. 59 Ley 788 de 2002) de ';
    const NOTIFICACION = '<b>Notificación.</b> Se notifica por inserción en la página web del Municipio y publicación simultánea en la cartelera de la Secretaría de Hacienda (art. 69 Ley 1111 de 2006, mod. art. 354 Ley 1819 de 2016; art. 11 Decreto 009 de 2017). El envío al predio o al correo del contribuyente es divulgación adicional y su omisión no invalida la notificación. <b>Recurso.</b> Procede el de reconsideración, por escrito ante la Secretaría de Hacienda, dentro de los dos (2) meses siguientes a la notificación (arts. 720 y 722 E.T.), en la Carrera 22 # 25-14 de Paipa o al correo impuestos@paipa-boyaca.gov.co. <b>Mérito ejecutivo.</b> En firme, este acto presta mérito ejecutivo y su cobro corresponde a la Dirección de Impuestos, Rentas y Jurisdicción Coactiva mediante el procedimiento administrativo de cobro coactivo (art. 69 Ley 1111 de 2006, arts. 823 y 828 E.T.). Los intereses se siguen causando hasta el pago efectivo.';

    private const TEAL = [23, 117, 111];
    private const TEAL_CLARO = [232, 240, 238];
    private const BORDE = [207, 220, 217];
    private const LINEA = [227, 236, 234];
    private const FONDO = [243, 248, 247];
    private const TINTA = [31, 45, 43];
    private const GRIS = [74, 90, 87];

    private const MESES = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];

    /**
     * Dibuja la hoja. Datos:
     *  numero, emision ('Y-m-d H:i:s'), predio (como Predios::detalle), ultimo (?array: fecha_pago, valor_pago),
     *  filas (filas de predios_pagos de las vigencias, de la más vieja a la más nueva), desde, hasta,
     *  cupon (valor, hasta 'Y-m-d'), ean (?string), tasa (?float, % anual), firma (ruta o ''),
     *  firmante, cargo.
     */
    public static function dibujar(array $d): TCPDF
    {
        $p = $d['predio'];
        $cupon = $d['cupon'];
        $valor = (int) $cupon['valor'];
        $hasta = strtotime((string) $cupon['hasta']);
        $expedida = date('d/m/Y', strtotime((string) $d['emision']));
        $vigencias = $d['desde'] === $d['hasta'] ? (string) $d['desde'] : $d['desde'] . ' a ' . $d['hasta'];
        $varias = $d['desde'] !== $d['hasta'];
        $hoja = self::filasHoja($d['filas'], $valor);
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $pdf = PdfPredial::crear('Factura impuesto predial ' . $d['numero'], 'A4');
        $pdf->SetMargins(self::X, 8, self::X);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->setCellHeightRatio(1.25);
        $pdf->SetLineWidth(0.2);

        // Marca de agua muy tenue, debajo de todo.
        $pdf->StartTransform();
        $pdf->SetAlpha(0.06);
        $pdf->SetTextColor(...self::TEAL);
        $pdf->SetFont('helvetica', 'B', 46);
        $pdf->Rotate(24, 105, 118);
        $pdf->Text(24, 126, 'MUNICIPIO DE PAIPA');
        $pdf->SetAlpha(1);
        $pdf->StopTransform();

        // ---------- Encabezado ----------
        $escudo = archivoImagenMunicipio('escudo_pdf');
        if ($escudo !== '') {
            $pdf->Image($escudo, self::X, 8.5, 0, 17);
        }
        $pdf->SetTextColor(...self::TINTA);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(25, 8.5);
        $pdf->Cell(110, 7, 'ALCALDÍA MUNICIPAL DE PAIPA', 0, 2, 'L');
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->Cell(110, 3.6, 'NIT ' . self::NIT, 0, 2, 'L');
        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->Cell(110, 3.6, 'Secretaría de Hacienda', 0, 2, 'L');
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->Cell(110, 3.6, 'Impuesto Predial Unificado', 0, 2, 'L');

        $pdf->SetFillColor(...self::TEAL);
        $pdf->RoundedRect(141, 7, 60, 23, 2.4, '1111', 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 6.8);
        $pdf->SetXY(141, 9.6);
        $pdf->Cell(60, 3.5, 'LIQUIDACIÓN OFICIAL – FACTURA No.', 0, 2, 'C');
        $pdf->SetFont('helvetica', 'B', 17);
        $pdf->Cell(60, 8.5, (string) $d['numero'], 0, 2, 'C');
        $pdf->SetFont('helvetica', '', 6.8);
        $pdf->Cell(60, 3.5, 'Expedida: ' . $expedida . ' · Publicada: ' . $expedida, 0, 2, 'C');

        // ---------- Predio ----------
        $principal = $p['propietarios'][0] ?? null;
        $propietario = $principal !== null ? trim((string) $principal['nombre']) : '';
        $documento = $principal !== null ? trim((string) $principal['identificacion']) : '';
        $zona = self::zona((string) ($p['zona'] ?? ''));
        $direccion = trim((string) $p['direccion']) . ($zona !== '' ? ' · ' . $zona : '');
        $n0 = fn($v) => number_format((float) $v, 0, ',', '.');
        $area = 'Ha ' . $n0($p['area_hectareas'] ?? 0) . '   Área M² ' . $n0($p['area_metros'] ?? 0) . '   Construida ' . $n0($p['area_construida'] ?? 0);
        $ultimo = '';
        if (!empty($p['ultimo_anio_pago'])) {
            $ultimo = (string) $p['ultimo_anio_pago'];
        }
        $u = $d['ultimo'] ?? null;
        if ($u !== null) {
            $f = self::fecha($u['fecha_pago'] ?? '');
            if ($f !== '') {
                $ultimo .= '   Fecha pago ' . $f;
            }
            if (is_numeric($u['valor_pago'] ?? null) && (float) $u['valor_pago'] > 0) {
                $ultimo .= '   Valor pagado $ ' . $n0($u['valor_pago']);
            }
        }

        $y = 33.0;
        $fila = 6.3;
        $w = [33, 75, 33, 51];
        self::celdaDato($pdf, self::X, $y, $w[0], $fila, 'Propietario', true);
        self::celdaDato($pdf, self::X + $w[0], $y, self::W - $w[0], $fila, $propietario, false);
        $y += $fila;
        $filasInfo = [
            ['Código catastral', (string) $p['codigo_predio'], 'NIT / C.C.', $documento],
            ['Dirección del predio', $direccion, 'Área', $area],
            ['Últ. año pagado', $ultimo, 'Vigencias liquidadas', $vigencias],
        ];
        foreach ($filasInfo as $i => $r) {
            $x = self::X;
            foreach ($r as $k => $texto) {
                self::celdaDato($pdf, $x, $y, $w[$k], $fila, $texto, $k % 2 === 0, $i === 2 && $k === 3);
                $x += $w[$k];
            }
            $y += $fila;
        }

        // ---------- Vigencias ----------
        $y += 3.6;
        $pdf->SetY($y);
        $y = self::tabla($pdf, $y, $hoja);

        // ---------- Competencia, total y fecha límite ----------
        $y += 3.5;
        $yCaja = $y;
        $pdf->SetTextColor(...self::TINTA);
        $pdf->setCellPaddings(0, 0, 2.5, 0);
        $pdf->SetFont('helvetica', '', 6.7);
        $pdf->setCellHeightRatio(1.32);
        $letras = self::totalEnLetras($valor);
        $pdf->writeHTMLCell(121, 0, self::X, $y,
            '<div style="text-align:justify;"><b><font color="#17756f">Competencia y determinación.</font></b> ' . $h(self::COMPETENCIA)
            . ($varias ? 'las vigencias' : 'la vigencia') . ' ' . $h($vigencias) . ', por <b><font color="#17756f">' . $h($letras)
            . ' ($' . $n0($valor) . ') M/CTE.</font></b></div>',
            0, 1, false, true, 'J', false);
        $yTexto = $pdf->GetY();
        $pdf->setCellPaddings(0, 0, 0, 0);

        // Total a pagar
        $pdf->SetDrawColor(...self::TEAL);
        $pdf->SetLineWidth(0.5);
        $pdf->RoundedRect(131.5, $yCaja, 38, 21, 2.4, '1111', 'D');
        $pdf->SetLineWidth(0.2);
        $pdf->SetTextColor(...self::TEAL);
        $pdf->SetFont('helvetica', 'B', 6.8);
        $pdf->SetXY(131.5, $yCaja + 4.2);
        $pdf->Cell(38, 3.5, 'TOTAL A PAGAR', 0, 2, 'C');
        $pdf->SetFont('helvetica', 'B', 15);
        $pdf->SetXY(131.5, $yCaja + 10.2);
        $pdf->Cell(38, 7, '$ ' . $n0($valor), 0, 0, 'C', false, '', 1);
        // Pague hasta
        $pdf->SetFillColor(...self::TEAL);
        $pdf->SetDrawColor(...self::TEAL);
        $pdf->SetLineWidth(0.5);
        $pdf->RoundedRect(172.5, $yCaja, 28.5, 21, 2.4, '1111', 'D');
        $pdf->SetLineWidth(0.2);
        $pdf->RoundedRect(172.5, $yCaja, 28.5, 4.8, 2.4, '1001', 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 6.8);
        $pdf->SetXY(172.5, $yCaja + 0.8);
        $pdf->Cell(28.5, 3.5, 'PAGUE HASTA', 0, 0, 'C');
        $pdf->SetTextColor(...self::TEAL);
        $pdf->SetFont('helvetica', 'B', 24);
        $pdf->SetXY(172.5, $yCaja + 6.4);
        $pdf->Cell(28.5, 9, date('d', $hasta), 0, 0, 'C');
        $pdf->SetTextColor(...self::TINTA);
        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->SetXY(172.5, $yCaja + 15.6);
        $pdf->Cell(28.5, 4, self::MESES[(int) date('n', $hasta) - 1] . ' · ' . date('Y', $hasta), 0, 0, 'C');
        $y = max($yTexto, $yCaja + 21) + 2.5;

        // ---------- Intereses de mora ----------
        $pdf->SetTextColor(...self::GRIS);
        $pdf->SetFont('helvetica', '', 6.7);
        $pdf->SetXY(self::X, $y);
        $tasa = isset($d['tasa']) && $d['tasa'] !== null ? 'de ' . number_format((float) $d['tasa'], 2, ',', '.') . ' % E.A.' : 'vigente';
        $pdf->MultiCell(self::W, 3.2, 'Intereses de mora liquidados al ' . $expedida . ' a la tasa ' . $tasa
            . ', por cada día de retardo (arts. 634 y 635 E.T.). Si paga después de la fecha límite, solicite una nueva liquidación.', 0, 'L', false, 1);
        $y = $pdf->GetY() + 1.5;

        // ---------- Notificación ----------
        $pdf->SetTextColor(...self::TINTA);
        $pdf->SetFont('helvetica', '', 6.6);
        $pdf->setCellHeightRatio(1.32);
        $pdf->SetFillColor(...self::FONDO);
        $pdf->SetLineStyle(['width' => 0.9, 'color' => self::TEAL]);
        $pdf->setCellPaddings(3, 2, 3, 2);
        $pdf->writeHTMLCell(self::W, 0, self::X, $y, '<div style="text-align:justify;">' . self::NOTIFICACION . '</div>', 'L', 1, true, true, 'J', true);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->SetLineStyle(['width' => 0.2, 'color' => self::TINTA]);
        $y = $pdf->GetY() + 3;

        // ---------- Atención al contribuyente y firma ----------
        $yFirma = $y + 13;   // línea de la firma
        $pdf->SetTextColor(...self::GRIS);
        $pdf->SetFont('helvetica', 'B', 6.9);
        $pdf->SetXY(self::X, $yFirma - 6);
        $pdf->Cell(95, 3.2, 'Atención al contribuyente:', 0, 2, 'L');
        $pdf->SetFont('helvetica', '', 6.9);
        $pdf->Cell(95, 3.2, 'Carrera 22 # 25-14, Paipa · Tel. ' . (string) config('municipio.telefono', '318 530 9285'), 0, 2, 'L');
        $pdf->Cell(95, 3.2, (string) config('municipio.correo', 'impuestos@paipa-boyaca.gov.co'), 0, 2, 'L');

        $firma = (string) ($d['firma'] ?? '');
        if ($firma !== '' && is_file($firma)) {
            // Solo la parte de arriba de la imagen (como en la hoja de la Alcaldía): el trazo
            // largo de abajo se montaría sobre el nombre.
            $pdf->StartTransform();
            try {
                $pdf->Rect(146, $yFirma - 12, 40, 11.6, 'CNZ');
                $pdf->Image($firma, 150, $yFirma - 12, 32, 0);
            } catch (Throwable $t) {
                // Una firma ilegible no debe impedir la factura: se imprime con el espacio en blanco.
                error_log('[psepaipa] firma de la factura: ' . $t->getMessage());
            }
            $pdf->StopTransform();
        }
        $pdf->SetDrawColor(...self::TINTA);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(131, $yFirma, 201, $yFirma);
        $pdf->SetLineWidth(0.2);
        $pdf->SetTextColor(...self::TINTA);
        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->SetXY(131, $yFirma + 0.8);
        $pdf->Cell(70, 3.4, mb_strtoupper((string) $d['firmante'], 'UTF-8'), 0, 2, 'C');
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->Cell(70, 3.4, (string) $d['cargo'], 0, 2, 'C');
        $pdf->SetTextColor(...self::GRIS);
        $pdf->SetFont('helvetica', '', 5.8);
        $pdf->Cell(70, 3, 'Firma mecánica - art. 12 Decreto Ley 2150 de 1995', 0, 2, 'C');
        $y = $yFirma + 11.5;

        // ---------- Cupones: banco y municipio ----------
        foreach (['BANCO' => 'el banco', 'MUNICIPIO' => 'el municipio'] as $rotulo => $para) {
            $y = self::cupon($pdf, $y, $rotulo, $para, $d, $valor, $hasta, $vigencias);
        }

        // ---------- Puntos de pago y pie ----------
        $bancos = (array) config('puntos_pago', []);
        $pdf->SetTextColor(...self::GRIS);
        if ($bancos) {
            $pdf->SetFont('helvetica', '', 6.7);
            $pdf->SetXY(self::X, $y + 1);
            $pdf->MultiCell(self::W, 3.1, '<b>Puntos de pago:</b> ' . implode(' · ', array_map(fn($b, $n) => $h($b) . ' ' . $h($n), array_keys($bancos), $bancos)),
                0, 'L', false, 1, '', '', true, 0, true);
            $y = $pdf->GetY();
        }
        $pdf->SetFont('helvetica', '', 5.6);
        $pdf->SetTextColor(138, 154, 151);
        $pdf->SetXY(self::X, $y + 2);
        $pdf->Cell(self::W, 3, 'Diseñado e impreso por SISTEMAS ERPSOFT SAS', 0, 0, 'C');

        return $pdf;
    }

    // ---------------------------------------------------------------------------------------------
    // Piezas de la hoja
    // ---------------------------------------------------------------------------------------------

    /** Celda del cuadro del predio: rótulo (con fondo) o dato. El texto se encoge si no cabe. */
    private static function celdaDato(TCPDF $pdf, float $x, float $y, float $w, float $h, string $texto, bool $rotulo, bool $negrita = false): void
    {
        $pdf->SetDrawColor(...self::BORDE);
        $pdf->SetLineWidth(0.2);
        if ($rotulo) {
            $pdf->SetFillColor(...self::TEAL_CLARO);
        }
        $pdf->Rect($x, $y, $w, $h, $rotulo ? 'DF' : 'D');
        $pdf->SetTextColor(...self::TINTA);
        $pdf->SetFont('helvetica', $rotulo || $negrita ? 'B' : '', 7.6);
        $pdf->setCellPaddings(1.8, 0, 1.8, 0);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, $texto, 0, 0, 'L', false, '', 1, false, 'T', 'M');
        $pdf->setCellPaddings(0, 0, 0, 0);
    }

    /**
     * Tabla de vigencias. Devuelve la y donde termina.
     * $hoja = ['filas' => [...], 'tot' => [...], 'otros' => bool] (ver filasHoja).
     */
    private static function tabla(TCPDF $pdf, float $y, array $hoja): float
    {
        $otros = $hoja['otros'];
        $cab = ['Vigencia', "Avalúo\ncatastral", "Tarifa\n‰", "Impuesto\npredial", "Intereses\npredial", "Sobretasa\nambiental", "Intereses\nsobretasa", "Alumbrado\npúblico"];
        $campos = ['anio', 'avaluo', 'tarifa', 'impuesto', 'int_imp', 'car', 'int_car', 'alumbrado'];
        $anchos = $otros ? [17, 23, 10, 18, 18, 18, 18, 18] : [18, 25, 11, 20, 20, 20, 20, 20];
        if ($otros) {
            $cab[] = 'Otros';
            $campos[] = 'otros';
            $anchos[] = 17;
        }
        $cab[] = 'Descuentos';
        $campos[] = 'descuento';
        $anchos[] = $otros ? 16 : 19;
        $cab[] = 'Total';
        $campos[] = 'total';
        $anchos[] = $otros ? 19 : 19;

        $n0 = fn($v) => number_format((float) round((float) $v), 0, ',', '.');
        $pdf->SetFillColor(...self::TEAL);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 6.6);
        $pdf->SetXY(self::X, $y);
        foreach ($cab as $i => $t) {
            $pdf->MultiCell($anchos[$i], 8.6, $t, 0, 'C', true, 0, '', '', true, 0, false, true, 8.6, 'M');
        }
        $y += 8.6;

        $pdf->SetDrawColor(...self::LINEA);
        $pdf->SetTextColor(...self::TINTA);
        $alto = 6.2;
        foreach ($hoja['filas'] as $r) {
            $x = self::X;
            foreach ($campos as $i => $c) {
                if ($c === 'anio') {
                    $t = $r['acumulada'] ? 'Antes de ' . $r['anio'] : $r['anio'];
                    $a = 'C';
                } elseif ($c === 'avaluo') {
                    $t = $r['acumulada'] ? '' : $n0($r['avaluo']);
                    $a = 'R';
                } elseif ($c === 'tarifa') {
                    $t = $r['acumulada'] ? '' : rtrim(rtrim(number_format((float) $r['tarifa'], 2, ',', ''), '0'), ',');
                    $a = 'C';
                } else {
                    $t = $n0($r[$c]);
                    $a = 'R';
                }
                $pdf->SetFont('helvetica', $c === 'anio' || $c === 'total' ? 'B' : '', 7.8);
                $pdf->setCellPaddings(0.8, 0, 1.2, 0);
                $pdf->SetXY($x, $y);
                $pdf->Cell($anchos[$i], $alto, $t, 'B', 0, $a, false, '', 1, false, 'T', 'M');
                $x += $anchos[$i];
            }
            $y += $alto;
        }
        // Totales
        $pdf->SetFillColor(...self::FONDO);
        $pdf->Rect(self::X, $y, self::W, 6.4, 'F');
        $pdf->SetDrawColor(...self::TEAL);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(self::X, $y, self::X + self::W, $y);
        $pdf->SetLineWidth(0.2);
        $x = self::X;
        $pdf->SetFont('helvetica', 'B', 7.8);
        foreach ($campos as $i => $c) {
            $t = $c === 'anio' ? 'Total' : (in_array($c, ['avaluo', 'tarifa'], true) ? '' : $n0($hoja['tot'][$c]));
            $pdf->setCellPaddings(0.8, 0, 1.2, 0);
            $pdf->SetXY($x, $y);
            $pdf->Cell($anchos[$i], 6.4, $t, 0, 0, $c === 'anio' ? 'C' : 'R', false, '', 1, false, 'T', 'M');
            $x += $anchos[$i];
        }
        $pdf->setCellPaddings(0, 0, 0, 0);
        return $y + 6.4;
    }

    /** Un cupón (banco o municipio) con su código GS1-128. Devuelve la y donde termina. */
    private static function cupon(TCPDF $pdf, float $y, string $rotulo, string $para, array $d, int $valor, int $hasta, string $vigencias): float
    {
        $p = $d['predio'];
        $n0 = fn($v) => number_format((float) $v, 0, ',', '.');
        // Línea de corte con su rótulo
        $pdf->SetDrawColor(125, 143, 140);
        $pdf->SetLineStyle(['width' => 0.2, 'dash' => '1.6,1.2', 'color' => [125, 143, 140]]);
        $pdf->Line(self::X, $y, self::X + self::W, $y);
        $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0]);
        $pdf->SetFont('helvetica', '', 6.4);
        $pdf->SetTextColor(106, 122, 119);
        $t = 'Cupón para ' . $para;
        $tw = $pdf->GetStringWidth($t) + 4;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetXY(self::X + (self::W - $tw) / 2, $y - 1.6);
        $pdf->Cell($tw, 3.2, $t, 0, 0, 'C', true);
        $y += 2.6;

        $alto = 32.5;
        $pdf->SetDrawColor(...self::BORDE);
        $pdf->SetLineWidth(0.5);
        $pdf->RoundedRect(self::X, $y, self::W, $alto, 2.4, '1111', 'D');
        $pdf->SetLineWidth(0.2);

        $pdf->SetTextColor(...self::TINTA);
        $pdf->SetFont('helvetica', '', 7.6);
        $pdf->SetXY(self::X + 3.5, $y + 3);
        $pdf->writeHTMLCell(75, 4, self::X + 3.5, $y + 3, '<font color="#17756f"><b>' . $rotulo . '</b></font> · Factura No. <b>' . htmlspecialchars((string) $d['numero']) . '</b>', 0, 0);
        $pdf->SetXY(self::X + 78, $y + 3);
        $pdf->Cell(60, 4, 'Ref. catastral ' . $p['codigo_predio'], 0, 0, 'C', false, '', 1);
        $pdf->SetXY(self::X + 140, $y + 3);
        $pdf->Cell(self::W - 143.5, 4, 'Vigencias ' . $vigencias, 0, 0, 'R', false, '', 1);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(self::X + 3.5, $y + 8.2);
        $pdf->Cell(100, 5, 'Pague hasta ' . date('d/m/Y', $hasta), 0, 0, 'L');
        $pdf->SetXY(self::X + self::W - 63.5, $y + 8.2);
        $pdf->Cell(60, 5, '$ ' . $n0($valor), 0, 0, 'R');

        if ($d['ean'] !== null) {
            [$contenido, $legible] = Factura::codigoBarras($d['ean'], (string) $d['numero'], $valor, date('Y-m-d', $hasta));
            $pdf->write1DBarcode($contenido, 'C128', self::X + 11, $y + 14.6, self::W - 22, 13, '',
                ['position' => '', 'border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false, 'text' => false, 'stretch' => true], 'N');
            $pdf->SetFont('helvetica', '', 6.8);
            $pdf->SetXY(self::X, $y + 28.2);
            $pdf->Cell(self::W, 3.2, $legible, 0, 0, 'C');
        } else {
            $pdf->SetFont('helvetica', 'I', 7.5);
            $pdf->SetXY(self::X, $y + 19);
            $pdf->Cell(self::W, 4, 'Código de barras no disponible: falta el EAN de recaudo en los parámetros de predial.', 0, 0, 'C');
        }
        return $y + $alto + 3.6;
    }

    // ---------------------------------------------------------------------------------------------
    // Datos de la tabla
    // ---------------------------------------------------------------------------------------------

    /**
     * Filas de la hoja a partir de predios_pagos. Cada una debe sumar su total: lo que no cabe en
     * las columnas (bomberil, saldos, otros conceptos) va a "Otros", que solo aparece si algún
     * predio lo necesita. Con más de MAX_FILAS vigencias, las más viejas van sumadas en una fila
     * "Antes de AAAA" (como en el sistema oficial). El valor a pagar manda: si el descuento que
     * aplica es el de la segunda o tercera fecha, la última vigencia lo absorbe en "Descuentos".
     *
     * @return array{filas: array, tot: array, otros: bool}
     */
    public static function filasHoja(array $filas, int $valor): array
    {
        $filas = array_values($filas);
        $a = fn(array $f) => [
            'anio'      => (string) (int) $f['ultimo_anio'],
            'avaluo'    => (float) $f['avaluo'],
            'tarifa'    => (float) $f['tarifa'] * 1000,
            'impuesto'  => (float) $f['valor_concepto1'],
            'int_imp'   => (float) $f['valor_concepto2'],
            'car'       => (float) $f['valor_concepto3'],
            'int_car'   => (float) $f['valor_concepto4'],
            'alumbrado' => (float) ($f['valor_concepto18'] ?? 0),
            'descuento' => (float) ($f['valor_concepto13'] ?? 0) + (float) ($f['valor_concepto15'] ?? 0),
            'total'     => (float) $f['total_calculo'],
            'acumulada' => false,
        ];
        $filas = array_map($a, $filas);

        if (count($filas) > self::MAX_FILAS) {
            $viejas = array_slice($filas, 0, count($filas) - self::MAX_FILAS);
            $recientes = array_slice($filas, count($filas) - self::MAX_FILAS);
            $suma = ['anio' => $recientes[0]['anio'], 'avaluo' => 0.0, 'tarifa' => 0.0, 'acumulada' => true];
            foreach (['impuesto', 'int_imp', 'car', 'int_car', 'alumbrado', 'descuento', 'total'] as $c) {
                $suma[$c] = array_sum(array_column($viejas, $c));
            }
            $filas = array_merge([$suma], $recientes);
        }

        if ($filas) {
            $ult = count($filas) - 1;
            $ajuste = $valor - array_sum(array_column($filas, 'total'));
            if (abs($ajuste) >= 1) {
                $filas[$ult]['descuento'] += $ajuste;
                $filas[$ult]['total'] += $ajuste;
            }
        }

        $hayOtros = false;
        $tot = array_fill_keys(['impuesto', 'int_imp', 'car', 'int_car', 'alumbrado', 'otros', 'descuento', 'total'], 0.0);
        foreach ($filas as $k => $f) {
            $otros = $f['total'] - ($f['impuesto'] + $f['int_imp'] + $f['car'] + $f['int_car'] + $f['alumbrado'] + $f['descuento']);
            $filas[$k]['otros'] = abs($otros) >= 1 ? $otros : 0.0;
            $hayOtros = $hayOtros || $filas[$k]['otros'] != 0;
            foreach ($tot as $c => $v) {
                $tot[$c] += $filas[$k][$c];
            }
        }
        return ['filas' => $filas, 'tot' => $tot, 'otros' => $hayOtros];
    }

    /** "Zona urbana", "Zona rural"... para la línea de dirección. */
    public static function zona(string $descripcion): string
    {
        $d = trim($descripcion);
        if ($d === '') {
            return '';
        }
        $mapa = ['urbano' => 'urbana', 'rural' => 'rural', 'sub urbano' => 'suburbana', 'expansion' => 'de expansión'];
        $k = mb_strtolower($d, 'UTF-8');
        return 'Zona ' . ($mapa[$k] ?? $k);
    }

    /** d/m/Y, o '' si el dato no es una fecha. */
    public static function fecha($valor): string
    {
        $v = trim((string) $valor);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            return '';
        }
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    // ---------------------------------------------------------------------------------------------
    // Valor en letras: "DOSCIENTOS VEINTIDÓS MIL TRESCIENTOS PESOS"
    // ---------------------------------------------------------------------------------------------

    private const UNIDADES = [
        '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE',
        'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIUNO',
        'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE',
    ];
    private const DECENAS = [3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA', 7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA'];
    private const CENTENAS = [1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS', 6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS'];

    public static function totalEnLetras($valor): string
    {
        $n = (int) round((float) $valor);
        if ($n <= 0) {
            return 'CERO PESOS';
        }
        if ($n === 1) {
            return 'UN PESO';
        }
        $millones = intdiv($n, 1000000);
        $resto = $n % 1000000;
        $texto = '';
        if ($millones === 1) {
            $texto = 'UN MILLÓN';
        } elseif ($millones > 1) {
            $texto = self::hastaMillon($millones) . ' MILLONES';
        }
        if ($resto > 0) {
            return $texto . ($texto !== '' ? ' ' : '') . self::hastaMillon($resto) . ' PESOS';
        }
        return $texto . ' DE PESOS';
    }

    /** 1 a 999.999 con "UN" (y no "UNO") delante de una unidad, como se lee antes de MIL, MILLONES y PESOS. */
    private static function hastaMillon(int $n): string
    {
        $miles = intdiv($n, 1000);
        $resto = $n % 1000;
        $t = '';
        if ($miles > 0) {
            $t = $miles === 1 ? 'MIL' : self::apocope(self::hastaMil($miles)) . ' MIL';
        }
        if ($resto > 0) {
            $t .= ($t !== '' ? ' ' : '') . self::apocope(self::hastaMil($resto));
        }
        return $t;
    }

    private static function hastaMil(int $n): string
    {
        if ($n === 100) {
            return 'CIEN';
        }
        $c = intdiv($n, 100);
        $r = $n % 100;
        $t = $c > 0 ? self::CENTENAS[$c] : '';
        if ($r > 0) {
            $t .= $t !== '' ? ' ' : '';
            if ($r < 30) {
                $t .= self::UNIDADES[$r];
            } else {
                $u = $r % 10;
                $t .= self::DECENAS[intdiv($r, 10)] . ($u > 0 ? ' Y ' . self::UNIDADES[$u] : '');
            }
        }
        return $t;
    }

    private static function apocope(string $t): string
    {
        if (substr($t, -9) === 'VEINTIUNO') {
            return substr($t, 0, -9) . 'VEINTIÚN';
        }
        if (substr($t, -3) === 'UNO') {
            return substr($t, 0, -3) . 'UN';
        }
        return $t;
    }
}
