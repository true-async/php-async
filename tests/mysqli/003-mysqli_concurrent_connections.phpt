--TEST--
MySQLi: Concurrent connections in separate coroutines
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

// Create multiple coroutines with separate MySQLi connections
$coroutines = [
    spawn(function() {
        $mysqli = AsyncMySQLiTest::factory();
        $result = $mysqli->query("SELECT 'coroutine1' as source, CONNECTION_ID() as conn_id");
        $row = $result->fetch_assoc();
        $result->free();
        $mysqli->close();
        return ['source' => $row['source'], 'conn_id' => $row['conn_id']];
    }),
    
    spawn(function() {
        $mysqli = AsyncMySQLiTest::factory();
        $result = $mysqli->query("SELECT 'coroutine2' as source, CONNECTION_ID() as conn_id");
        $row = $result->fetch_assoc();
        $result->free();
        $mysqli->close();
        return ['source' => $row['source'], 'conn_id' => $row['conn_id']];
    }),
    
    spawn(function() {
        $mysqli = AsyncMySQLiTest::factory();
        $result = $mysqli->query("SELECT 'coroutine3' as source, CONNECTION_ID() as conn_id");
        $row = $result->fetch_assoc();
        $result->free();
        $mysqli->close();
        return ['source' => $row['source'], 'conn_id' => $row['conn_id']];
    }),
    
    spawn(function() {
        $mysqli = AsyncMySQLiTest::factory();
        // Test with some workload
        $mysqli->query("CREATE TEMPORARY TABLE temp_work (id INT, data VARCHAR(100))");
        $mysqli->query("INSERT INTO temp_work VALUES (1, 'data1'), (2, 'data2')");
        $result = $mysqli->query("SELECT COUNT(*) as count, CONNECTION_ID() as conn_id FROM temp_work");
        $row = $result->fetch_assoc();
        $result->free();
        $mysqli->close();
        return ['source' => 'coroutine4', 'conn_id' => $row['conn_id'], 'count' => $row['count']];
    })
];

$results = awaitAllOrFail($coroutines);

// Display results in deterministic order
usort($results, function($a, $b) {
    return strcmp($a['source'], $b['source']);
});

foreach ($results as $result) {
    if (isset($result['count'])) {
        echo "from {$result['source']} (with work) conn_id: {$result['conn_id']}, count: {$result['count']}\n";
    } else {
        echo "from {$result['source']} conn_id: {$result['conn_id']}\n";
    }
}

// Verify all connections are different
$connectionIds = array_map(function($r) { return $r['conn_id']; }, $results);
$uniqueIds = array_unique($connectionIds);
echo "unique connections: " . count($uniqueIds) . "\n";
echo "total coroutines: " . count($connectionIds) . "\n";

if (count($uniqueIds) === count($connectionIds)) {
    echo "isolation: passed\n";
} else {
    echo "isolation: failed\n";
}

echo "end\n";

?>
--EXPECTF--
start
from coroutine1 conn_id: %d
from coroutine2 conn_id: %d
from coroutine3 conn_id: %d
from coroutine4 (with work) conn_id: %d, count: 2
unique connections: 4
total coroutines: 4
isolation: passed
end