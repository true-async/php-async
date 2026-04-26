--TEST--
PDO_SQLite Pool: POOL_MAX caps concurrent slot acquisition; coroutines wait
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_sqlite_test.inc';

use function Async\spawn;
use function Async\await;
use function Async\delay;

[$pdo, $path] = AsyncPDOSqliteTest::poolFromTemp([
    PDO::ATTR_POOL_MAX => 1,
]);
$pdo->exec("CREATE TABLE t (val INT)");

// Two coroutines compete for the single available slot. The second one
// must wait until the first releases (here: when its coroutine ends).
$started = [];
$tasks = [];
for ($i = 0; $i < 2; $i++) {
    $tasks[] = spawn(function () use ($pdo, $i, &$started) {
        $started[] = $i . ":enter";
        $pdo->beginTransaction();   // hold the slot
        $started[] = $i . ":holding";
        delay(10);
        $pdo->commit();
        return $i;
    });
}
foreach ($tasks as $t) { await($t); }

// The "holding" markers must be sequential — coroutines cannot both hold the
// only slot at the same time.
echo implode(',', $started), "\n";

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
0:enter,0:holding,1:enter,1:holding
Done
