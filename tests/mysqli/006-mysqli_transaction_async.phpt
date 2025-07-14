--TEST--
MySQLi: Async transaction handling
--EXTENSIONS--
async
mysqli
--SKIPIF--
<?php
if (!extension_loaded('mysqli')) die('skip mysqli not available');
if (!getenv('MYSQL_TEST_HOST')) die('skip MYSQL_TEST_HOST not set');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "start\n";

$coroutine = spawn(function() {
    $host = getenv("MYSQL_TEST_HOST") ?: "127.0.0.1";
    $port = getenv("MYSQL_TEST_PORT") ?: 3306;
    $user = getenv("MYSQL_TEST_USER") ?: "root";
    $passwd = getenv("MYSQL_TEST_PASSWD") ?: "";
    $db = getenv("MYSQL_TEST_DB") ?: "test";
    
    try {
        $mysqli = new mysqli($host, $user, $passwd, $db, $port);
        
        if ($mysqli->connect_error) {
            throw new Exception("Connection failed: " . $mysqli->connect_error);
        }
        
        // Create test table
        $mysqli->query("DROP TEMPORARY TABLE IF EXISTS async_transaction_test");
        $mysqli->query("CREATE TEMPORARY TABLE async_transaction_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), amount DECIMAL(10,2)) ENGINE=InnoDB");
        echo "table created\n";
        
        // Test successful transaction
        echo "starting transaction\n";
        $mysqli->autocommit(false);
        $mysqli->begin_transaction();
        
        $mysqli->query("INSERT INTO async_transaction_test (name, amount) VALUES ('account1', 1000.00)");
        $mysqli->query("INSERT INTO async_transaction_test (name, amount) VALUES ('account2', 500.00)");
        echo "inserted initial data\n";
        
        // Transfer money between accounts
        $mysqli->query("UPDATE async_transaction_test SET amount = amount - 200.00 WHERE name = 'account1'");
        $mysqli->query("UPDATE async_transaction_test SET amount = amount + 200.00 WHERE name = 'account2'");
        echo "performed transfer\n";
        
        // Check balances in transaction
        $result = $mysqli->query("SELECT name, amount FROM async_transaction_test ORDER BY name");
        echo "balances in transaction:\n";
        while ($row = $result->fetch_assoc()) {
            echo "  {$row['name']}: {$row['amount']}\n";
        }
        $result->free();
        
        // Commit transaction
        $mysqli->commit();
        echo "transaction committed\n";
        
        // Verify data persists after commit
        $result = $mysqli->query("SELECT name, amount FROM async_transaction_test ORDER BY name");
        echo "balances after commit:\n";
        while ($row = $result->fetch_assoc()) {
            echo "  {$row['name']}: {$row['amount']}\n";
        }
        $result->free();
        
        // Test rollback transaction
        echo "testing rollback\n";
        $mysqli->begin_transaction();
        
        $mysqli->query("UPDATE async_transaction_test SET amount = 0 WHERE name = 'account1'");
        $mysqli->query("UPDATE async_transaction_test SET amount = 0 WHERE name = 'account2'");
        echo "zeroed all amounts\n";
        
        // Check balances before rollback
        $result = $mysqli->query("SELECT SUM(amount) as total FROM async_transaction_test");
        $row = $result->fetch_assoc();
        echo "total before rollback: {$row['total']}\n";
        $result->free();
        
        // Rollback
        $mysqli->rollback();
        echo "rolled back\n";
        
        // Verify rollback worked
        $result = $mysqli->query("SELECT name, amount FROM async_transaction_test ORDER BY name");
        echo "balances after rollback:\n";
        while ($row = $result->fetch_assoc()) {
            echo "  {$row['name']}: {$row['amount']}\n";
        }
        $result->free();
        
        $mysqli->autocommit(true);
        $mysqli->close();
        return "completed";
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
table created
starting transaction
inserted initial data
performed transfer
balances in transaction:
  account1: 800.00
  account2: 700.00
transaction committed
balances after commit:
  account1: 800.00
  account2: 700.00
testing rollback
zeroed all amounts
total before rollback: 0.00
rolled back
balances after rollback:
  account1: 800.00
  account2: 700.00
result: completed
end