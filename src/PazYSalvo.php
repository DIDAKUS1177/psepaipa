<?php
/**
 * Certificado de paz y salvo del predial, como el del sistema oficial
 * (PrediosController::generate_paz_pdf + pazPDF_pai):
 *  - número = AAAA + 5 cifras del consecutivo anios.consecutivo_paz del año en
 *    curso (el mismo que usa la Alcaldía; guarda el último usado), saltando
 *    los que ya existan en predios_validaciones_qr;
 *  - código QR registrado en predios_validaciones_qr (la misma tabla del
 *    sistema oficial), válido hasta el 31 de diciembre del año, con 5
 *    validaciones; se verifica en validar.php (o en la URL del config);
 *  - solo si el predio no debe nada según las MARCAS oficiales (no según el
 *    valor guardado, que puede estar en 0 sin calcular): sin vigencias
 *    pendientes, sin acuerdo de pago activo con cuotas por pagar, con el año
 *    en curso liquidado y pagado (o exento) y sin pago en línea en curso.
 *
 * El PDF lleva el nombre y el documento del propietario completos, como el
 * oficial (lo pidió la Tesorería el 2026-10-14 con su certificado de muestra);
 * en pantalla siguen ocultos. Si el MISMO usuario pide otro del mismo predio el
 * mismo día se le repite el suyo en vez de gastar otro número. Topes por usuario, por IP y por hora:
 * cada certificado nuevo gasta un número del consecutivo oficial.
 */

declare(strict_types=1);

final class PazYSalvo
{
    const MAX_POR_USUARIO_DIA = 5;
    const MAX_POR_IP_DIA      = 10;
    const MAX_GLOBAL_HORA     = 60;

    /** Por qué no se puede expedir, o null si se puede. */
    public static function motivo(int $id): ?string
    {
        $anio = (int) date('Y');
        $pendientes = (int) Db::valor(
            'SELECT COUNT(*) FROM predios_pagos pp
              WHERE pp.id_predio = ? AND ' . Predios::SE_DEBE . ' AND ISNULL(pp.exencion, 0) = 0',
            [$id]
        );
        if ($pendientes > 0) {
            return 'El predio tiene vigencias pendientes de pago.';
        }
        $acuerdo = (int) Db::valor(
            'SELECT COUNT(*) FROM predios_acuerdos_pago ap
              WHERE ap.id_predio = ? AND ap.estado_acuerdo = 1
                AND EXISTS (SELECT 1 FROM predios_acuerdos_pago_detalle d WHERE d.id_acuerdo = ap.id AND d.pagado = 0 AND ISNULL(d.estado, 1) = 1)',
            [$id]
        );
        if ($acuerdo > 0) {
            return 'El predio tiene un acuerdo de pago con cuotas pendientes.';
        }
        $actual = Db::fila('SELECT pagado, exencion FROM predios_pagos WHERE id_predio = ? AND ultimo_anio = ? AND ISNULL(anulada, 0) = 0', [$id, $anio]);
        if ($actual === null) {
            return 'La vigencia ' . $anio . ' todavía no está liquidada para este predio.';
        }
        if ((int) $actual['pagado'] === 0 && (int) $actual['exencion'] === 0) {
            return 'Falta pagar la vigencia ' . $anio . '.';
        }
        if (PagoLinea::enTramite($id) !== null) {
            return 'Hay un pago en línea en curso: espera a que el banco lo confirme.';
        }
        return null;
    }

