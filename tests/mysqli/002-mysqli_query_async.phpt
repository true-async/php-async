--TEST--
MySQLi: Async query execution
--EXTENSIONS--
async
mysqli
--SKIPIF--
<?php
require_once __DIR__ . '/inc/async_mysqli_test.inc';
AsyncMySQLiTest::skipIfNoAsync();
AsyncMySQLiTest::skipIfNoMySQLi();
AsyncMySQLiTest::skip();
?>
--FILE--
<?php
require_once __DIR__ . '/inc/async_mysqli_test.inc';

use function Async\spawn;
use function Async\await;

echo "start\n";

$coroutine = spawn(function() {
    try {
        $mysqli = AsyncMySQLiTest::initDatabase();
        
        // Create and populate test table
        $mysqli->query("DROP TEMPORARY TABLE IF EXISTS async_test");
        $result = $mysqli->query("CREATE TEMPORARY TABLE async_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), value INT)");
        
        if (!$result) {
            echo "create table failed: " . $mysqli->error . "\n";
            return "failed";
        }
        echo "table created\n";
        
        // Insert test data
        $mysqli->query("INSERT INTO async_test (name, value) VALUES ('test1', 10)");
        $mysqli->query("INSERT INTO async_test (name, value) VALUES ('test2', 20)");
        $mysqli->query("INSERT INTO async_test (name, value) VALUES ('test3', 30)");
        echo "data inserted\n";
        
        // Test SELECT query
        $result = $mysqli->query("SELECT * FROM async_test ORDER BY id");
        if ($result) {
            $count = 0;
            while ($row = $result->fetch_assoc()) {
                $count++;
                echo "row $count: {$row['name']} = {$row['value']}\n";
            }
            $result->free();
        }
        
        // Test aggregate query
        $result = $mysqli->query("SELECT COUNT(*) as total, SUM(value) as sum_value FROM async_test");
        if ($result) {
            $row = $result->fetch_assoc();
            echo "total rows: {$row['total']}, sum: {$row['sum_value']}\n";
            $result->free();
        }
        
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
data inserted
row 1: test1 = 10
row 2: test2 = 20
row 3: test3 = 30
total rows: 3, sum: 60
result: completed
end