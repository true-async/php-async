--TEST--
PDO MySQL: Async resource cleanup test
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

function getConnectionCount() {
    $pdo = AsyncPDOMySQLTest::factory();
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
        try {
            // Create connection
            $pdo = AsyncPDOMySQLTest::factory();
            
            // Get connection ID
            $stmt = $pdo->query("SELECT CONNECTION_ID() as conn_id");
            $conn_info = $stmt->fetch(PDO::FETCH_ASSOC);
            $conn_id = $conn_info['conn_id'];
            
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
            
            // Explicitly close connection
            $pdo = null;
            
            return [
                'type' => 'explicit_cleanup',
                'coroutine_id' => $i,
                'conn_id' => $conn_id,
                'rows_inserted' => $count_result['count'],
                'status' => 'completed'
            ];
        } catch (Exception $e) {
            return [
                'type' => 'explicit_cleanup',
                'coroutine_id' => $i,
                'conn_id' => null,
                'rows_inserted' => 0,
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    });
}

// Test coroutine that exits without explicit cleanup
$coroutines[] = spawn(function() {
    $pdo = AsyncPDOMySQLTest::factory();
    $stmt = $pdo->query("SELECT CONNECTION_ID() as conn_id");
    $conn_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Exit without calling $pdo = null (test automatic cleanup)
    return [
        'type' => 'auto_cleanup',
        'coroutine_id' => 6,
        'conn_id' => $conn_info['conn_id'],
        'rows_inserted' => 0,
        'status' => 'completed'
    ];
});

echo "waiting for all coroutines to complete\n";
$results = await_all_or_fail($coroutines);

// Sort results by coroutine_id for deterministic output
usort($results, function($a, $b) {
    return $a['coroutine_id'] - $b['coroutine_id'];
});

echo "all coroutines completed\n";

// Display results in sorted order
foreach ($results as $result) {
    $id = $result['coroutine_id'];
    $conn_id = $result['conn_id'];
    $type = $result['type'];
    $status = $result['status'];
    
    if ($type === 'explicit_cleanup') {
        echo "coroutine $id: connection $conn_id created\n";
        if ($status === 'completed') {
            echo "coroutine $id: inserted {$result['rows_inserted']} rows\n";
            echo "coroutine $id: connection $conn_id closed\n";
        } else {
            echo "coroutine $id error: {$result['error']}\n";
        }
    } elseif ($type === 'auto_cleanup') {
        echo "coroutine $id: connection $conn_id created (no explicit cleanup)\n";
    }
}

// Display final results summary
foreach ($results as $i => $result) {
    $result_str = "coroutine_{$result['coroutine_id']}_{$result['status']}";
    echo "result " . ($i + 1) . ": $result_str\n";
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
all coroutines completed
coroutine 1: connection %d created
coroutine 1: inserted 3 rows
coroutine 1: connection %d closed
coroutine 2: connection %d created
coroutine 2: inserted 3 rows
coroutine 2: connection %d closed
coroutine 3: connection %d created
coroutine 3: inserted 3 rows
coroutine 3: connection %d closed
coroutine 4: connection %d created
coroutine 4: inserted 3 rows
coroutine 4: connection %d closed
coroutine 5: connection %d created
coroutine 5: inserted 3 rows
coroutine 5: connection %d closed
coroutine 6: connection %d created (no explicit cleanup)
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