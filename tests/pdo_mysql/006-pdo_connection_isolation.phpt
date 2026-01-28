--TEST--
PDO MySQL: Connection isolation test - connections cannot be shared between coroutines
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

// Create connection in main context
$mainPdo = AsyncPDOMySQLTest::factory();

// Get main connection ID
$stmt = $mainPdo->query("SELECT CONNECTION_ID() as conn_id");
$mainConnId = $stmt->fetch(PDO::FETCH_ASSOC)['conn_id'];
echo "main connection id: $mainConnId\n";

// Test: Each coroutine should create its own connection
$coroutines = [
    spawn(function() {
        // Create new connection in coroutine
        $pdo = AsyncPDOMySQLTest::factory();
        
        $stmt = $pdo->query("SELECT CONNECTION_ID() as conn_id");
        $connId = $stmt->fetch(PDO::FETCH_ASSOC)['conn_id'];
        return ['type' => 'new_connection_1', 'conn_id' => $connId];
    }),
    
    spawn(function() {
        // Create another new connection in different coroutine
        $pdo = AsyncPDOMySQLTest::factory();
        
        $stmt = $pdo->query("SELECT CONNECTION_ID() as conn_id");
        $connId = $stmt->fetch(PDO::FETCH_ASSOC)['conn_id'];
        return ['type' => 'new_connection_2', 'conn_id' => $connId];
    }),
    
    spawn(function() use ($mainPdo, $mainConnId) {
        // Test using connection from main context (this should work but be isolated)
        try {
            $stmt = $mainPdo->query("SELECT CONNECTION_ID() as conn_id");
            $connId = $stmt->fetch(PDO::FETCH_ASSOC)['conn_id'];
            
            $sameAsMain = ($connId == $mainConnId);
            return ['type' => 'shared_connection', 'conn_id' => $connId, 'same_as_main' => $sameAsMain];
        } catch (Exception $e) {
            return ['type' => 'shared_connection', 'conn_id' => null, 'error' => $e->getMessage()];
        }
    })
];

$results = await_all_or_fail($coroutines);

// Sort results by type for deterministic output
usort($results, function($a, $b) {
    return strcmp($a['type'], $b['type']);
});

// Display results in deterministic order
foreach ($results as $result) {
    if ($result['type'] === 'new_connection_1') {
        echo "new connection 1 id: " . $result['conn_id'] . "\n";
    } elseif ($result['type'] === 'new_connection_2') {
        echo "new connection 2 id: " . $result['conn_id'] . "\n";
    } elseif ($result['type'] === 'shared_connection') {
        if (isset($result['error'])) {
            echo "shared connection error: " . $result['error'] . "\n";
        } else {
            echo "shared connection id: " . $result['conn_id'] . "\n";
            if ($result['same_as_main']) {
                echo "shared connection: same as main\n";
            } else {
                echo "shared connection: different from main\n";
            }
        }
    }
}

// Analyze connection isolation
$connIds = array_filter(array_map(function($r) { return $r['conn_id']; }, $results));
$allIds = array_merge([$mainConnId], $connIds);
$uniqueIds = array_unique($allIds);

echo "total connections tested: " . count($allIds) . "\n";
echo "unique connection ids: " . count($uniqueIds) . "\n";

// For proper isolation, the two new connections should have different IDs
$newConn1 = null;
$newConn2 = null;
foreach ($results as $result) {
    if ($result['type'] === 'new_connection_1') {
        $newConn1 = $result['conn_id'];
    } elseif ($result['type'] === 'new_connection_2') {
        $newConn2 = $result['conn_id'];
    }
}

if ($newConn1 && $newConn2 && $newConn1 != $newConn2) {
    echo "coroutine isolation: passed (different connections)\n";
} else {
    echo "coroutine isolation: failed (same connection)\n";
}

echo "end\n";

?>
--EXPECTF--
start
main connection id: %d
new connection 1 id: %d
new connection 2 id: %d
shared connection id: %d
shared connection: same as main
total connections tested: 4
unique connection ids: %d
coroutine isolation: passed (different connections)
end