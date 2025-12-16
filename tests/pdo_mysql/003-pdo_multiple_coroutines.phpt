--TEST--
PDO MySQL: Multiple coroutines with separate connections
--EXTENSIONS--
pdo_mysql
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

use function Async\spawn;
use function Async\await;
use function Async\await_all_or_fail;

echo "start\n";

// Initialize database first
AsyncPDOMySQLTest::initDatabase();

// Create multiple coroutines with separate connections
$coroutines = [
    spawn(function() {
        $pdo = AsyncPDOMySQLTest::factory();
        $stmt = $pdo->query("SELECT 'coroutine1' as source, CONNECTION_ID() as conn_id");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "from " . $result['source'] . " conn_id: " . $result['conn_id'] . "\n";
        return $result['conn_id'];
    }),
    
    spawn(function() {
        $pdo = AsyncPDOMySQLTest::factory();
        $stmt = $pdo->query("SELECT 'coroutine2' as source, CONNECTION_ID() as conn_id");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "from " . $result['source'] . " conn_id: " . $result['conn_id'] . "\n";
        return $result['conn_id'];
    }),
    
    spawn(function() {
        $pdo = AsyncPDOMySQLTest::factory();
        $stmt = $pdo->query("SELECT 'coroutine3' as source, CONNECTION_ID() as conn_id");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "from " . $result['source'] . " conn_id: " . $result['conn_id'] . "\n";
        return $result['conn_id'];
    })
];

$connectionIds = await_all_or_fail($coroutines);

// Verify all connections are different
$uniqueIds = array_unique($connectionIds);
echo "unique connections: " . count($uniqueIds) . "\n";
echo "total coroutines: " . count($connectionIds) . "\n";

if (count($uniqueIds) === count($connectionIds)) {
    echo "isolation: passed\n";
} else {
    echo "isolation: failed\n";
}

echo "end\n";

?>
--EXPECTF--
start
%A
unique connections: 3
total coroutines: 3
isolation: passed
end