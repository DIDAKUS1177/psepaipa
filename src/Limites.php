<?php
/**
 * Límites de uso (intentos de inicio de sesión, inscripciones, facturas
 * nuevas) guardados en archivos pequeños fuera de la web, uno por clave.
 * No se usan tablas propias para no tocar el esquema de la base oficial.
 *
 * Si la carpeta no se puede escribir, los límites no frenan (fallan abiertos)
 * y queda anotado en el log: herramientas/verificar.php lo revisa.
 */

declare(strict_types=1);

final class Limites
{
    public static function carpeta(): string
    {
        return (string) (config('dir_datos') ?: RAIZ . '/var') . '/limites';
    }

    private static function ruta(string $clave): string
    {
        return self::carpeta() . '/' . sha1($clave) . '.json';
    }

    /** Marcas de tiempo vigentes de la clave (dentro de la ventana). */
    private static function marcas(string $clave, int $ventana): array
    {
        $r = self::ruta($clave);
        if (!is_file($r)) {
            return [];
        }
        $datos = json_decode((string) @file_get_contents($r), true);
        $limite = time() - $ventana;
        return array_values(array_filter(is_array($datos) ? $datos : [], fn($t) => is_int($t) && $t > $limite));
    }

    public static function cuenta(string $clave, int $ventana): int
    {
        return count(self::marcas($clave, $ventana));
    }

    public static function excedido(string $clave, int $maximo, int $ventana): bool
    {
        return self::cuenta($clave, $ventana) >= $maximo;
    }

    /** Anota un uso. Guarda solo las últimas 24 horas. */
    public static function anotar(string $clave): void
    {
        $dir = self::carpeta();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('[psepaipa] No se puede crear ' . $dir . ': los límites de uso no están funcionando.');
            return;
        }
        $r = self::ruta($clave);
        $f = @fopen($r, 'c+');
        if ($f === false) {
            error_log('[psepaipa] No se puede escribir en ' . $dir . ': los límites de uso no están funcionando.');
            return;
        }
        flock($f, LOCK_EX);
        $datos = json_decode((string) stream_get_contents($f), true);
        $limite = time() - 86400;
        $datos = array_values(array_filter(is_array($datos) ? $datos : [], fn($t) => is_int($t) && $t > $limite));
        $datos[] = time();
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, json_encode($datos));
        fflush($f);
        flock($f, LOCK_UN);
        fclose($f);
    }

    public static function borrar(string $clave): void
    {
        @unlink(self::ruta($clave));
    }

    /** ¿Se puede escribir la carpeta? (para la verificación). */
    public static function escribible(): bool
    {
        $dir = self::carpeta();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $prueba = $dir . '/.prueba';
        $ok = @file_put_contents($prueba, '1') !== false;
        @unlink($prueba);
        return $ok;
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }
}
