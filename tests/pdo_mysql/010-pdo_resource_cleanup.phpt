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

// Count *our* connections only by checking the IDs we recorded against the
// server's PROCESSLIST. SHOW STATUS LIKE 'Threads_connected' is global —
// under run-tests.php -jN it counts other parallel workers and produces
// false-positive "leaks".
function ourLiveConnections(array $ids) {
    if (empty($ids)) return 0;
    $pdo = AsyncPDOMySQLTest::factory();
    $list = implode(',', array_map('intval', $ids));
    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM information_schema.PROCESSLIST WHERE ID IN ($list)");
    return (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
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

// Collect connection IDs we created (not the no-cleanup coroutine 6 — its
// $pdo is captured by the closure and freed only when await_all_or_fail's
// result array goes out of scope; we don't probe it here).
$ourIds = [];
foreach ($results as $r) {
    if ($r['type'] === 'explicit_cleanup' && !empty($r['conn_id'])) {
        $ourIds[] = $r['conn_id'];
    }
}

// Poll PROCESSLIST: explicitly-closed connections must disappear within a
// reasonable window. Wait up to ~1s.
$leaked = 0;
for ($i = 0; $i < 100; $i++) {
    $leaked = ourLiveConnections($ourIds);
    if ($leaked === 0) break;
    usleep(10000); // 10ms
}

if ($leaked === 0) {
    echo "cleanup: passed\n";
} else {
    echo "cleanup: potential leak ($leaked of our connections still live)\n";
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
cleanup: passed
end