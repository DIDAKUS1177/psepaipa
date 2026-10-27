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
        $json = self::decodificar((string) file_get_contents($ruta));
        $cadenas = $json === null ? [] : self::cadenas($json);
        if (!$cadenas) {
            return null;
        }
        $cadena = $nombre !== null && isset($cadenas[$nombre]) ? $cadenas[$nombre] : reset($cadenas);
        return is_string($cadena) ? self::interpretar($cadena) : null;
    }

    /**
     * El correo de notificaciones del appsettings.json de la API anterior
     * (FromEmail, FromPass, Host, Port), o null si falta algo. Lo usa Correo
     * para mandar la contraseña temporal sin copiar la clave a otro archivo.
     */
    public static function correo(string $ruta): ?array
    {
        if (!is_file($ruta) || !is_readable($ruta)) {
            return null;
        }
        $json = self::decodificar((string) file_get_contents($ruta));
        if ($json === null) {
            return null;
        }
        $v = [];
        foreach ($json as $k => $valor) {
            if (is_scalar($valor)) {
                $v[strtolower((string) $k)] = trim((string) $valor);
            }
        }
        if (($v['host'] ?? '') === '' || ($v['fromemail'] ?? '') === '') {
            return null;
        }
        return [
            'host'      => $v['host'],
            'puerto'    => (int) ($v['port'] ?? 587) ?: 587,
            'usuario'   => $v['fromemail'],
            'clave'     => $v['frompass'] ?? '',
            'remitente' => $v['fromemail'],
        ];
    }

    /**
     * Por qué no se pudo leer, sin mostrar valores: solo existencia, permisos,
     * nombres de claves del JSON y nombres de campos de la cadena.
     */
    public static function diagnostico(string $ruta): string
    {
        if (!file_exists($ruta)) {
            return 'el archivo no existe' . (ini_get('open_basedir') ? ' o lo bloquea open_basedir (' . ini_get('open_basedir') . ')' : '');
        }
        if (!is_readable($ruta)) {
            return 'el archivo existe pero PHP no tiene permiso de leerlo';
        }
        $texto = (string) @file_get_contents($ruta);
        if ($texto === '') {
            return 'no se pudo leer el contenido (open_basedir: ' . (ini_get('open_basedir') ?: 'ninguno') . ')';
        }
        $json = self::decodificar($texto);
        if ($json === null) {
            return 'el JSON no se pudo interpretar';
        }
        $cadenas = self::cadenas($json);
        if (!$cadenas) {
            return 'no hay ConnectionStrings; claves del archivo: ' . implode(', ', array_keys($json));
        }
        $partes = [];
        foreach ($cadenas as $nombre => $cadena) {
            $campos = [];
            foreach (explode(';', (string) $cadena) as $par) {
                if (strpos($par, '=') !== false) {
                    $campos[] = trim(substr($par, 0, strpos($par, '=')));
                }
            }
            $partes[] = $nombre . ' [' . implode(', ', $campos) . ']';
        }
        return 'ConnectionStrings trae: ' . implode('; ', $partes);
    }

    private static function cadenas(array $json): array
    {
        foreach ($json as $k => $v) {
            if (strcasecmp((string) $k, 'ConnectionStrings') === 0 && is_array($v)) {
                return $v;
            }
        }
        return [];
    }

    /**
     * JSON de appsettings: admite BOM, comentarios de línea y de bloque
     * fuera de las cadenas y comas finales, como los acepta .NET.
     */
    private static function decodificar(string $texto): ?array
    {
        if (strncmp($texto, "\xEF\xBB\xBF", 3) === 0) {
            $texto = substr($texto, 3);
        }
        $json = json_decode($texto, true);
        if (is_array($json)) {
            return $json;
        }
        $limpio = '';
        $n = strlen($texto);
        $enCadena = false;
        for ($i = 0; $i < $n; $i++) {
            $c = $texto[$i];
            if ($enCadena) {
                $limpio .= $c;
                if ($c === '\\' && $i + 1 < $n) {
                    $limpio .= $texto[++$i];
                } elseif ($c === '"') {
                    $enCadena = false;
                }
                continue;
            }
            if ($c === '"') {
                $enCadena = true;
                $limpio .= $c;
            } elseif ($c === '/' && $i + 1 < $n && $texto[$i + 1] === '/') {
                while ($i < $n && $texto[$i] !== "\n") {
                    $i++;
                }
                $limpio .= "\n";
            } elseif ($c === '/' && $i + 1 < $n && $texto[$i + 1] === '*') {
                $fin = strpos($texto, '*/', $i + 2);
                $i = $fin === false ? $n : $fin + 1;
            } else {
                $limpio .= $c;
            }
        }
        $limpio = preg_replace('/,\s*([}\]])/', '$1', $limpio) ?? $limpio;
        $json = json_decode($limpio, true);
        return is_array($json) ? $json : null;
    }

    /** "Server=x;Database=y;User Id=z;Password=w;..." → partes. */
    public static function interpretar(string $cadena): ?array
    {
        // Partes separadas por ';' fuera de comillas: un valor puede venir
        // entre comillas y llevar ';' dentro (Password="a;b").
        $partes = [];
        $trozo = '';
        $comilla = '';
        $n = strlen($cadena);
        for ($i = 0; $i <= $n; $i++) {
            $c = $i < $n ? $cadena[$i] : ';';
            if ($comilla !== '') {
                if ($c === $comilla) {
                    if ($i + 1 < $n && $cadena[$i + 1] === $comilla) {
                        $trozo .= $c;
                        $i++;
                    } else {
                        $comilla = '';
                    }
                } else {
                    $trozo .= $c;
                }
                continue;
            }
            if (($c === '"' || $c === "'") && trim(substr($trozo, (int) strpos($trozo, '=') + 1)) === '' && strpos($trozo, '=') !== false) {
                $comilla = $c;
                continue;
            }
            if ($c === ';') {
                $pos = strpos($trozo, '=');
                if ($pos !== false) {
                    $clave = strtolower(preg_replace('/\s+/', '', substr($trozo, 0, $pos)) ?? '');
                    $partes[$clave] = trim(substr($trozo, $pos + 1));
                }
                $trozo = '';
                continue;
            }
            $trozo .= $c;
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
        $usuario = $tomar(['userid', 'uid', 'user', 'username']);
        // Sin usuario (seguridad integrada de Windows) PHP no puede entrar igual:
        // mejor no reemplazar la conexión del config.
        if ($servidor === '' || $base === '' || $usuario === '') {
            return null;
        }
        $servidor = preg_replace('/^tcp:/i', '', $servidor) ?? $servidor;
        if ($servidor === '.' || strpos($servidor, '.\\') === 0) {
            $servidor = 'localhost' . substr($servidor, 1);
        }
        return [
            'servidor' => $servidor,
            'base'     => $base,
            'usuario'  => $usuario,
            'clave'    => $tomar(['password', 'pwd']),
        ];
    }
}
