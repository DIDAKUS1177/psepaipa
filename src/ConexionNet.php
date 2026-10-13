<?php
/**
 * Toma la conexión a la base del appsettings.json de la API .NET anterior
 * (serviciospsepaipa), que ya apunta a la base de predial del municipio. Así
 * la clave no se copia a mano ni pasa por nadie: se lee en el servidor al
 * arrancar.
 *
 * Se activa con 'db_appsettings' => ruta en config.local.php. Si el archivo
 * no está o no trae una cadena válida, se usa 'db' tal cual.
 */

declare(strict_types=1);

final class ConexionNet
{
    /** Devuelve ['servidor','base','usuario','clave'] o null. */
    public static function leer(string $ruta, ?string $nombre = null): ?array
    {
        if (!is_file($ruta) || !is_readable($ruta)) {
            return null;
        }
        $texto = (string) file_get_contents($ruta);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto) ?? $texto;
        $json = json_decode($texto, true);
        if (!is_array($json)) {
            // appsettings admite comentarios // y /* */ y comas finales.
            $limpio = preg_replace('~/\*.*?\*/~s', '', $texto) ?? $texto;
            $limpio = preg_replace('~^\s*//.*$~m', '', $limpio) ?? $limpio;
            $limpio = preg_replace('/,\s*([}\]])/', '$1', $limpio) ?? $limpio;
            $json = json_decode($limpio, true);
        }
        if (!is_array($json)) {
            return null;
        }
        $cadenas = [];
        foreach ($json as $k => $v) {
            if (strcasecmp((string) $k, 'ConnectionStrings') === 0 && is_array($v)) {
                $cadenas = $v;
            }
        }
        if (!$cadenas) {
            return null;
        }
        $cadena = $nombre !== null && isset($cadenas[$nombre]) ? $cadenas[$nombre] : reset($cadenas);
        return is_string($cadena) ? self::interpretar($cadena) : null;
    }

    /** "Server=x;Database=y;User Id=z;Password=w;..." → partes. */
    public static function interpretar(string $cadena): ?array
    {
        $partes = [];
        foreach (explode(';', $cadena) as $par) {
            $pos = strpos($par, '=');
            if ($pos === false) {
                continue;
            }
            $clave = strtolower(preg_replace('/\s+/', '', substr($par, 0, $pos)) ?? '');
            $partes[$clave] = trim(substr($par, $pos + 1));
        }
        $tomar = function (array $nombres) use ($partes): string {
            foreach ($nombres as $n) {
                if (isset($partes[$n]) && $partes[$n] !== '') {
                    return $partes[$n];
                }
            }
            return '';
        };
        $servidor = $tomar(['server', 'datasource', 'address', 'addr', 'networkaddress']);
        $base = $tomar(['database', 'initialcatalog']);
        if ($servidor === '' || $base === '') {
            return null;
        }
        $servidor = preg_replace('/^tcp:/i', '', $servidor) ?? $servidor;
        if ($servidor === '.' || strpos($servidor, '.\\') === 0) {
            $servidor = 'localhost' . substr($servidor, 1);
        }
        return [
            'servidor' => $servidor,
            'base'     => $base,
            'usuario'  => $tomar(['userid', 'uid', 'user', 'username']),
            'clave'    => $tomar(['password', 'pwd']),
        ];
    }
}
