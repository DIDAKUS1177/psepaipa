<?php
/**
 * Verificación pública del código QR del paz y salvo (sin iniciar sesión).
 * Misma tabla y reglas del sistema oficial (QrValidationController): válido
 * hasta su fecha de validez y hasta 5 consultas; cada consulta se anota.
 */

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';

$token = (string) ($_GET['t'] ?? '');
$estado = 'invalido';
$q = null;
if (Limites::excedido('validar_ip_' . Limites::ip(), 60, 3600)) {
    $estado = 'ocupado';
} elseif (preg_match('/^[A-Za-z0-9]{32,192}$/', $token)) {
    Limites::anotar('validar_ip_' . Limites::ip());
    try {
        $q = Db::fila(
            'SELECT v.*, p.codigo_predio, p.direccion FROM predios_validaciones_qr v LEFT JOIN predios p ON p.id = v.id_predio
              WHERE v.token = ? AND v.estado = 1',
            [$token]
        );
        if ($q !== null) {
            if ((int) $q['validation_count'] >= (int) $q['max_validations']) {
                $estado = 'agotado';
            } elseif (substr((string) $q['fecha_validez'], 0, 10) < hoy()) {
                $estado = 'vencido';
            } else {
                Db::ejecutar(
                    'UPDATE predios_validaciones_qr SET validation_count = validation_count + 1, is_validated = 1, validated_at = ?,
                            validated_ip = ?, validated_user_agent = ?, updated_at = ? WHERE id = ?',
                    [date('Y-m-d\TH:i:s'), Limites::ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500), date('Y-m-d\TH:i:s'), (int) $q['id']]
                );
                $q['validation_count'] = (int) $q['validation_count'] + 1;
                $estado = 'valido';
            }
        }
    } catch (Throwable $t) {
        error_log('[psepaipa validar] ' . $t->getMessage());
        $estado = 'error';
    }
}

$textos = [
    'valido'   => ['ok', 'chequeo', 'Paz y salvo válido', 'El certificado es auténtico y está vigente. Esta consulta quedó registrada.'],
    'vencido'  => ['error', 'alerta', 'Paz y salvo vencido', 'El certificado existe, pero ya pasó su fecha de validez.'],
    'agotado'  => ['alerta', 'alerta', 'Sin consultas disponibles', 'Este código ya se consultó el número máximo de veces permitido.'],
    'ocupado'  => ['alerta', 'reloj', 'Demasiadas consultas', 'Intenta de nuevo en un rato.'],
    'error'    => ['alerta', 'alerta', 'No se pudo verificar', 'Intenta de nuevo en unos minutos.'],
    'invalido' => ['error', 'alerta', 'Código no válido', 'El código no existe en los registros de la Alcaldía. El documento no es auténtico.'],
];
[$clase, $icono, $titulo, $mensaje] = $textos[$estado];

Vista::abrir('Verificar paz y salvo', ['titulo' => 'Verificar paz y salvo', 'volver' => null, 'icono' => 'escudo']);
?>
<div class="contenedor pagina pago-resumen">
    <section class="tarjeta pago-resultado pago-<?= $clase ?>" aria-labelledby="t-validar">
        <div class="pago-cabeza">
            <span class="pago-icono"><?= Vista::icono($icono) ?></span>
            <div>
                <h2 id="t-validar" class="tarjeta-titulo"><?= e($titulo) ?></h2>
                <p><?= e($mensaje) ?></p>
            </div>
        </div>
        <?php if ($q !== null && $estado !== 'invalido'): ?>
        <dl class="pago-filas">
            <div><dt>Certificado No.</dt><dd class="mono"><?= e($q['certificado_numero']) ?></dd></div>
            <div><dt>Referencia catastral</dt><dd class="mono"><?= e($q['codigo_predio']) ?></dd></div>
            <div><dt>Dirección</dt><dd><?= e($q['direccion']) ?></dd></div>
            <div><dt>Propietario</dt><dd><?= e(strpos((string) $q['propietario_principal'], '*') === false ? Predios::enmascararNombre((string) $q['propietario_principal']) : $q['propietario_principal']) ?></dd></div>
            <div><dt>Expedido</dt><dd><?= e(fechaCorta($q['fecha_expedicion'])) ?></dd></div>
            <div><dt>Válido hasta</dt><dd><?= e(fechaCorta($q['fecha_validez'])) ?></dd></div>
            <div><dt>Consultas</dt><dd><?= (int) $q['validation_count'] ?> de <?= (int) $q['max_validations'] ?></dd></div>
        </dl>
        <?php endif; ?>
    </section>
</div>
<?php
Vista::cerrar();
