<?php
/* Async PDO_SQLite test helpers.
 *
 * SQLite needs no server — every test creates a temp file DB via tempnam()
 * and unlinks it on shutdown. file:...?cache=shared is the only in-memory
 * mode compatible with the pool (multiple sqlite3* sharing one DB). */

class AsyncPDOSqliteTest
{
    /** Open a fresh PDO\Sqlite over a per-test temp file. Caller registers
     *  shutdown to unlink the file. */
    public static function fileDsn(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'async_pdo_sqlite_');
        $dsn  = "sqlite:" . $path;
        return [$dsn, $path];
    }

    /** DSN for a shared in-memory DB. cache=shared is mandatory for pool
     *  mode — without it each slot gets its own private DB. */
    public static function sharedMemoryDsn(string $name): string
    {
        return "sqlite:file:" . $name . "?mode=memory&cache=shared";
    }

    /** Default pool options. Pass overrides to merge on top. */
    public static function poolOptions(array $overrides = []): array
    {
        return $overrides + [
            PDO::ATTR_POOL_ENABLED        => true,
            PDO::ATTR_POOL_MIN            => 0,
            PDO::ATTR_POOL_MAX            => 4,
            PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
        ];
    }

    /** Open a pooled PDO\Sqlite over a temp file. Returns [pdo, path]. */
    public static function poolFromTemp(array $optionOverrides = []): array
    {
        [$dsn, $path] = self::fileDsn();
        $pdo = Pdo\Sqlite::connect($dsn, null, null, self::poolOptions($optionOverrides));
        return [$pdo, $path];
    }

    /** Best-effort unlink of a path; safe to call multiple times. */
    public static function cleanup(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
}
