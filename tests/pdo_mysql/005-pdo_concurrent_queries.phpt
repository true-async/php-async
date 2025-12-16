--TEST--
PDO MySQL: Concurrent queries with separate connections
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

// Create coroutines that run concurrent queries
$coroutines = [
    spawn(function() {
        $pdo = AsyncPDOMySQLTest::factory();
        $stmt = $pdo->query("SELECT SLEEP(0.1), 'fast query' as type, CONNECTION_ID() as conn_id");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['type'];
    }),
    
    spawn(function() {
        $pdo = AsyncPDOMySQLTest::factory();
        $stmt = $pdo->query("SELECT SLEEP(0.2), 'medium query' as type, CONNECTION_ID() as conn_id");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['type'];
    }),
    
    spawn(function() {
        $pdo = AsyncPDOMySQLTest::factory();
        $stmt = $pdo->query("SELECT SLEEP(0.05), 'quick query' as type, CONNECTION_ID() as conn_id");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['type'];
    }),
    
    spawn(function() {
        $pdo = AsyncPDOMySQLTest::factory();
        // Test concurrent prepared statements
        $stmt = $pdo->prepare("SELECT ? as message, CONNECTION_ID() as conn_id");
        $stmt->execute(['prepared statement']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['message'];
    })
];

echo "waiting for all queries\n";
$results = await_all_or_fail($coroutines);

echo "all queries completed\n";
echo "results count: " . count($results) . "\n";

// Sort results for consistent output since coroutines are async
sort($results);
foreach ($results as $i => $result) {
    echo "result[$i]: $result\n";
}

echo "end\n";

?>
--EXPECT--
start
waiting for all queries
all queries completed
results count: 4
result[0]: fast query
result[1]: medium query
result[2]: prepared statement
result[3]: quick query
end