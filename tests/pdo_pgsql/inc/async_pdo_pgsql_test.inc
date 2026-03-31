<?php
require_once __DIR__ . '/config.inc';

class AsyncPDOPgSQLTest {

    static function factory(array $options = []): Pdo\Pgsql {
        global $config;
        return new Pdo\Pgsql($config['ENV']['PDOTEST_DSN'], null, null, $options);
    }

    static function poolFactory(int $poolMax = 3, array $extra = []): PDO {
        global $config;
        return new PDO($config['ENV']['PDOTEST_DSN'], null, null, $extra + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_POOL_ENABLED => true,
            PDO::ATTR_POOL_MIN => 0,
            PDO::ATTR_POOL_MAX => $poolMax,
        ]);
    }

    static function dsn(): string {
        global $config;
        return $config['ENV']['PDOTEST_DSN'];
    }

    static function skip(): void {
        try {
            $db = self::factory();
            $db->query("SELECT 1");
        } catch (Exception $e) {
            die('skip PostgreSQL server not available: ' . $e->getMessage());
        }
    }

    static function skipIfNoAsync(): void {
        if (!function_exists('Async\\spawn')) {
            die('skip async extension not loaded');
        }
    }
}
?>
