--TEST--
PDO_SQLite Pool: same UDF name with different arity registers as two functions
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_sqlite_test.inc';

use function Async\spawn;
use function Async\await;

[$pdo, $path] = AsyncPDOSqliteTest::poolFromTemp();

$pdo->createFunction('repeat_x', fn(string $s) => $s, 1);
$pdo->createFunction('repeat_x', fn(string $s, int $n) => str_repeat($s, $n), 2);

$row = await(spawn(function () use ($pdo) {
    $a = $pdo->query("SELECT repeat_x('hi') AS a")->fetch()['a'];
    $b = $pdo->query("SELECT repeat_x('hi', 3) AS b")->fetch()['b'];
    return [$a, $b];
}));
echo "a=", $row[0], " b=", $row[1], "\n";

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
a=hi b=hihihi
Done
