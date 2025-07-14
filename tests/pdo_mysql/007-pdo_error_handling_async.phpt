--TEST--
PDO MySQL: Async error handling
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
use function Async\awaitAllOrFail;

echo "start\n";

// Test SQL syntax error in coroutine
$coroutine1 = spawn(function() {
    $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
    $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
    $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
    
    try {
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Intentional SQL syntax error
        $stmt = $pdo->query("INVALID SQL SYNTAX HERE");
        return "should not reach here";
    } catch (PDOException $e) {
        echo "caught SQL error: " . (strpos($e->getMessage(), 'syntax') !== false ? 'syntax error' : 'other error') . "\n";
        return "sql_error_handled";
    } catch (Exception $e) {
        echo "caught general error: " . $e->getMessage() . "\n";
        return "general_error_handled";
    }
});

// Test invalid table error in coroutine
$coroutine2 = spawn(function() {
    $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
    $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
    $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
    
    try {
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Query non-existent table
        $stmt = $pdo->query("SELECT * FROM non_existent_table_12345");
        return "should not reach here";
    } catch (PDOException $e) {
        echo "caught table error: " . (strpos($e->getMessage(), "doesn't exist") !== false ? 'table not found' : 'other error') . "\n";
        return "table_error_handled";
    } catch (Exception $e) {
        echo "caught general error: " . $e->getMessage() . "\n";
        return "general_error_handled";
    }
});

// Test constraint violation error
$coroutine3 = spawn(function() {
    $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
    $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
    $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
    
    try {
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create table with unique constraint
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS error_test");
        $pdo->exec("CREATE TEMPORARY TABLE error_test (id INT PRIMARY KEY, email VARCHAR(100) UNIQUE)");
        
        // Insert first record
        $stmt = $pdo->prepare("INSERT INTO error_test (id, email) VALUES (?, ?)");
        $stmt->execute([1, 'test@example.com']);
        
        // Try to insert duplicate email (should fail)
        $stmt->execute([2, 'test@example.com']);
        
        return "should not reach here";
    } catch (PDOException $e) {
        echo "caught constraint error: " . (strpos($e->getMessage(), 'Duplicate') !== false ? 'duplicate entry' : 'other error') . "\n";
        return "constraint_error_handled";
    } catch (Exception $e) {
        echo "caught general error: " . $e->getMessage() . "\n";
        return "general_error_handled";
    }
});

// Test connection timeout/failure (if possible)
$coroutine4 = spawn(function() {
    try {
        // Try to connect to invalid host
        $pdo = new PDO('mysql:host=invalid_host_12345;dbname=test', 'user', 'pass');
        return "should not reach here";
    } catch (PDOException $e) {
        echo "caught connection error: connection failed\n";
        return "connection_error_handled";
    } catch (Exception $e) {
        echo "caught general connection error\n";
        return "general_connection_error";
    }
});

echo "waiting for all error handling tests\n";
$results = awaitAllOrFail([$coroutine1, $coroutine2, $coroutine3, $coroutine4]);

echo "all error tests completed\n";
foreach ($results as $i => $result) {
    echo "test " . ($i + 1) . ": $result\n";
}

echo "end\n";

?>
--EXPECTF--
start
waiting for all error handling tests
caught SQL error: syntax error
caught table error: table not found
caught constraint error: duplicate entry
caught connection error: connection failed
all error tests completed
test 1: sql_error_handled
test 2: table_error_handled
test 3: constraint_error_handled
test 4: connection_error_handled
end