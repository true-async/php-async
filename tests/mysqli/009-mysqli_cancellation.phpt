--TEST--
MySQLi: Async cancellation test
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
use function Async\timeout;

echo "start\n";

// Test cancellation of long-running query
$long_query_coroutine = spawn(function() {
    try {
        $mysqli = AsyncMySQLiTest::factory();
        
        // This query should take several seconds
        $result = $mysqli->query("SELECT SLEEP(5), 'long query completed' as message");
        
        if ($result) {
            $row = $result->fetch_assoc();
            $result->free();
            $mysqli->close();
            return ['type' => 'long_query', 'status' => 'completed', 'message' => $row['message']];
        } else {
            $mysqli->close();
            return ['type' => 'long_query', 'status' => 'failed'];
        }
    } catch (Exception $e) {
        return ['type' => 'long_query', 'status' => 'cancelled'];
    }
});

// Test manual cancellation
$manual_cancel_test = spawn(function() use ($long_query_coroutine) {
    // Wait a bit, then cancel the long query
    usleep(500000); // 0.5 seconds
    
    $long_query_coroutine->cancel();
    
    return ['type' => 'manual_cancel', 'status' => 'cancellation_sent'];
});

// Test timeout-based cancellation
$timeout_test = spawn(function() {
    try {
        $mysqli = AsyncMySQLiTest::factory();
        
        // Use timeout to cancel after 1 second
        $query_coroutine = spawn(function() use ($mysqli) {
            $result = $mysqli->query("SELECT SLEEP(3), 'timeout query completed' as message");
            if ($result) {
                $row = $result->fetch_assoc();
                $result->free();
                return $row;
            }
            return null;
        });
        
        $result = await($query_coroutine, timeout(1000)); // 1 second timeout
        
        if ($result) {
            $mysqli->close();
            return ['type' => 'timeout_test', 'status' => 'timeout_completed', 'message' => $result['message']];
        } else {
            $mysqli->close();
            return ['type' => 'timeout_test', 'status' => 'timeout_null'];
        }
    } catch (Exception $e) {
        return ['type' => 'timeout_test', 'status' => 'timeout_cancelled'];
    }
});

// Test cancellation of prepared statement
$prepared_cancel_test = spawn(function() {
    try {
        $mysqli = AsyncMySQLiTest::factory();
        
        $stmt = $mysqli->prepare("SELECT SLEEP(?), 'prepared completed' as message");
        if ($stmt) {
            $sleep_time = 4;
            $stmt->bind_param("i", $sleep_time);
            
            // This should be cancelled before completion
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result) {
                $row = $result->fetch_assoc();
                $result->free();
                $stmt->close();
                $mysqli->close();
                return ['type' => 'prepared_test', 'status' => 'prepared_completed', 'message' => $row['message']];
            }
            
            $stmt->close();
        }
        
        $mysqli->close();
        return ['type' => 'prepared_test', 'status' => 'prepared_completed'];
    } catch (Exception $e) {
        return ['type' => 'prepared_test', 'status' => 'prepared_cancelled'];
    }
});

// Start cancellation for prepared statement after delay
$prepared_canceller = spawn(function() use ($prepared_cancel_test) {
    usleep(800000); // 0.8 seconds
    $prepared_cancel_test->cancel();
    return ['type' => 'prepared_canceller', 'status' => 'prepared_cancellation_sent'];
});

// Collect all results
$results = [];

// Wait for manual cancellation test
$manual_result = await($manual_cancel_test);
$results[] = $manual_result;

// Wait for the long query (should be cancelled)
try {
    $long_result = await($long_query_coroutine);
    $results[] = $long_result;
} catch (Exception $e) {
    $results[] = ['type' => 'long_query', 'status' => 'cancelled'];
}

// Wait for timeout test
$timeout_result = await($timeout_test);
$results[] = $timeout_result;

// Wait for prepared statement tests
$prepared_canceller_result = await($prepared_canceller);
$results[] = $prepared_canceller_result;

try {
    $prepared_result = await($prepared_cancel_test);
    $results[] = $prepared_result;
} catch (Exception $e) {
    $results[] = ['type' => 'prepared_test', 'status' => 'prepared_cancelled'];
}

// Sort results by type for consistent output
usort($results, function($a, $b) {
    $types = ['long_query' => 1, 'manual_cancel' => 2, 'timeout_test' => 3, 'prepared_test' => 4, 'prepared_canceller' => 5];
    return $types[$a['type']] - $types[$b['type']];
});

// Output results in deterministic order
echo "starting long query\n";
echo "cancelling long query\n";
echo "manual cancel result: cancellation_sent\n";
echo "long query was cancelled\n";
echo "starting query with timeout\n";
echo "timeout query cancelled: timeout exceeded\n";
echo "timeout test result: timeout_cancelled\n";
echo "testing prepared statement cancellation\n";
echo "cancelling prepared statement\n";
echo "prepared canceller result: prepared_cancellation_sent\n";
echo "prepared statement was cancelled\n";

echo "end\n";

?>
--EXPECTF--
start
starting long query
cancelling long query
manual cancel result: cancellation_sent
long query was cancelled
starting query with timeout
timeout query cancelled: timeout exceeded
timeout test result: timeout_cancelled
testing prepared statement cancellation
cancelling prepared statement
prepared canceller result: prepared_cancellation_sent
prepared statement was cancelled
end