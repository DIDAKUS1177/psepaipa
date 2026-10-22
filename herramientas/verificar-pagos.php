<?php
/**
 * Tarea programada (cada 15 minutos): consulta en la pasarela (AvalPay o
 * 1cero1pay) los intentos que siguen abiertos y aplica lo que haya pasado. Es el respaldo del aviso
 * (avalpay-aviso.php) y del retorno, por si el contribuyente cerró el
 * navegador o el aviso no llegó. Solo por línea de comandos.
 *
 *   php herramientas/verificar-pagos.php
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/arranque.php';

if (!AvalPay::configurado() && !UnoCeroUno::configurado()) {
    echo "Ninguna pasarela está configurada: nada que hacer.\n";
    exit(0);
}
$enProv = 'proveedor IN (' . implode(', ', array_fill(0, count(PagoLinea::proveedores()), '?')) . ')';

// Abiertos con sesión creada, de los últimos días (los de más de una semana ya
// no cambian: se revisan a mano).
$abiertos = Db::filas(
    "SELECT * FROM generacion_pagos
      WHERE $enProv AND Activo = 1 AND IDTransaccion IS NOT NULL AND IDTransaccion <> ''
        AND created_at >= ?
      ORDER BY IdGeneraPado",
    [...PagoLinea::proveedores(), date('Y-m-d\TH:i:s', time() - 7 * 86400)]
);
$errores = 0;
foreach ($abiertos as $i) {
    if (!PagoLinea::pasarelaLista($i)) {
        continue;
    }
    try {
        $r = PagoLinea::actualizar($i);
        echo $i['IdGeneraPado'] . ' ' . trim((string) $i['factura']) . ': ' . $i['EstadoTransaccion'] . ' -> ' . ($r['intento']['EstadoTransaccion'] ?? '?') . "\n";
    } catch (Throwable $t) {
        $errores++;
        echo $i['IdGeneraPado'] . ': ERROR ' . $t->getMessage() . "\n";
    }
}

// Intentos sin sesión (la pasarela no respondió al crearla): se cierran.
Db::ejecutar(
    "UPDATE generacion_pagos SET EstadoTransaccion = 'FAILED', Mensaje = 'Sin sesión de pago', Activo = 0
      WHERE $enProv AND Activo = 1 AND (IDTransaccion IS NULL OR IDTransaccion = '') AND created_at < ?",
    [...PagoLinea::proveedores(), date('Y-m-d\TH:i:s', time() - 3600)]
);
echo count($abiertos) . " intentos revisados, $errores con error.\n";
exit($errores > 0 ? 1 : 0);
