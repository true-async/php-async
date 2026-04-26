--TEST--
PDO_SQLite Pool: createFunction / createCollation throw after first acquire freezes the registry
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_sqlite_test.inc';

[$pdo, $path] = AsyncPDOSqliteTest::poolFromTemp();
$pdo->createFunction('shared_upper', fn(string $s) => strtoupper($s), 1);

// First exec acquires a slot — freeze fires.
$pdo->exec("CREATE TABLE t (val TEXT)");

try {
    $pdo->createFunction('late_udf', fn() => 0, 0);
    echo "UNEXPECTED: late createFunction accepted\n";
} catch (PDOException $e) {
    echo "func rejected\n";
}

try {
    $pdo->createCollation('late_coll', fn($a, $b) => 0);
    echo "UNEXPECTED: late createCollation accepted\n";
} catch (PDOException $e) {
    echo "coll rejected\n";
}

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
func rejected
coll rejected
Done
