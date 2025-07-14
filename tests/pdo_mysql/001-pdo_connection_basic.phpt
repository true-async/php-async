--TEST--
PDO MySQL: Basic async connection test
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

// Test async PDO connection in coroutine
echo "start\n";

$coroutine = spawn(function() {
    $host = getenv('MYSQL_TEST_HOST');
    $db = getenv('MYSQL_TEST_DB') ?: 'test';
    $user = getenv('MYSQL_TEST_USER') ?: 'root';
    $pass = getenv('MYSQL_TEST_PASSWD') ?: '';
    $dsn = "mysql:host=$host;dbname=$db";
    
    try {
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "connected\n";
        
        // Test simple query
        $stmt = $pdo->query("SELECT 1 as test");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "query result: " . $result['test'] . "\n";
        
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
awaited: success
end