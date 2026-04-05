--TEST--
PDO MySQL Pool: getAttribute returns pool attributes correctly
--EXTENSIONS--
pdo_mysql
true_async
--SKIPIF--
<?php
require_once __DIR__ . '/inc/async_pdo_mysql_test.inc';
AsyncPDOMySQLTest::skip();
?>
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_mysql_test.inc';

// Pool-enabled PDO
$pdo = AsyncPDOMySQLTest::factory(options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 2,
    PDO::ATTR_POOL_MAX => 7,
]);

echo "pool_enabled: ";
var_dump($pdo->getAttribute(PDO::ATTR_POOL_ENABLED));

echo "pool_min: ";
var_dump($pdo->getAttribute(PDO::ATTR_POOL_MIN));

echo "pool_max: ";
var_dump($pdo->getAttribute(PDO::ATTR_POOL_MAX));

// HEALTHCHECK_INTERVAL is construction-only
echo "pool_healthcheck_interval: ";
try {
    $pdo->getAttribute(PDO::ATTR_POOL_HEALTHCHECK_INTERVAL);
    echo "ERROR: should have raised an error\n";
} catch (\PDOException $e) {
    echo $e->getMessage() . "\n";
}

// Non-pool PDO
$pdo2 = AsyncPDOMySQLTest::factory();

echo "no_pool_enabled: ";
var_dump($pdo2->getAttribute(PDO::ATTR_POOL_ENABLED));

echo "no_pool_min: ";
var_dump($pdo2->getAttribute(PDO::ATTR_POOL_MIN));

echo "no_pool_max: ";
var_dump($pdo2->getAttribute(PDO::ATTR_POOL_MAX));

echo "Done\n";
?>
--EXPECT--
pool_enabled: bool(true)
pool_min: int(2)
pool_max: int(7)
pool_healthcheck_interval: SQLSTATE[IM001]: Driver does not support this function: PDO::ATTR_POOL_HEALTHCHECK_INTERVAL is a construction-only attribute and cannot be read at runtime
no_pool_enabled: bool(false)
no_pool_min: bool(false)
no_pool_max: bool(false)
Done
