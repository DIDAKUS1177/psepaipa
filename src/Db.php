<?php
/**
 * Conexión a la base de predial (SQL Server). Todo va con parámetros: nada se
 * pega dentro del SQL.
 */

declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'sqlsrv:Server=%s;Database=%s;TrustServerCertificate=1',
                config('db.servidor'),
                config('db.base')
            );
            self::$pdo = new PDO($dsn, (string) config('db.usuario'), (string) config('db.clave'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::SQLSRV_ATTR_ENCODING    => PDO::SQLSRV_ENCODING_UTF8,
            ]);
        }
        return self::$pdo;
    }

    public static function filas(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function fila(string $sql, array $params = []): ?array
    {
        $f = self::filas($sql, $params);
        return $f[0] ?? null;
    }

    public static function valor(string $sql, array $params = [])
    {
        $f = self::fila($sql, $params);
        return $f === null ? null : reset($f);
    }

    public static function ejecutar(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /**
     * Candado de aplicación de SQL Server dentro de la transacción en curso.
     * Lo usan la numeración de facturas y las inscripciones.
     */
    public static function candado(string $recurso, int $esperaMs = 10000): void
    {
        $st = self::pdo()->prepare(
            "DECLARE @r INT; EXEC @r = sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = ?; SELECT @r AS r;"
        );
        $st->execute([$recurso, $esperaMs]);
        do {
            $f = $st->fetch();
            if ($f !== false && isset($f['r'])) {
                if ((int) $f['r'] < 0) {
                    throw new RuntimeException('El sistema está ocupado. Intenta de nuevo en un momento.');
                }
                return;
            }
        } while ($st->nextRowset());
        throw new RuntimeException('No se obtuvo el candado ' . $recurso);
    }
}
