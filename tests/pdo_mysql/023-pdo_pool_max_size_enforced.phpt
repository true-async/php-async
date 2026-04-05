--TEST--
PDO MySQL Pool: max_size limits concurrent connections even with slow queries
--EXTENSIONS--
pdo_mysql
true_async
--SKIPIF--
<?php
require_once __DIR__ . '/inc/async_pdo_mysql_test.inc';
AsyncPDOMySQLTest::skip();
?>
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_mysql_test.inc';

use function Async\spawn;
use function Async\await;
use function Async\delay;

/*
 * 10 coroutines each hold a connection for 500ms via SLEEP.
 * Pool max=3 — only 3 connections should be created.
 * Without the fix, factory yield during connect allowed all 10
 * to pass the max check simultaneously.
 */

$pdo = AsyncPDOMySQLTest::factory(options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 0,
    PDO::ATTR_POOL_MAX => 3,
]);

$pool = $pdo->getPool();

$coros = [];
for ($i = 0; $i < 10; $i++) {
    $coros[] = spawn(function() use ($pdo) {
        $pdo->query("SELECT SLEEP(0.5)");
    });
}

// Check pool count while queries are running
spawn(function() use ($pool) {
    delay(100);
    echo "During: pool=" . $pool->count() . "\n";
});

foreach ($coros as $c) await($c);

echo "After: pool=" . $pool->count() . "\n";
echo "Done\n";
?>
--EXPECT--
During: pool=3
After: pool=3
Done