    /**
     * Expide (o repite el del día del mismo usuario) y devuelve los datos para
     * el PDF. Lanza AvisoUsuario con un mensaje para el contribuyente.
     */
    public static function expedir(int $id, int $idUsuario): array
    {
        $motivo = self::motivo($id);
        if ($motivo !== null) {
            throw new AvisoUsuario('No se puede expedir el paz y salvo: ' . $motivo);
        }
        if (!self::hayTablaQr()) {
            // Sin el QR no se puede verificar: no se gasta un número oficial.
            throw new AvisoUsuario('El paz y salvo en línea no está disponible. Solicítalo en la Dirección de Impuestos.');
        }
        $anio = (int) date('Y');

        // El mismo usuario, el mismo predio, el mismo día: el mismo certificado.
        $previo = $_SESSION['paz'][$id] ?? null;
        if (is_array($previo) && ($previo['fecha'] ?? '') === hoy() && ($previo['usuario'] ?? 0) === $idUsuario
            && Db::valor('SELECT COUNT(*) FROM predios_validaciones_qr WHERE token = ? AND estado = 1', [(string) $previo['token']]) > 0) {
            return ['numero' => (string) $previo['numero'], 'token' => (string) $previo['token'], 'validez' => $anio . '-12-31'];
        }
        if (Limites::excedido('paz_usuario_' . $idUsuario, self::MAX_POR_USUARIO_DIA, 86400)
            || Limites::excedido('paz_ip_' . Limites::ip(), self::MAX_POR_IP_DIA, 86400)) {
            throw new AvisoUsuario('Ya descargaste varios paz y salvo hoy. Intenta mañana o usa el que descargaste.');
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::candado('psepaipa_paz_salvo');
            // Tope del portal (la ventanilla también escribe en la tabla del QR
            // y no se distingue: por eso se cuenta aparte).
            $hora = Limites::cuenta('paz_global', 3600);
            if ($hora >= (int) config('paz_salvo.max_hora', self::MAX_GLOBAL_HORA)) {
                error_log('[psepaipa] Freno global del paz y salvo: ' . $hora . ' en la última hora.');
                throw new AvisoUsuario('En este momento hay muchas solicitudes. Intenta de nuevo en unos minutos.');
            }
            $a = Db::fila('SELECT id, ISNULL(consecutivo_paz, 0) AS c FROM anios WITH (UPDLOCK) WHERE anio = ?', [$anio]);
            if ($a === null) {
                throw new AvisoUsuario('El año ' . $anio . ' no está abierto en el sistema de predial. Comunícate con la Alcaldía.');
            }
            // El sistema oficial toma el número sin este candado: se salta
            // cualquiera que ya exista.
            $n = (int) $a['c'];
            for ($intentos = 0; ; $intentos++) {
                $n++;
                $numero = $anio . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
                if ($n > 99999 || $intentos > 500) {
                    throw new AvisoUsuario('No hay números de paz y salvo disponibles. Comunícate con la Alcaldía.');
                }
                if ((int) Db::valor('SELECT COUNT(*) FROM predios_validaciones_qr WHERE certificado_numero = ?', [$numero]) === 0) {
                    break;
                }
            }
            Db::ejecutar('UPDATE anios SET consecutivo_paz = ? WHERE id = ?', [$n, (int) $a['id']]);

            $token = bin2hex(random_bytes(16)) . hash('sha256', $id . $numero . microtime());
            $principal = Db::valor(
                'SELECT TOP 1 pr.nombre FROM predios_propietarios prp JOIN propietarios pr ON pr.id = prp.id_propietario
                  WHERE prp.id_predio = ? ORDER BY prp.jerarquia',
                [$id]
            );
            $ahora = date('Y-m-d\TH:i:s');
            Db::ejecutar(
                'INSERT INTO predios_validaciones_qr
                    (token, id_predio, certificado_numero, propietario_principal, fecha_expedicion, fecha_validez,
                     is_validated, created_at, updated_at, estado, max_validations, validation_count)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 1, 5, 0)',
                [$token, $id, $numero, mb_substr(trim((string) $principal), 0, 255), hoy(), $anio . '-12-31', $ahora, $ahora]
            );
            $pdo->commit();
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
        Limites::anotar('paz_global');
        Limites::anotar('paz_usuario_' . $idUsuario);
        Limites::anotar('paz_ip_' . Limites::ip());
        $_SESSION['paz'][$id] = ['fecha' => hoy(), 'usuario' => $idUsuario, 'numero' => $numero, 'token' => $token];
        return ['numero' => $numero, 'token' => $token, 'validez' => $anio . '-12-31'];
    }

    /** Avalúo y año que se certifican: los de la vigencia en curso (pagada o exenta). */
    public static function avaluoActual(int $id): ?array
    {
        return Db::fila(
            'SELECT avaluo, ultimo_anio FROM predios_pagos WHERE id_predio = ? AND ultimo_anio = ? AND ISNULL(anulada, 0) = 0',
            [$id, (int) date('Y')]
        );
    }

