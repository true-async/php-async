--TEST--
PDO PgSQL Pool: Factory handles invalid credentials without leaks or crashes
--EXTENSIONS--
pdo_pgsql
true_async
--SKIPIF--
<?php
require_once __DIR__ . '/inc/async_pdo_pgsql_test.inc';
AsyncPDOPgSQLTest::skipIfNoAsync();
AsyncPDOPgSQLTest::skip();
?>
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_pgsql_test.inc';

use function Async\spawn;
use function Async\await;

$pdo = new PDO(
    'pgsql:host=localhost;port=5432;dbname=test',
    'completely_wrong_user_12345',
    'completely_wrong_password_12345',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_POOL_ENABLED => true,
        PDO::ATTR_POOL_MIN => 0,
        PDO::ATTR_POOL_MAX => 2,
    ]
);

try {
    $coro = spawn(function() use ($pdo) {
        $pdo->query("SELECT 1");
    });
    await($coro);
    echo "ERROR: should have thrown\n";
} catch (\Throwable $e) {
    echo "Caught: " . get_class($e) . "\n";
    echo "Pool acquire failed: OK\n";
}

echo "Done\n";
?>
--EXPECTF--
Caught: %s
Pool acquire failed: OK
Done
