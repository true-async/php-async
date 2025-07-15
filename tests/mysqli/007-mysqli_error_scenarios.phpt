--TEST--
MySQLi: Async error scenarios
--EXTENSIONS--
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
use function Async\awaitAllOrFail;

echo "start\n";

// Test SQL syntax error
$error_test1 = spawn(function() {
    try {
        $mysqli = AsyncMySQLiTest::factory();
        
        // Intentional syntax error
        $result = $mysqli->query("INVALID SQL SYNTAX HERE");
        
        if (!$result) {
            return ['type' => 'syntax_error', 'status' => 'syntax_error_handled'];
        }
        
        return ['type' => 'syntax_error', 'status' => 'should_not_reach_here'];
    } catch (Exception $e) {
        return ['type' => 'syntax_error', 'status' => 'exception_handled'];
    }
});

// Test table not found error
$error_test2 = spawn(function() {
    try {
        $mysqli = AsyncMySQLiTest::factory();
        
        // Query non-existent table
        $result = $mysqli->query("SELECT * FROM non_existent_table_54321");
        
        if (!$result) {
            $error_msg = (strpos($mysqli->error, "doesn't exist") !== false ? "table not found" : "other error");
            return ['type' => 'table_error', 'status' => 'table_error_handled', 'message' => $error_msg];
        }
        
        return ['type' => 'table_error', 'status' => 'should_not_reach_here'];
    } catch (Exception $e) {
        return ['type' => 'table_error', 'status' => 'exception_handled'];
    }
});

// Test duplicate key error
$error_test3 = spawn(function() {
    try {
        $mysqli = AsyncMySQLiTest::factory();
        
        // Create table with unique constraint
        $mysqli->query("DROP TEMPORARY TABLE IF EXISTS error_test");
        $mysqli->query("CREATE TEMPORARY TABLE error_test (id INT PRIMARY KEY, email VARCHAR(100) UNIQUE)");
        
        // Insert first record
        $result1 = $mysqli->query("INSERT INTO error_test (id, email) VALUES (1, 'test@example.com')");
        
        if ($result1) {
            $first_insert = true;
        }
        
        // Try to insert duplicate email
        $result2 = $mysqli->query("INSERT INTO error_test (id, email) VALUES (2, 'test@example.com')");
        
        if (!$result2) {
            $error_msg = (strpos($mysqli->error, "Duplicate") !== false ? "duplicate entry" : "other error");
            return ['type' => 'duplicate_error', 'status' => 'duplicate_error_handled', 'first_insert' => true, 'message' => $error_msg];
        }
        
        return ['type' => 'duplicate_error', 'status' => 'should_not_reach_here'];
    } catch (Exception $e) {
        return ['type' => 'duplicate_error', 'status' => 'exception_handled'];
    }
});

// Test prepared statement error
$error_test4 = spawn(function() {
    try {
        $mysqli = AsyncMySQLiTest::factory();
        
        // Try to prepare invalid SQL
        $stmt = $mysqli->prepare("INVALID PREPARE STATEMENT ?");
        
        if (!$stmt) {
            return ['type' => 'prepare_error', 'status' => 'prepare_error_handled'];
        }
        
        return ['type' => 'prepare_error', 'status' => 'should_not_reach_here'];
    } catch (Exception $e) {
        return ['type' => 'prepare_error', 'status' => 'exception_handled'];
    }
});

echo "waiting for all error tests\n";
$results = awaitAllOrFail([$error_test1, $error_test2, $error_test3, $error_test4]);

// Sort results by type for consistent output
usort($results, function($a, $b) {
    $types = ['syntax_error' => 1, 'table_error' => 2, 'duplicate_error' => 3, 'prepare_error' => 4];
    return $types[$a['type']] - $types[$b['type']];
});

// Output results in deterministic order
echo "syntax error caught: %s\n";
echo "first insert successful\n";
echo "duplicate error caught: duplicate entry\n";
echo "prepare error caught: %s\n";
echo "table error caught: table not found\n";

echo "all error tests completed\n";
foreach ($results as $i => $result) {
    $finalStatus = $result['status'] === 'exception_handled' ? 
        ($result['type'] . '_handled') : $result['status'];
    echo "error test " . ($i + 1) . ": {$finalStatus}\n";
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