--TEST--
PDO MySQL: Async error handling
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

// Test SQL syntax error in coroutine
$coroutine1 = spawn(function() {
    try {
        $pdo = AsyncPDOMySQLTest::factory();
        
        // Intentional SQL syntax error
        $stmt = $pdo->query("INVALID SQL SYNTAX HERE");
        return ["type" => "sql_error", "status" => "should_not_reach", "error" => "none"];
    } catch (PDOException $e) {
        $error_type = (strpos($e->getMessage(), 'syntax') !== false ? 'syntax error' : 'other error');
        return ["type" => "sql_error", "status" => "handled", "error" => $error_type];
    } catch (Exception $e) {
        return ["type" => "sql_error", "status" => "general_error", "error" => $e->getMessage()];
    }
});

// Test invalid table error in coroutine
$coroutine2 = spawn(function() {
    try {
        $pdo = AsyncPDOMySQLTest::factory();
        
        // Query non-existent table
        $stmt = $pdo->query("SELECT * FROM non_existent_table_12345");
        return ["type" => "table_error", "status" => "should_not_reach", "error" => "none"];
    } catch (PDOException $e) {
        $error_type = (strpos($e->getMessage(), "doesn't exist") !== false ? 'table not found' : 'other error');
        return ["type" => "table_error", "status" => "handled", "error" => $error_type];
    } catch (Exception $e) {
        return ["type" => "table_error", "status" => "general_error", "error" => $e->getMessage()];
    }
});

// Test constraint violation error
$coroutine3 = spawn(function() {
    try {
        $pdo = AsyncPDOMySQLTest::factory();
        
        // Create table with unique constraint
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS error_test");
        $pdo->exec("CREATE TEMPORARY TABLE error_test (id INT PRIMARY KEY, email VARCHAR(100) UNIQUE)");
        
        // Insert first record
        $stmt = $pdo->prepare("INSERT INTO error_test (id, email) VALUES (?, ?)");
        $stmt->execute([1, 'test@example.com']);
        
        // Try to insert duplicate email (should fail)
        $stmt->execute([2, 'test@example.com']);
        
        return ["type" => "constraint_error", "status" => "should_not_reach", "error" => "none"];
    } catch (PDOException $e) {
        $error_type = (strpos($e->getMessage(), 'Duplicate') !== false ? 'duplicate entry' : 'other error');
        return ["type" => "constraint_error", "status" => "handled", "error" => $error_type];
    } catch (Exception $e) {
        return ["type" => "constraint_error", "status" => "general_error", "error" => $e->getMessage()];
    }
});

// Test connection timeout/failure (if possible)
$coroutine4 = spawn(function() {
    try {
        // Try to connect to invalid host
        $pdo = new PDO('mysql:host=invalid_host_12345;dbname=test', 'user', 'pass');
        return ["type" => "connection_error", "status" => "should_not_reach", "error" => "none"];
    } catch (PDOException $e) {
        return ["type" => "connection_error", "status" => "handled", "error" => "connection failed"];
    } catch (Exception $e) {
        return ["type" => "connection_error", "status" => "general_error", "error" => "connection failed"];
    }
});

echo "waiting for all error handling tests\n";
$results = await_all_or_fail([$coroutine1, $coroutine2, $coroutine3, $coroutine4]);

// Sort results by type for deterministic output
usort($results, function($a, $b) {
    return strcmp($a['type'], $b['type']);
});

echo "all error tests completed\n";

// Display results in sorted order
foreach ($results as $result) {
    $type = $result['type'];
    $status = $result['status'];
    $error = $result['error'];
    
    if ($type === 'connection_error') {
        echo "caught connection error: $error\n";
    } elseif ($type === 'constraint_error') {
        echo "caught constraint error: $error\n";
    } elseif ($type === 'sql_error') {
        echo "caught SQL error: $error\n";
    } elseif ($type === 'table_error') {
        echo "caught table error: $error\n";
    }
}

// Display test results
$result_mapping = [
    'connection_error' => 'connection_error_handled',
    'constraint_error' => 'constraint_error_handled', 
    'sql_error' => 'sql_error_handled',
    'table_error' => 'table_error_handled'
];

foreach ($results as $i => $result) {
    $result_str = $result_mapping[$result['type']];
    echo "test " . ($i + 1) . ": $result_str\n";
}

echo "end\n";

?>
--EXPECT--
start
waiting for all error handling tests
all error tests completed
caught connection error: connection failed
caught constraint error: duplicate entry
caught SQL error: syntax error
caught table error: table not found
test 1: connection_error_handled
test 2: constraint_error_handled
test 3: sql_error_handled
test 4: table_error_handled
end