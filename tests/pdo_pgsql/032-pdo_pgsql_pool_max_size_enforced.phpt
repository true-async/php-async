--TEST--
PDO PgSQL Pool: max_size limits concurrent connections even with slow queries
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
use function Async\delay;

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 3);
$pool = $pdo->getPool();

$coros = [];
for ($i = 0; $i < 10; $i++) {
    $coros[] = spawn(function() use ($pdo) {
        $pdo->query("SELECT pg_sleep(0.5)");
    });
}

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
