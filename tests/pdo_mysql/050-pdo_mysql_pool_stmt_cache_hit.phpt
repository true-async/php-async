--TEST--
PDO MySQL Pool: stmt cache reuses server-side prepared statement on hit
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
    PDO::ATTR_POOL_STMT_CACHE_SIZE => 16,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

Async\await(Async\spawn(function () use ($pdo) {
    $sql = 'SELECT ? + ? AS r';

    $start = (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetch(PDO::FETCH_NUM)[1];

    for ($i = 0; $i < 5; $i++) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$i, 1]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        var_dump((int)$row['r']);
        unset($stmt);
    }

    $end = (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetch(PDO::FETCH_NUM)[1];
    /* Cache must collapse 5 iterations into a single COM_STMT_PREPARE on the wire. */
    echo 'prepares=', $end - $start, "\n";
}));

echo "Done\n";
?>
--EXPECT--
int(1)
int(2)
int(3)
int(4)
int(5)
prepares=1
Done