    /** Último año pagado del predio (para "último pago con factura No."). */
    public static function ultimoPago(int $id): ?array
    {
        return Db::fila(
            'SELECT TOP 1 ultimo_anio, factura_pago, fecha_pago, avaluo FROM predios_pagos
              WHERE id_predio = ? AND pagado <> 0 AND ISNULL(anulada, 0) = 0 ORDER BY ultimo_anio DESC',
            [$id]
        );
    }

    public static function urlValidacion(string $token): string
    {
        $base = (string) config('paz_salvo.url_validacion', '');
        return $base !== '' ? $base . $token : urlPublica() . '/validar.php?t=' . $token;
    }

    public static function hayTablaQr(): bool
    {
        return Db::valor("SELECT OBJECT_ID('predios_validaciones_qr', 'U')") !== null;
    }

    /**
     * Dibuja el certificado (formato oficial de Paipa, A4). Separado de la
     * página para poder probarlo con datos inventados sin gastar números.
     */
    public static function pdf(array $p, array $c, ?array $ultimo, ?array $actual, string $nit, string $codigoPostal, string $destino): TCPDF
    {
        $ciudad = mb_strtoupper((string) config('municipio.ciudad'), 'UTF-8');
        $dependencia = mb_strtoupper((string) config('paz_salvo.dependencia', 'Tesorería Municipal'), 'UTF-8');
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $expedido = $dias[(int) date('w')] . ' ' . (int) date('j') . ' de ' . $meses[(int) date('n') - 1] . ' de ' . date('Y');
        $area = fn($v) => ($v === null || (float) $v == 0.0) ? '0' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $dinero = fn($v) => '$ ' . number_format((float) $v, 2, '.', ',');
        $propietarios = $p['propietarios'];
        $principal = $propietarios[0] ?? null;
        $avaluo = $actual['avaluo'] ?? $ultimo['avaluo'] ?? $p['avaluo'];
        $anioAvaluo = $actual['ultimo_anio'] ?? $ultimo['ultimo_anio'] ?? date('Y');

        $pdf = PdfPredial::crear('Paz y salvo predial ' . $c['numero'], 'A4');
        // La primera hoja dibuja su encabezado con posiciones fijas; el margen de
        // arriba es el de las hojas siguientes (si las hay), que llevan su rótulo.
        $pdf->SetMargins(15, 16, 15);
        $pdf->SetAutoPageBreak(true, 22);   // deja libre el pie
        $ancho = 210 - 30;
        $pie = 297 - 18;                    // línea del pie
        $pdf->SetTextColor(20, 20, 20);

        // ---------- Encabezado: escudo, títulos y QR ----------
        $escudo = RAIZ . '/public/assets/img/escudo-paipa-pdf.png';
        if (is_file($escudo)) {
            $pdf->Image($escudo, 22, 12, 0, 28);
        }
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY(15, 41);
        $pdf->Cell(42, 4, $nit !== '' ? 'Nit ' . $nit : '', 0, 0, 'C');
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetXY(60, 18);
        foreach (['REPÚBLICA DE COLOMBIA', 'ALCALDÍA DE ' . $ciudad, $dependencia, 'CERTIFICADO DE PAZ Y SALVO - IMPUESTO PREDIAL'] as $linea) {
            $pdf->Cell(92, 5, $linea, 0, 2, 'C');
        }
        if ($c['token'] !== '') {
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->Rect(156, 11, 31, 34);
            $pdf->write2DBarcode(PazYSalvo::urlValidacion($c['token']), 'QRCODE,M', 159.5, 13, 24, 24,
                ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false], 'N');
            $pdf->SetFont('helvetica', '', 5.5);
            $pdf->SetXY(156, 39);
            $pdf->Cell(31, 4, 'Verificar autenticidad', 0, 0, 'C');
        }

