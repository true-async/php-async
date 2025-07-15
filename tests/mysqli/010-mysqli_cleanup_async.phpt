--TEST--
MySQLi: Async resource cleanup and connection management
--EXTENSIONS--
async
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

function getConnectionCount() {
    try {
        $mysqli = AsyncMySQLiTest::factory();
        $result = $mysqli->query("SHOW STATUS LIKE 'Threads_connected'");
        if ($result) {
            $row = $result->fetch_assoc();
            $result->free();
            $mysqli->close();
            return (int) $row['Value'];
        }
        $mysqli->close();
        return -1;
    } catch (Exception $e) {
        return -1;
    }
}

$initial_connections = getConnectionCount();
echo "initial connections: $initial_connections\n";

// Test proper cleanup in multiple coroutines
$cleanup_coroutines = [];

for ($i = 1; $i <= 4; $i++) {
    $cleanup_coroutines[] = spawn(function() use ($i) {
        try {
            // Create connection
            $mysqli = AsyncMySQLiTest::factory();
            
            // Get connection ID
            $result = $mysqli->query("SELECT CONNECTION_ID() as conn_id");
            $conn_info = $result->fetch_assoc();
            $conn_id = $conn_info['conn_id'];
            $result->free();
            
            echo "coroutine $i: connection $conn_id created\n";
            
            // Create and use temporary table
            $mysqli->query("DROP TEMPORARY TABLE IF EXISTS cleanup_test_$i");
            $mysqli->query("CREATE TEMPORARY TABLE cleanup_test_$i (id INT AUTO_INCREMENT PRIMARY KEY, data VARCHAR(100))");
            
            // Insert some data
            $stmt = $mysqli->prepare("INSERT INTO cleanup_test_$i (data) VALUES (?)");
            if ($stmt) {
                for ($j = 1; $j <= 5; $j++) {
                    $data = "test_data_$j";
                    $stmt->bind_param("s", $data);
                    $stmt->execute();
                }
                $stmt->close();
                echo "coroutine $i: inserted data\n";
            }
            
            // Query the data
            $result = $mysqli->query("SELECT COUNT(*) as count FROM cleanup_test_$i");
            if ($result) {
                $count_row = $result->fetch_assoc();
                echo "coroutine $i: found {$count_row['count']} records\n";
                $result->free();
            }
            
            // Test prepared statement cleanup
            $stmt = $mysqli->prepare("SELECT data FROM cleanup_test_$i WHERE id = ?");
            if ($stmt) {
                $id = 3;
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    $row = $result->fetch_assoc();
                    echo "coroutine $i: retrieved: {$row['data']}\n";
                    $result->free();
                }
                $stmt->close();
            }
            
            // Explicitly close connection
            $mysqli->close();
            echo "coroutine $i: connection $conn_id closed\n";
            
            return "coroutine_$i" . "_completed";
        } catch (Exception $e) {
            echo "coroutine $i error: " . $e->getMessage() . "\n";
            return "coroutine_$i" . "_failed";
        }
    });
}

// Test coroutine that doesn't explicitly close (tests automatic cleanup)
$cleanup_coroutines[] = spawn(function() {
    try {
        $mysqli = AsyncMySQLiTest::factory();
    } catch (Exception $e) {
        return "connection_failed";
    }
    
    $result = $mysqli->query("SELECT CONNECTION_ID() as conn_id");
    $conn_info = $result->fetch_assoc();
    $result->free();
    
    echo "coroutine 5: connection {$conn_info['conn_id']} created (auto cleanup test)\n";
    
    // Do some work but don't call close() - test automatic cleanup
    $mysqli->query("SELECT 1");
    
    return "coroutine_5_completed";
});

// Test coroutine with statement that's not explicitly closed
$cleanup_coroutines[] = spawn(function() {
    try {
        $mysqli = AsyncMySQLiTest::factory();
    } catch (Exception $e) {
        return "connection_failed";
    }
    
    $result = $mysqli->query("SELECT CONNECTION_ID() as conn_id");
    $conn_info = $result->fetch_assoc();
    $result->free();
    
    echo "coroutine 6: connection {$conn_info['conn_id']} created (statement cleanup test)\n";
    
    // Create statement but don't close it
    $stmt = $mysqli->prepare("SELECT 1 as test");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            $result->fetch_assoc();
            $result->free();
        }
        // Don't call $stmt->close() - test automatic cleanup
    }
    
    $mysqli->close();
    return "coroutine_6_completed";
});

echo "waiting for all cleanup tests\n";
$results = awaitAllOrFail($cleanup_coroutines);

echo "all cleanup tests completed\n";
foreach ($results as $i => $result) {
    echo "cleanup test " . ($i + 1) . ": $result\n";
}

// Force garbage collection
gc_collect_cycles();
echo "garbage collection forced\n";

// Small delay to allow MySQL to process connection closures
usleep(200000); // 0.2 seconds

$final_connections = getConnectionCount();
echo "final connections: $final_connections\n";

$connection_diff = $final_connections - $initial_connections;
echo "connection difference: $connection_diff\n";

if ($connection_diff <= 1) { // Allow for our own monitoring connection
    echo "cleanup: passed\n";
} else {
    echo "cleanup: potential leak ($connection_diff extra connections)\n";
}

echo "end\n";

?>
--EXPECTF--
start
initial connections: %d
waiting for all cleanup tests
coroutine 1: connection %d created
coroutine 1: inserted data
coroutine 1: found 5 records
coroutine 1: retrieved: test_data_3
coroutine 1: connection %d closed
coroutine 2: connection %d created
coroutine 2: inserted data
coroutine 2: found 5 records
coroutine 2: retrieved: test_data_3
coroutine 2: connection %d closed
coroutine 3: connection %d created
coroutine 3: inserted data
coroutine 3: found 5 records
coroutine 3: retrieved: test_data_3
coroutine 3: connection %d closed
coroutine 4: connection %d created
coroutine 4: inserted data
coroutine 4: found 5 records
coroutine 4: retrieved: test_data_3
coroutine 4: connection %d closed
coroutine 5: connection %d created (auto cleanup test)
coroutine 6: connection %d created (statement cleanup test)
all cleanup tests completed
cleanup test 1: coroutine_1_completed
cleanup test 2: coroutine_2_completed
cleanup test 3: coroutine_3_completed
cleanup test 4: coroutine_4_completed
cleanup test 5: coroutine_5_completed
cleanup test 6: coroutine_6_completed
garbage collection forced
final connections: %d
connection difference: %d
cleanup: passed
end