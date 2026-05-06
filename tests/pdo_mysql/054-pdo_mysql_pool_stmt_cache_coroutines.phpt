--TEST--
PDO MySQL Pool: stmt cache survives coroutine churn on the same physical conn
--EXTENSIONS--
pdo_mysql
true_async
--SKIPIF--
<?php
require_once __DIR__ . '/inc/async_pdo_mysql_test.inc';
AsyncPDOMySQLTest::skipIfNoAsync();
AsyncPDOMySQLTest::skipIfNoPDOMySQL();
AsyncPDOMySQLTest::skip();
?>
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_mysql_test.inc';

/* Single physical conn — all coroutines share it serially. */
$pdo = AsyncPDOMySQLTest::poolFactory(poolMax: 1, extra: [
    PDO::ATTR_POOL_STMT_CACHE_SIZE => 16,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$sql = 'SELECT ? + 1 AS r';

/* Warm the cache once so the count below isolates coroutine reuse. */
Async\await(Async\spawn(function () use ($pdo, $sql) {
    $s = $pdo->prepare($sql);
    $s->execute([0]);
    $s->fetch();
}));

$start = (int)Async\await(Async\spawn(function () use ($pdo) {
    return (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetch(PDO::FETCH_NUM)[1];
}));

$tasks = [];
for ($i = 0; $i < 8; $i++) {
    $tasks[] = Async\spawn(function () use ($pdo, $sql, $i) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$i]);
        return (int)$stmt->fetchColumn();
    });
}

$sum = 0;
foreach ($tasks as $t) {
    $sum += Async\await($t);
}
echo "sum=", $sum, "\n";

$end = (int)Async\await(Async\spawn(function () use ($pdo) {
    return (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetch(PDO::FETCH_NUM)[1];
}));

/* All 8 coroutine prepares hit the cached stmt → 0 new COM_STMT_PREPARE for the
 * SELECT. The closing SHOW STATUS is itself cached → 0. Anything > 1 means a
 * coroutine raced and missed. */
echo ($end - $start) <= 1 ? 'cache-effective' : 'cache-miss-' . ($end - $start), "\n";

echo "Done\n";
?>
--EXPECT--
sum=36
cache-effective
Done
