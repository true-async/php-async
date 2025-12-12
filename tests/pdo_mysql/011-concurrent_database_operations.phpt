--TEST--
PDO MySQL: Concurrent database operations with automatic setup
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
use function Async\awaitAllOrFail;

echo "start\n";

// Test concurrent operations with automatic table setup and cleanup
$result = AsyncPDOMySQLTest::runAsyncTest(function($pdo, $tableName) {
    echo "database initialized\n";
    
    // Spawn multiple coroutines for concurrent operations
    $coroutines = [
        spawn(function() use ($tableName) {
            $pdo = AsyncPDOMySQLTest::factory();
            $stmt = $pdo->prepare("INSERT INTO {$tableName} (name, value) VALUES (?, ?)");
            $stmt->execute(['async_test_1', 'concurrent_value_1']);
            echo "inserted record 1\n";
            return 1;
        }),
        
        spawn(function() use ($tableName) {
            $pdo = AsyncPDOMySQLTest::factory();
            $stmt = $pdo->prepare("INSERT INTO {$tableName} (name, value) VALUES (?, ?)");
            $stmt->execute(['async_test_2', 'concurrent_value_2']);
            echo "inserted record 2\n";
            return 2;
        }),
        
        spawn(function() use ($tableName) {
            $pdo = AsyncPDOMySQLTest::factory();
            // Query existing data
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM {$tableName}");
            $result = $stmt->fetch();
            echo "initial count: " . $result['count'] . "\n";
            return $result['count'];
        })
    ];
    
    $results = awaitAllOrFail($coroutines);
    
    // Check final state
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM {$tableName}");
    $final = $stmt->fetch();
    echo "final count: " . $final['total'] . "\n";
    
    // Verify all async operations completed
    $stmt = $pdo->query("SELECT * FROM {$tableName} WHERE name LIKE 'async_test_%'");
    $asyncRecords = $stmt->fetchAll();
    echo "async records: " . count($asyncRecords) . "\n";
    
    return "concurrent_test_passed";
});

echo "test result: " . $result . "\n";
echo "end\n";

?>
--EXPECTF--
start
database initialized
initial count: 5
%A
final count: 7
async records: 2
test result: concurrent_test_passed
end