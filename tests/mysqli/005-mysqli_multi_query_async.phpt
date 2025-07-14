--TEST--
MySQLi: Async multi-query execution
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
        
        // Prepare multi-query
        $multi_query = "
            DROP TEMPORARY TABLE IF EXISTS async_multi_test;
            CREATE TEMPORARY TABLE async_multi_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), value INT);
            INSERT INTO async_multi_test (name, value) VALUES ('item1', 100);
            INSERT INTO async_multi_test (name, value) VALUES ('item2', 200);
            INSERT INTO async_multi_test (name, value) VALUES ('item3', 300);
            SELECT COUNT(*) as total_count FROM async_multi_test;
            SELECT SUM(value) as total_value FROM async_multi_test;
            SELECT * FROM async_multi_test ORDER BY id;
        ";
        
        echo "executing multi-query\n";
        
        if (!$mysqli->multi_query($multi_query)) {
            throw new Exception("Multi-query failed: " . $mysqli->error);
        }
        
        $query_count = 0;
        do {
            $query_count++;
            
            if ($result = $mysqli->store_result()) {
                echo "query $query_count results:\n";
                
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $values = [];
                        foreach ($row as $key => $value) {
                            $values[] = "$key: $value";
                        }
                        echo "  " . implode(", ", $values) . "\n";
                    }
                } else {
                    echo "  no result rows\n";
                }
                
                $result->free();
            } else {
                if ($mysqli->errno) {
                    echo "query $query_count error: " . $mysqli->error . "\n";
                } else {
                    echo "query $query_count: executed (no result set)\n";
                }
            }
            
            // Check if there are more results
            if (!$mysqli->more_results()) {
                break;
            }
            
        } while ($mysqli->next_result());
        
        echo "total queries executed: $query_count\n";
        
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
--EXPECTF--
start
executing multi-query
query 1: executed (no result set)
query 2: executed (no result set)
query 3: executed (no result set)
query 4: executed (no result set)
query 5: executed (no result set)
query 6 results:
  total_count: 3
query 7 results:
  total_value: 600
query 8 results:
  id: 1, name: item1, value: 100
  id: 2, name: item2, value: 200
  id: 3, name: item3, value: 300
total queries executed: 8
result: completed
end