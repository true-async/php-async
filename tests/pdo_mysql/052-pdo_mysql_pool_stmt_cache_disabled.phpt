--TEST--
PDO MySQL Pool: stmt cache disabled (size=0) issues a fresh COM_STMT_PREPARE per prepare
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

$pdo = AsyncPDOMySQLTest::poolFactory(poolMax: 1, extra: [
    PDO::ATTR_EMULATE_PREPARES => false,
    /* explicitly leave POOL_STMT_CACHE_SIZE unset → cache disabled */
]);

Async\await(Async\spawn(function () use ($pdo) {
    $sql = 'SELECT ? + 1 AS r';

    $start = (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetch(PDO::FETCH_NUM)[1];
    for ($i = 0; $i < 4; $i++) {
        $s = $pdo->prepare($sql);
        $s->execute([$i]);
        $s->fetch();
        unset($s);
    }
    $end = (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetch(PDO::FETCH_NUM)[1];

    /* No cache: each prepare → fresh COM_STMT_PREPARE.
     * Plus the closing SHOW STATUS query. */
    echo 'prepares=', $end - $start, "\n";
}));

echo "Done\n";
?>
--EXPECT--
prepares=5
Done
