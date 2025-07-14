--TEST--
PDO MySQL: Async resource cleanup test
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

function getConnectionCount() {
    $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
    $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
    $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
    
    $pdo = new PDO($dsn, $user, $pass);
    $stmt = $pdo->query("SHOW STATUS LIKE 'Threads_connected'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) $result['Value'];
}

$initial_connections = getConnectionCount();
echo "initial connections: $initial_connections\n";

// Test resource cleanup in coroutines
$coroutines = [];

for ($i = 1; $i <= 5; $i++) {
    $coroutines[] = spawn(function() use ($i) {
        $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
        $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
        $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
        
        try {
            // Create connection
            $pdo = new PDO($dsn, $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get connection ID
            $stmt = $pdo->query("SELECT CONNECTION_ID() as conn_id");
            $conn_info = $stmt->fetch(PDO::FETCH_ASSOC);
            $conn_id = $conn_info['conn_id'];
            
            echo "coroutine $i: connection $conn_id created\n";
            
            // Do some work
            $pdo->exec("DROP TEMPORARY TABLE IF EXISTS cleanup_test_$i");
            $pdo->exec("CREATE TEMPORARY TABLE cleanup_test_$i (id INT, data VARCHAR(50))");
            
            $stmt = $pdo->prepare("INSERT INTO cleanup_test_$i (id, data) VALUES (?, ?)");
            for ($j = 1; $j <= 3; $j++) {
                $stmt->execute([$j, "data_$j"]);
            }
            
            // Query the data
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM cleanup_test_$i");
            $count_result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "coroutine $i: inserted {$count_result['count']} rows\n";
            
            // Explicitly close connection
            $pdo = null;
            echo "coroutine $i: connection $conn_id closed\n";
            
            return "coroutine_$i" . "_completed";
        } catch (Exception $e) {
            echo "coroutine $i error: " . $e->getMessage() . "\n";
            return "coroutine_$i" . "_failed";
        }
    });
}

// Test coroutine that exits without explicit cleanup
$coroutines[] = spawn(function() {
    $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
    $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
    $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
    
    $pdo = new PDO($dsn, $user, $pass);
    $stmt = $pdo->query("SELECT CONNECTION_ID() as conn_id");
    $conn_info = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "coroutine 6: connection {$conn_info['conn_id']} created (no explicit cleanup)\n";
    
    // Exit without calling $pdo = null (test automatic cleanup)
    return "coroutine_6_completed";
});

echo "waiting for all coroutines to complete\n";
$results = awaitAllOrFail($coroutines);

echo "all coroutines completed\n";
foreach ($results as $i => $result) {
    echo "result " . ($i + 1) . ": $result\n";
}

// Force garbage collection
gc_collect_cycles();
echo "garbage collection forced\n";

// Small delay to allow connection cleanup
usleep(100000); // 0.1 seconds

$final_connections = getConnectionCount();
echo "final connections: $final_connections\n";

$connection_diff = $final_connections - $initial_connections;
echo "connection difference: $connection_diff\n";

if ($connection_diff <= 1) { // Allow for our own test connection
    echo "cleanup: passed\n";
} else {
    echo "cleanup: potential leak ($connection_diff extra connections)\n";
}

echo "end\n";

?>
--EXPECTF--
start
initial connections: %d
waiting for all coroutines to complete
coroutine 1: connection %d created
coroutine 2: connection %d created
coroutine 3: connection %d created
coroutine 4: connection %d created
coroutine 5: connection %d created
coroutine 6: connection %d created (no explicit cleanup)
coroutine 1: inserted 3 rows
coroutine 1: connection %d closed
coroutine 2: inserted 3 rows
coroutine 2: connection %d closed
coroutine 3: inserted 3 rows
coroutine 3: connection %d closed
coroutine 4: inserted 3 rows
coroutine 4: connection %d closed
coroutine 5: inserted 3 rows
coroutine 5: connection %d closed
all coroutines completed
result 1: coroutine_1_completed
result 2: coroutine_2_completed
result 3: coroutine_3_completed
result 4: coroutine_4_completed
result 5: coroutine_5_completed
result 6: coroutine_6_completed
garbage collection forced
final connections: %d
connection difference: %d
cleanup: passed
end