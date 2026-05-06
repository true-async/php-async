--TEST--
PDO MySQL Pool: stmt cache stays out of the way when ATTR_EMULATE_PREPARES is true
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

/* Cache size set, but emulate=true → preparer never reaches the cache lookup,
 * no COM_STMT_PREPARE is sent (the wire path is plain COM_QUERY). */
$pdo = AsyncPDOMySQLTest::poolFactory(poolMax: 1, extra: [
    PDO::ATTR_POOL_STMT_CACHE_SIZE => 16,
    PDO::ATTR_EMULATE_PREPARES => true,
]);

Async\await(Async\spawn(function () use ($pdo) {
    $start = (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetch(PDO::FETCH_NUM)[1];

    for ($i = 0; $i < 4; $i++) {
        $s = $pdo->prepare('SELECT ? + 1 AS r');
        $s->execute([$i]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        var_dump((int)$row['r']);
        unset($s);
    }

    $end = (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetch(PDO::FETCH_NUM)[1];
    /* SHOW STATUS itself has no params and emulate=true → COM_QUERY, not COM_STMT_PREPARE.
     * Prepares for the SELECT loop also use COM_QUERY in emulate mode. So delta = 0. */
    echo 'prepares=', $end - $start, "\n";
}));

echo "Done\n";
?>
--EXPECT--
int(1)
int(2)
int(3)
int(4)
prepares=0
Done
