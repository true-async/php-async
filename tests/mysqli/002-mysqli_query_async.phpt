--TEST--
MySQLi: Async query execution
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
            echo "connection failed: " . $mysqli->connect_error . "\n";
            return "failed";
        }
        
        // Create temporary table
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