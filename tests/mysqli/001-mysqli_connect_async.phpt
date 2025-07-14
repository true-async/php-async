--TEST--
MySQLi: Basic async connection test
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
    $host = getenv("MYSQL_TEST_HOST");
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
        
        echo "connected\n";
        
        // Test simple query
        $result = $mysqli->query("SELECT 1 as test");
        if ($result) {
            $row = $result->fetch_assoc();
            echo "query result: " . $row['test'] . "\n";
            $result->free();
        }
        
        $mysqli->close();
        echo "closed\n";
        
        return "success";
    } catch (Exception $e) {
        echo "error: " . $e->getMessage() . "\n";
        return "failed";
    }
});

$result = await($coroutine);
echo "awaited: " . $result . "\n";
echo "end\n";

?>
--EXPECT--
start
connected
query result: 1
closed
awaited: success
end