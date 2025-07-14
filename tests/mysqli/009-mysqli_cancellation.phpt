--TEST--
MySQLi: Async cancellation test
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
use function Async\timeout;

echo "start\n";

// Test cancellation of long-running query
$long_query_coroutine = spawn(function() {
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
        
        echo "starting long query\n";
        
        // This query should take several seconds
        $result = $mysqli->query("SELECT SLEEP(5), 'long query completed' as message");
        
        if ($result) {
            $row = $result->fetch_assoc();
            echo "query completed: " . $row['message'] . "\n";
            $result->free();
            $mysqli->close();
            return "completed";
        } else {
            echo "query failed: " . $mysqli->error . "\n";
            $mysqli->close();
            return "failed";
        }
    } catch (Exception $e) {
        echo "query cancelled or failed: " . $e->getMessage() . "\n";
        return "cancelled";
    }
});

// Test manual cancellation
$manual_cancel_test = spawn(function() use ($long_query_coroutine) {
    // Wait a bit, then cancel the long query
    usleep(500000); // 0.5 seconds
    
    echo "cancelling long query\n";
    $long_query_coroutine->cancel();
    
    return "cancellation_sent";
});

// Test timeout-based cancellation
$timeout_test = spawn(function() {
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
        
        echo "starting query with timeout\n";
        
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
            echo "timeout query completed: " . $result['message'] . "\n";
            $mysqli->close();
            return "timeout_completed";
        } else {
            echo "timeout query returned null\n";
            $mysqli->close();
            return "timeout_null";
        }
    } catch (Exception $e) {
        echo "timeout query cancelled: timeout exceeded\n";
        return "timeout_cancelled";
    }
});

// Test cancellation of prepared statement
$prepared_cancel_test = spawn(function() {
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
        
        echo "testing prepared statement cancellation\n";
        
        $stmt = $mysqli->prepare("SELECT SLEEP(?), 'prepared completed' as message");
        if ($stmt) {
            $sleep_time = 4;
            $stmt->bind_param("i", $sleep_time);
            
            // This should be cancelled before completion
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result) {
                $row = $result->fetch_assoc();
                echo "prepared statement completed: " . $row['message'] . "\n";
                $result->free();
            }
            
            $stmt->close();
        }
        
        $mysqli->close();
        return "prepared_completed";
    } catch (Exception $e) {
        echo "prepared statement cancelled\n";
        return "prepared_cancelled";
    }
});

// Start cancellation for prepared statement after delay
$prepared_canceller = spawn(function() use ($prepared_cancel_test) {
    usleep(800000); // 0.8 seconds
    echo "cancelling prepared statement\n";
    $prepared_cancel_test->cancel();
    return "prepared_cancellation_sent";
});

// Wait for manual cancellation test
$manual_result = await($manual_cancel_test);
echo "manual cancel result: " . $manual_result . "\n";

// Wait for the long query (should be cancelled)
try {
    $long_result = await($long_query_coroutine);
    echo "long query result: " . $long_result . "\n";
} catch (Exception $e) {
    echo "long query was cancelled\n";
}

// Wait for timeout test
$timeout_result = await($timeout_test);
echo "timeout test result: " . $timeout_result . "\n";

// Wait for prepared statement tests
$prepared_canceller_result = await($prepared_canceller);
echo "prepared canceller result: " . $prepared_canceller_result . "\n";

try {
    $prepared_result = await($prepared_cancel_test);
    echo "prepared test result: " . $prepared_result . "\n";
} catch (Exception $e) {
    echo "prepared statement was cancelled\n";
}

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