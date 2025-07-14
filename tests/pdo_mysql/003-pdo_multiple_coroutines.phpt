--TEST--
PDO MySQL: Multiple coroutines with separate connections
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

function createConnection() {
    $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
    $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
    $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
    
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

// Create multiple coroutines with separate connections
$coroutines = [
    spawn(function() {
        $pdo = createConnection();
        $stmt = $pdo->query("SELECT 'coroutine1' as source, CONNECTION_ID() as conn_id");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "from " . $result['source'] . " conn_id: " . $result['conn_id'] . "\n";
        return $result['conn_id'];
    }),
    
    spawn(function() {
        $pdo = createConnection();
        $stmt = $pdo->query("SELECT 'coroutine2' as source, CONNECTION_ID() as conn_id");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "from " . $result['source'] . " conn_id: " . $result['conn_id'] . "\n";
        return $result['conn_id'];
    }),
    
    spawn(function() {
        $pdo = createConnection();
        $stmt = $pdo->query("SELECT 'coroutine3' as source, CONNECTION_ID() as conn_id");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "from " . $result['source'] . " conn_id: " . $result['conn_id'] . "\n";
        return $result['conn_id'];
    })
];

$connectionIds = awaitAllOrFail($coroutines);

// Verify all connections are different
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
unique connections: 3
total coroutines: 3
isolation: passed
end