--TEST--
PDO_SQLite Pool: duplicate name+argc registration on the template throws
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_sqlite_test.inc';

[$pdo, $path] = AsyncPDOSqliteTest::poolFromTemp();
$pdo->createFunction('php_upper', 'strtoupper', 1);

try {
    $pdo->createFunction('php_upper', 'strtoupper', 1);
    echo "UNEXPECTED: duplicate accepted\n";
} catch (PDOException $e) {
    echo "rejected\n";
}

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
rejected
Done
