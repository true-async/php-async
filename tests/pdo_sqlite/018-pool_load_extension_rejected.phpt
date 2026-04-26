--TEST--
PDO_SQLite Pool: loadExtension is rejected on a pool template
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--SKIPIF--
<?php
if (!method_exists(Pdo\Sqlite::class, 'loadExtension')) {
    die("skip Pdo\\Sqlite::loadExtension() not available (sqlite built without --enable-load-extension)");
}
?>
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_sqlite_test.inc';

[$pdo, $path] = AsyncPDOSqliteTest::poolFromTemp();
try {
    $pdo->loadExtension('does_not_matter');
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
