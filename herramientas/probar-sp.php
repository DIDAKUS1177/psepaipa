<?php
/**
 * Diagnóstico del cálculo oficial (SP_CALCULO_PREDIAL) para UN predio, SIN
 * guardar nada: cada variante corre dentro de una transacción que se deshace.
 * Sirve para comparar lo que muestra el portal con lo que da el sistema
 * oficial con la fecha de hoy. Solo por línea de comandos.
 *
 *   php herramientas/probar-sp.php <id del predio>
 *
 * (En Plesk: Tareas programadas > Ejecutar un script PHP, con el id en
 * "Argumentos".)
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/arranque.php';

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    exit("Uso: php herramientas/probar-sp.php <id del predio>\n");
}
$anio = (int) date('Y');
$leer = fn() => Db::filas(
    'SELECT ultimo_anio, total_calculo, valor_concepto2 AS intereses FROM predios_pagos pp
      WHERE pp.id_predio = ? AND ' . Predios::SE_DEBE . ' ORDER BY ultimo_anio',
    [$id]
);
$mostrar = function (string $titulo, array $filas): void {
    echo str_pad($titulo, 34) . implode('  ', array_map(
        fn($f) => $f['ultimo_anio'] . '=' . number_format((float) $f['total_calculo'], 0, ',', '.') . ' (int ' . number_format((float) $f['intereses'], 0, ',', '.') . ')',
        $filas
    )) . "\n";
};
$variante = function (string $titulo, callable $correr) use ($leer, $mostrar): void {
    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        $correr();
        $mostrar($titulo, $leer());
    } catch (Throwable $t) {
        echo str_pad($titulo, 34) . 'ERROR ' . $t->getMessage() . "\n";
    } finally {
        $pdo->rollBack();
    }
};

echo 'Predio ' . $id . ', fecha de cálculo ' . hoy() . "\n\n";
$mostrar('Guardado en la base', $leer());
$primera = Predios::primeraDeuda($id);
$variante("Vista previa oficial ($anio, 0)", fn() => Predios::calcular($id, $anio, $anio));
if ($primera !== null && $primera < $anio) {
    $variante("Rango $primera-$anio (solo SP)", fn() => Predios::calcular($id, $primera, $anio));
    $variante("Portal: calcularHoy $primera-$anio", fn() => Predios::calcularHoy($id, $primera, $anio));
}
foreach (array_column($leer(), 'ultimo_anio') as $a) {
    $variante("Solo $a", fn() => Predios::calcular($id, (int) $a, (int) $a));
}
$c = Predios::cotizar($id, null, null, true);
echo "\nLo que muestra el portal al entrar: " . ($c['ok'] ? 'calculado hoy' : 'SIN CALCULAR (cifras guardadas)')
    . ', total a pagar ' . pesos($c['total']) . ' por ' . $c['desde'] . '-' . $c['hastaAnio'] . "\n";
echo "Nada de lo anterior quedó guardado.\n";
