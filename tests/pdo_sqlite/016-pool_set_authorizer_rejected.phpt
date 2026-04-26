--TEST--
PDO_SQLite Pool: setAuthorizer is rejected on a pool template
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_sqlite_test.inc';

[$pdo, $path] = AsyncPDOSqliteTest::poolFromTemp();
try {
    $pdo->setAuthorizer(fn() => Pdo\Sqlite::OK);
    echo "UNEXPECTED: accepted\n";
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
