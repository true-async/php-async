--TEST--
MySQLi: Async error scenarios
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
use function Async\awaitAllOrFail;

echo "start\n";

// Test SQL syntax error
$error_test1 = spawn(function() {
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
        
        // Intentional syntax error
        $result = $mysqli->query("INVALID SQL SYNTAX HERE");
        
        if (!$result) {
            echo "syntax error caught: " . $mysqli->error . "\n";
            return "syntax_error_handled";
        }
        
        return "should_not_reach_here";
    } catch (Exception $e) {
        echo "exception in syntax test: " . $e->getMessage() . "\n";
        return "exception_handled";
    }
});

// Test table not found error
$error_test2 = spawn(function() {
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
        
        // Query non-existent table
        $result = $mysqli->query("SELECT * FROM non_existent_table_54321");
        
        if (!$result) {
            echo "table error caught: " . (strpos($mysqli->error, "doesn't exist") !== false ? "table not found" : "other error") . "\n";
            return "table_error_handled";
        }
        
        return "should_not_reach_here";
    } catch (Exception $e) {
        echo "exception in table test: " . $e->getMessage() . "\n";
        return "exception_handled";
    }
});

// Test duplicate key error
$error_test3 = spawn(function() {
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
        
        // Create table with unique constraint
        $mysqli->query("DROP TEMPORARY TABLE IF EXISTS error_test");
        $mysqli->query("CREATE TEMPORARY TABLE error_test (id INT PRIMARY KEY, email VARCHAR(100) UNIQUE)");
        
        // Insert first record
        $result1 = $mysqli->query("INSERT INTO error_test (id, email) VALUES (1, 'test@example.com')");
        
        if ($result1) {
            echo "first insert successful\n";
        }
        
        // Try to insert duplicate email
        $result2 = $mysqli->query("INSERT INTO error_test (id, email) VALUES (2, 'test@example.com')");
        
        if (!$result2) {
            echo "duplicate error caught: " . (strpos($mysqli->error, "Duplicate") !== false ? "duplicate entry" : "other error") . "\n";
            return "duplicate_error_handled";
        }
        
        return "should_not_reach_here";
    } catch (Exception $e) {
        echo "exception in duplicate test: " . $e->getMessage() . "\n";
        return "exception_handled";
    }
});

// Test prepared statement error
$error_test4 = spawn(function() {
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
        
        // Try to prepare invalid SQL
        $stmt = $mysqli->prepare("INVALID PREPARE STATEMENT ?");
        
        if (!$stmt) {
            echo "prepare error caught: " . $mysqli->error . "\n";
            return "prepare_error_handled";
        }
        
        return "should_not_reach_here";
    } catch (Exception $e) {
        echo "exception in prepare test: " . $e->getMessage() . "\n";
        return "exception_handled";
    }
});

echo "waiting for all error tests\n";
$results = awaitAllOrFail([$error_test1, $error_test2, $error_test3, $error_test4]);

echo "all error tests completed\n";
foreach ($results as $i => $result) {
    echo "error test " . ($i + 1) . ": $result\n";
}

echo "end\n";

?>
--EXPECTF--
start
waiting for all error tests
syntax error caught: %s
first insert successful
duplicate error caught: duplicate entry
prepare error caught: %s
table error caught: table not found
all error tests completed
error test 1: syntax_error_handled
error test 2: table_error_handled
error test 3: duplicate_error_handled
error test 4: prepare_error_handled
end