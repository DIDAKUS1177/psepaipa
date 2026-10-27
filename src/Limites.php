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
    private static ?string $carpeta = null;

    /**
     * Primera carpeta escribible de: 'dir_datos' del config, var/ del
     * proyecto, la carpeta de sesiones de PHP y la temporal. En Plesk/IIS el
     * proceso web no puede escribir en la carpeta de la aplicación (pasó lo
     * mismo en ICA), pero sí donde guarda las sesiones.
     */
    public static function carpeta(): string
    {
        if (self::$carpeta !== null) {
            return self::$carpeta;
        }
        $candidatas = array_filter([
            config('dir_datos') ? config('dir_datos') . '/limites' : null,
            RAIZ . '/var/limites',
            session_save_path() !== '' ? rtrim(preg_replace('/^\d+;/', '', session_save_path()), '/\\') . '/psepaipa-limites' : null,
            rtrim(sys_get_temp_dir(), '/\\') . '/psepaipa-limites',
        ]);
        foreach ($candidatas as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $prueba = $dir . '/.prueba-' . getmypid();
            if (@file_put_contents($prueba, '1') !== false) {
                @unlink($prueba);
                return self::$carpeta = $dir;
            }
        }
        error_log('[psepaipa] Ninguna carpeta escribible para los límites de uso: no están funcionando.');
        return self::$carpeta = RAIZ . '/var/limites';
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

    /**
     * Reserva un uso solo si no se llegó al tope en la ventana, de forma atómica
     * (comprueba y anota bajo el mismo candado: ráfagas en paralelo no se cuelan).
     * false si ya se llegó al tope o si no se puede escribir la carpeta.
     */
    public static function reservar(string $clave, int $maximo, int $ventana): bool
    {
        $f = @fopen(self::ruta($clave), 'c+');
        if ($f === false) {
            error_log('[psepaipa] No se puede escribir en ' . self::carpeta() . ': los límites de uso no están funcionando.');
            return false;
        }
        flock($f, LOCK_EX);
        $datos = json_decode((string) stream_get_contents($f), true);
        $datos = array_values(array_filter(is_array($datos) ? $datos : [], fn($t) => is_int($t) && $t > time() - 86400));
        $usos = count(array_filter($datos, fn($t) => $t > time() - $ventana));
        $cabe = $usos < $maximo;
        if ($cabe) {
            $datos[] = time();
            ftruncate($f, 0);
            rewind($f);
            fwrite($f, json_encode($datos));
            fflush($f);
        }
        flock($f, LOCK_UN);
        fclose($f);
        return $cabe;
    }

    /** Candado de una sola solicitud a la vez por clave; null si ya hay otra en curso. @return resource|null */
    public static function tomar(string $clave)
    {
        $f = @fopen(self::ruta('candado_' . $clave), 'c');
        if ($f === false) {
            return null;
        }
        if (!flock($f, LOCK_EX | LOCK_NB)) {
            fclose($f);
            return null;
        }
        return $f;
    }

    public static function soltar($candado): void
    {
        if (is_resource($candado)) {
            flock($candado, LOCK_UN);
            fclose($candado);
        }
    }

    public static function borrar(string $clave): void
    {
        @unlink(self::ruta($clave));
    }

    /** ¿Se puede escribir la carpeta? (para la verificación). */
    public static function escribible(): bool
    {
        // Nombre propio de cada petición: varias a la vez no deben pisarse el archivo de prueba.
        $prueba = self::carpeta() . '/.prueba-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $ok = @file_put_contents($prueba, '1') !== false;
        @unlink($prueba);
        return $ok;
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }
}
