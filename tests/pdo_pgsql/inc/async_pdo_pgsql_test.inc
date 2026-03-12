<?php
require_once __DIR__ . '/config.inc';

class AsyncPDOPgSQLTest {

    static function factory(): Pdo\Pgsql {
        global $config;
        return new Pdo\Pgsql($config['ENV']['PDOTEST_DSN']);
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
