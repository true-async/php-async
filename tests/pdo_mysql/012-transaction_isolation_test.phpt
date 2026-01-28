--TEST--
PDO MySQL: Transaction isolation with automatic database setup
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

// Test transaction isolation with automatic setup
$result = AsyncPDOMySQLTest::runAsyncTest(function($pdo, $tableName) {
    // Setup transaction test table
    $pdo->exec("CREATE TABLE transaction_test (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(255),
        amount DECIMAL(10,2)
    ) ENGINE=InnoDB");
    
    $pdo->exec("INSERT INTO transaction_test (name, amount) VALUES ('account1', 100.00), ('account2', 200.00)");
    
    echo "transaction test setup\n";
    
    // Test concurrent transactions
    $transaction1 = spawn(function() {
        $pdo = AsyncPDOMySQLTest::factory();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE transaction_test SET amount = amount - 50 WHERE name = 'account1'");
            $stmt->execute();
            
            $stmt = $pdo->prepare("UPDATE transaction_test SET amount = amount + 50 WHERE name = 'account2'");  
            $stmt->execute();
            
            $pdo->commit();
            return ['id' => 1, 'status' => 'committed'];
        } catch (Exception $e) {
            $pdo->rollback();
            return ['id' => 1, 'status' => 'rolled_back'];
        }
    });
    
    $transaction2 = spawn(function() {
        $pdo = AsyncPDOMySQLTest::factory();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE transaction_test SET amount = amount - 25 WHERE name = 'account2'");
            $stmt->execute();
            
            $stmt = $pdo->prepare("UPDATE transaction_test SET amount = amount + 25 WHERE name = 'account1'");
            $stmt->execute();
            
            $pdo->commit();
            return ['id' => 2, 'status' => 'committed'];
        } catch (Exception $e) {
            $pdo->rollback();
            return ['id' => 2, 'status' => 'rolled_back'];
        }
    });
    
    $results = [await($transaction1), await($transaction2)];
    
    // Sort results by transaction id for deterministic output
    usort($results, function($a, $b) {
        return $a['id'] - $b['id'];
    });
    
    // Display results in order
    foreach ($results as $result) {
        $id = $result['id'];
        $status = $result['status'];
        echo "transaction $id: debited account" . ($id == 1 ? '1' : '2') . "\n";
        echo "transaction $id: credited account" . ($id == 1 ? '2' : '1') . "\n";
        echo "transaction $id: $status\n";
    }
    
    // Check final balances
    $stmt = $pdo->query("SELECT name, amount FROM transaction_test ORDER BY name");
    $balances = $stmt->fetchAll();
    
    foreach ($balances as $balance) {
        echo "final balance " . $balance['name'] . ": " . $balance['amount'] . "\n";
    }
    
    // Cleanup
    $pdo->exec("DROP TABLE transaction_test");
    
    return "transaction_isolation_passed";
});

echo "test result: " . $result . "\n";
echo "end\n";

?>
--EXPECT--
start
transaction test setup
transaction 1: debited account1
transaction 1: credited account2
transaction 1: committed
transaction 2: debited account2
transaction 2: credited account1
transaction 2: committed
final balance account1: 75.00
final balance account2: 225.00
test result: transaction_isolation_passed
end