--TEST--
PDO MySQL: Async transaction handling
--EXTENSIONS--
pdo_mysql
--SKIPIF--
<?php
if (!extension_loaded('pdo_mysql')) die('skip pdo_mysql not available');
if (!getenv('MYSQL_TEST_HOST')) die('skip MYSQL_TEST_HOST not set');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "start\n";

$coroutine = spawn(function() {
    $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
    $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
    $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
    
    try {
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
        
        // Create test table
        $pdo->exec("DROP TABLE IF EXISTS async_transaction_test");
        $pdo->exec("CREATE TEMPORARY TABLE async_transaction_test (id INT PRIMARY KEY, value VARCHAR(50))");
        
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
        
        // Test rollback
        $pdo->beginTransaction();
        $stmt->execute([3, 'test3']);
        echo "inserted test3\n";
        
        $pdo->rollback();
        echo "rolled back\n";
        
        // Verify rollback worked
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM async_transaction_test");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "final count: " . $count['cnt'] . "\n";
        
        return "success";
    } catch (Exception $e) {
        echo "error: " . $e->getMessage() . "\n";
        return "failed";
    }
});

$result = await($coroutine);
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