--TEST--
PDO MySQL: Async transaction handling
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
    // Create test table for transactions
    $pdo->exec("DROP TABLE IF EXISTS async_transaction_test");
    $pdo->exec("CREATE TABLE async_transaction_test (id INT PRIMARY KEY, value VARCHAR(50)) ENGINE=InnoDB");
    
    echo "starting transaction\n";
    $pdo->beginTransaction();
    
    // Insert some data
    $stmt = $pdo->prepare("INSERT INTO async_transaction_test (id, value) VALUES (?, ?)");
    $stmt->execute([1, 'test1']);
    $stmt->execute([2, 'test2']);
    echo "inserted data\n";
    
    // Check data exists in transaction
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM async_transaction_test");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "count in transaction: " . $count['cnt'] . "\n";
    
    // Commit transaction
    $pdo->commit();
    echo "committed\n";
    
    // Verify data persists after commit
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM async_transaction_test");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "count after commit: " . $count['cnt'] . "\n";
    
    // Test rollback with new transaction
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO async_transaction_test (id, value) VALUES (?, ?)");
    $stmt->execute([3, 'test3']);
    echo "inserted test3\n";
    
    $pdo->rollback();
    echo "rolled back\n";
    
    // Verify rollback worked
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM async_transaction_test");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "final count: " . $count['cnt'] . "\n";
    
    return "success";
}, 'async_transaction_test', function($pdo) {
    // Custom setup - no default table needed
}, function($pdo) {
    // Custom cleanup
    $pdo->exec("DROP TABLE IF EXISTS async_transaction_test");
});

echo "result: " . $result . "\n";
echo "end\n";

?>
--EXPECT--
start
starting transaction
inserted data
count in transaction: 2
committed
count after commit: 2
inserted test3
rolled back
final count: 2
result: success
end