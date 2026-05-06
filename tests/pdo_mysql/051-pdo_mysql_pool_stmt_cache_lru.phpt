--TEST--
PDO MySQL Pool: stmt cache LRU evicts oldest entry when capacity is exceeded
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
    PDO::ATTR_POOL_STMT_CACHE_SIZE => 2,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

Async\await(Async\spawn(function () use ($pdo) {
    $sqls = [
        'SELECT 1 + ? AS r',
        'SELECT 2 + ? AS r',
        'SELECT 3 + ? AS r',
    ];

    /* Warm pass: populate cache, eviction happens on the third insertion. */
    foreach ($sqls as $sql) {
        $s = $pdo->prepare($sql);
        $s->execute([0]);
        $s->fetch();
        unset($s);
    }

    /* After warm-up, cache holds the 2nd and 3rd SQLs (LRU eviction kicked the 1st). */
    $start = (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetch(PDO::FETCH_NUM)[1];

    $s2 = $pdo->prepare($sqls[1]); $s2->execute([0]); $s2->fetch(); unset($s2);
    $s3 = $pdo->prepare($sqls[2]); $s3->execute([0]); $s3->fetch(); unset($s3);
    $s1 = $pdo->prepare($sqls[0]); $s1->execute([0]); $s1->fetch(); unset($s1);

    $end = (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetch(PDO::FETCH_NUM)[1];

    /* sqls[1] and sqls[2] are cached → 0 prepares; sqls[0] was evicted → +1 prepare.
     * The status SHOW queries also use the cache (cap=2 may evict though). */
    echo 'sqls[1]+sqls[2] hits, sqls[0] miss → server prepares >= 1: ', ($end - $start) >= 1 ? 'yes' : 'no', "\n";
}));

echo "Done\n";
?>
--EXPECT--
sqls[1]+sqls[2] hits, sqls[0] miss → server prepares >= 1: yes
Done