        // ---------- Certifica ----------
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetXY(15, 52);
        $pdf->Cell($ancho, 6, 'LA ' . $dependencia . ' DE ' . $ciudad, 0, 1, 'C');
        $pdf->Ln(4);
        $pdf->Cell($ancho, 6, 'CERTIFICA', 0, 1, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->writeHTML('<table cellpadding="1"><tr>'
            . '<td width="17%"><b>Certificado No:</b></td><td width="33%"><b>' . $h($c['numero']) . '</b></td>'
            . '<td width="13%"><b>Cod. postal:</b></td><td width="37%">' . $h($codigoPostal) . '</td></tr></table>', true, false, true, false, '');

        $aNombre = $principal !== null
            ? ' el cual figura a nombre de <b>' . $h(trim((string) $principal['nombre'])) . '</b>, documento de identidad número <b>'
              . $h(trim((string) $principal['identificacion'])) . '</b> con las siguientes especificaciones.'
            : ', sin propietario registrado, con las siguientes especificaciones.';
        $texto = '<p style="text-align:justify;line-height:1.5;">Que en los archivos de la ' . $h(mb_strtolower($dependencia, 'UTF-8'))
            . ' aparece inscrito el predio con código catastral número: <b>' . $h($p['codigo_predio']) . '</b>' . $aNombre;
        if ($ultimo !== null && trim((string) $ultimo['factura_pago']) !== '') {
            $texto .= '<br/><b>Último pago con factura No.:</b> ' . $h(trim((string) $ultimo['factura_pago']))
                . ($ultimo['fecha_pago'] ? '<br/><b>Fecha:</b> ' . $h(substr((string) $ultimo['fecha_pago'], 0, 10)) : '');
        }
        $texto .= '</p>';
        $pdf->writeHTML($texto, true, false, true, false, '');
        $pdf->Ln(1);

        // ---------- Predio ----------
        $gris = 'background-color:#f3efef;';
        $pdf->writeHTML('<table cellpadding="2.5" border="0.3" style="font-size:7.5px;">'
            . '<tr style="' . $gris . 'font-weight:bold;"><td rowspan="2" width="40%" align="center">DIRECCIÓN DEL PREDIO</td>'
            . '<td rowspan="2" width="11%" align="center">UBICACIÓN</td><td colspan="3" width="24%" align="center">ÁREA</td>'
            . '<td rowspan="2" width="16%" align="center">VALOR AVALUO</td><td rowspan="2" width="9%" align="center">AÑO<br/>AVALUO</td></tr>'
            . '<tr style="' . $gris . 'font-weight:bold;"><td width="8%" align="center">HA</td><td width="8%" align="center">M2</td><td width="8%" align="center">CONS</td></tr>'
            . '<tr><td width="40%">' . $h($p['direccion']) . '</td><td width="11%" align="center">' . $h($p['zona']) . '</td>'
            . '<td width="8%" align="center">' . $area($p['area_hectareas']) . '</td><td width="8%" align="center">' . $area($p['area_metros']) . '</td>'
            . '<td width="8%" align="center">' . $area($p['area_construida']) . '</td>'
            . '<td width="16%" align="center">' . $dinero($avaluo) . '</td>'
            . '<td width="9%" align="center">' . $h($anioAvaluo) . '</td></tr></table>', true, false, true, false, '');
        $pdf->Ln(3);

        // ---------- Propietarios (hasta 5, como el oficial) ----------
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->Cell($ancho, 5, 'El cual está registrado con los siguientes propietarios:', 0, 1, 'L');
        $pdf->Ln(1);
        $filas = '';
        foreach (array_slice($propietarios, 0, 5) as $pr) {
            $filas .= '<tr><td width="6%">' . $h(str_pad(trim((string) $pr['jerarquia']), 3, '0', STR_PAD_LEFT)) . '</td>'
                . '<td width="14%">' . $h(trim((string) $pr['identificacion'])) . '</td><td width="80%">' . $h(trim((string) $pr['nombre'])) . '</td></tr>';
        }
        if (!$propietarios) {
            $filas = '<tr><td colspan="3">Sin propietario registrado.</td></tr>';
        } elseif (count($propietarios) > 5) {
            $filas .= '<tr><td colspan="3">La lista de propietarios se extiende más allá de la cantidad permitida para mostrar en este documento. '
                . 'Por defecto se muestran los 5 primeros. <b>Número total de propietarios: ' . count($propietarios) . '</b></td></tr>';
        }
        $pdf->writeHTML('<table cellpadding="1.5" border="0.3" style="font-size:7.5px;"><tr style="' . $gris . 'font-weight:bold;">'
            . '<td width="6%">No.</td><td width="14%">Cédula / NIT</td><td width="80%">Nombre</td></tr>' . $filas . '</table>', true, false, true, false, '');
        $pdf->Ln(3);

        $nota = (string) config('paz_salvo.nota', '');
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->writeHTML('<p>El cual se encuentra a PAZ Y SALVO por concepto de impuesto predial.</p>'
            . '<p>Expedido el ' . $h($expedido) . '</p>'
            . '<p>Se expide con destino a: <b>' . $h($destino) . '</b></p>'
            . '<p style="line-height:1.5;">' . ($nota !== '' ? $h($nota) . '<br/>' : '')
            . 'La presente certificación se expide en relación con el predio identificado con cédula catastral No. <b>' . $h($p['codigo_predio']) . '</b></p>',
            true, false, true, false, '');

        // ---------- Validez y firma (bloque de 30 mm; si no cabe, hoja nueva) ----------
        $y = max($pdf->GetY() + 16, 222);
        if ($y + 18 > $pie - 2) {
            $pdf->AddPage();
            $y = 34;
        }
        $firma = (string) config('paz_salvo.firma', '');
        if ($firma !== '' && is_file($firma)) {
            // Cruza la línea de la firma, como en el oficial.
            $pdf->Image($firma, 128, $y - 11, 36, 0);
        }
        $pdf->SetDrawColor(48, 47, 47);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(108, $y + 2, 188, $y + 2);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetXY(108, $y + 3.5);
        $pdf->Cell(80, 4, (string) config('paz_salvo.cargo', 'SECRETARIO(A) DE HACIENDA MUNICIPAL'), 0, 0, 'C');

        $valor = (float) config('paz_salvo.valor', 0);
        $pdf->SetXY(17, $y);
        $pdf->writeHTML('<table cellpadding="1" style="font-size:7.5px;">'
            . '<tr><td>Válido hasta <b>' . $h($c['validez']) . '</b></td></tr>'
            . '<tr><td>Valor <b>' . number_format($valor, 2, '.', ',') . '</b> Pesos m/cte.</td></tr>'
            . '<tr><td>Página ' . $pdf->getAliasNumPage() . ' de ' . $pdf->getAliasNbPages() . '</td></tr>'
            . '<tr><td>Elaboró: <b>' . $h(mb_strtoupper((string) config('paz_salvo.elaboro', 'Portal de pago en línea'), 'UTF-8')) . '</b></td></tr>'
            . '</table>', true, false, true, false, '');

        // ---------- Pie en todas las hojas (y rótulo en las siguientes) ----------
        $paginas = $pdf->getNumPages();
        for ($i = 1; $i <= $paginas; $i++) {
            $pdf->setPage($i);
            // setPage() devuelve el salto automático que la hoja tenía al
            // crearse: se apaga en CADA hoja, o el pie salta a la siguiente.
            $pdf->SetAutoPageBreak(false, 0);
            if ($i > 1) {
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->SetXY(15, 7);
                $pdf->Cell($ancho, 4, 'CERTIFICADO DE PAZ Y SALVO No. ' . $c['numero'] . ' — página ' . $i . ' de ' . $paginas, 0, 0, 'L');
            }
            $pdf->SetDrawColor(192, 192, 192);
            $pdf->SetLineWidth(0.2);
            $pdf->Line(15, $pie, 15 + $ancho, $pie);
            $pdf->SetFont('helvetica', '', 7);
            $pdf->SetXY(15, $pie + 1);
            foreach ([$dependencia, (string) config('paz_salvo.correo', 'tesoreria@paipa-boyaca.gov.co'), (string) config('paz_salvo.direccion', 'Carrera 22 # 25 - 14')] as $linea) {
                $pdf->Cell($ancho, 3.4, $linea, 0, 2, 'C');
            }
        }
        return $pdf;
    }
}
