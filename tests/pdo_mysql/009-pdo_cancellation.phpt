--TEST--
PDO MySQL: Async cancellation test
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
use function Async\timeout;

echo "start\n";

// Test cancellation of a long-running query
$coroutine = spawn(function() {
    $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
    $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
    $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
    
    try {
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "starting long query\n";
        
        // This query should take several seconds
        $stmt = $pdo->query("SELECT SLEEP(5), 'long query completed' as message");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "query completed: " . $result['message'] . "\n";
        return "completed";
    } catch (Exception $e) {
        echo "query cancelled or failed: " . $e->getMessage() . "\n";
        return "cancelled";
    }
});

// Test manual cancellation
$manual_cancel_test = spawn(function() use ($coroutine) {
    // Wait a bit, then cancel the coroutine
    $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
    $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
    $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
    
    $pdo = new PDO($dsn, $user, $pass);
    
    // Simulate some work before cancelling
    usleep(500000); // 0.5 seconds
    
    echo "cancelling long query\n";
    $coroutine->cancel();
    
    return "cancellation_sent";
});

// Test timeout-based cancellation
$timeout_test = spawn(function() {
    $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
    $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
    $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
    
    try {
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "starting query with timeout\n";
        
        // Use timeout to cancel after 1 second
        $result = await(spawn(function() use ($pdo) {
            $stmt = $pdo->query("SELECT SLEEP(3), 'timeout query completed' as message");
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }), timeout(1000)); // 1 second timeout
        
        echo "timeout query completed: " . $result['message'] . "\n";
        return "timeout_completed";
    } catch (Exception $e) {
        echo "timeout query cancelled: timeout exceeded\n";
        return "timeout_cancelled";
    }
});

// Wait for manual cancellation test
$manual_result = await($manual_cancel_test);
echo "manual cancel result: " . $manual_result . "\n";

// Wait for the original coroutine (should be cancelled)
try {
    $result = await($coroutine);
    echo "original query result: " . $result . "\n";
} catch (Exception $e) {
    echo "original query was cancelled\n";
}

// Wait for timeout test
$timeout_result = await($timeout_test);
echo "timeout test result: " . $timeout_result . "\n";

echo "end\n";

?>
--EXPECTF--
start
starting long query
cancelling long query
manual cancel result: cancellation_sent
original query was cancelled
starting query with timeout
timeout query cancelled: timeout exceeded
timeout test result: timeout_cancelled
end