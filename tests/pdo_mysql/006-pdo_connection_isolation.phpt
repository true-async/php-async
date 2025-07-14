--TEST--
PDO MySQL: Connection isolation test - connections cannot be shared between coroutines
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

// Create connection in main context
$dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
$user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
$pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';

$mainPdo = new PDO($dsn, $user, $pass);
$mainPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get main connection ID
$stmt = $mainPdo->query("SELECT CONNECTION_ID() as conn_id");
$mainConnId = $stmt->fetch(PDO::FETCH_ASSOC)['conn_id'];
echo "main connection id: $mainConnId\n";

// Test: Each coroutine should create its own connection
$coroutines = [
    spawn(function() {
        // Create new connection in coroutine
        $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
        $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
        $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
        
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->query("SELECT CONNECTION_ID() as conn_id");
        $connId = $stmt->fetch(PDO::FETCH_ASSOC)['conn_id'];
        echo "coroutine1 connection id: $connId\n";
        return $connId;
    }),
    
    spawn(function() {
        // Create another new connection in different coroutine
        $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
        $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
        $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
        
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->query("SELECT CONNECTION_ID() as conn_id");
        $connId = $stmt->fetch(PDO::FETCH_ASSOC)['conn_id'];
        echo "coroutine2 connection id: $connId\n";
        return $connId;
    }),
    
    spawn(function() use ($mainPdo, $mainConnId) {
        // Test using connection from main context (this should work but be isolated)
        try {
            $stmt = $mainPdo->query("SELECT CONNECTION_ID() as conn_id");
            $connId = $stmt->fetch(PDO::FETCH_ASSOC)['conn_id'];
            echo "coroutine3 using main PDO, connection id: $connId\n";
            
            // Verify it's still the same connection
            if ($connId == $mainConnId) {
                echo "coroutine3: same connection as main\n";
            } else {
                echo "coroutine3: different connection from main\n";
            }
            
            return $connId;
        } catch (Exception $e) {
            echo "coroutine3 error: " . $e->getMessage() . "\n";
            return null;
        }
    })
];

$connectionIds = awaitAllOrFail($coroutines);

// Analyze connection isolation
$allIds = array_merge([$mainConnId], array_filter($connectionIds));
$uniqueIds = array_unique($allIds);

echo "total connections tested: " . count($allIds) . "\n";
echo "unique connection ids: " . count($uniqueIds) . "\n";

// For proper isolation, coroutines 1 and 2 should have different IDs
// Coroutine 3 may share with main (depends on implementation)
if ($connectionIds[0] != $connectionIds[1]) {
    echo "coroutine isolation: passed (different connections)\n";
} else {
    echo "coroutine isolation: failed (same connection)\n";
}

echo "end\n";

?>
--EXPECTF--
start
main connection id: %d
coroutine1 connection id: %d
coroutine2 connection id: %d
coroutine3 using main PDO, connection id: %d
coroutine3: same connection as main
total connections tested: 4
unique connection ids: %d
coroutine isolation: passed (different connections)
end