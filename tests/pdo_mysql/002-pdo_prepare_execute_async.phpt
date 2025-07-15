--TEST--
PDO MySQL: Async prepare and execute statements
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

echo "start\n";

$result = AsyncPDOMySQLTest::runAsyncTest(function($pdo, $tableName) {
    // Create custom table for this test
    $pdo->exec("DROP TABLE IF EXISTS async_prepare_test");
    $pdo->exec("CREATE TABLE async_prepare_test (id INT, name VARCHAR(50))");
    
    // Test prepared statement
    $stmt = $pdo->prepare("INSERT INTO async_prepare_test (id, name) VALUES (?, ?)");
    $stmt->execute([1, 'first']);
    $stmt->execute([2, 'second']);
    echo "inserted records\n";
    
    // Test prepared select
    $stmt = $pdo->prepare("SELECT * FROM async_prepare_test WHERE id = ?");
    $stmt->execute([1]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "selected: " . $result['name'] . "\n";
    
    // Test count
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM async_prepare_test");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "count: " . $count['cnt'] . "\n";
    
    return "completed";
}, 'async_prepare_test', function($pdo) {
    // Custom setup - no default table needed
}, function($pdo) {
    // Custom cleanup
    $pdo->exec("DROP TABLE IF EXISTS async_prepare_test");
});

echo "result: " . $result . "\n";
echo "end\n";

?>
--EXPECT--
start
inserted records
selected: first
count: 2
result: completed
end