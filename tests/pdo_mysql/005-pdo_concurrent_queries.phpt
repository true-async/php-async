--TEST--
PDO MySQL: Concurrent queries with separate connections
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

// Create coroutines that run concurrent queries
$coroutines = [
    spawn(function() {
        $pdo = createConnection();
        $stmt = $pdo->query("SELECT SLEEP(0.1), 'fast query' as type, CONNECTION_ID() as conn_id");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "completed: " . $result['type'] . " on connection " . $result['conn_id'] . "\n";
        return $result['type'];
    }),
    
    spawn(function() {
        $pdo = createConnection();
        $stmt = $pdo->query("SELECT SLEEP(0.2), 'medium query' as type, CONNECTION_ID() as conn_id");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "completed: " . $result['type'] . " on connection " . $result['conn_id'] . "\n";
        return $result['type'];
    }),
    
    spawn(function() {
        $pdo = createConnection();
        $stmt = $pdo->query("SELECT SLEEP(0.05), 'quick query' as type, CONNECTION_ID() as conn_id");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "completed: " . $result['type'] . " on connection " . $result['conn_id'] . "\n";
        return $result['type'];
    }),
    
    spawn(function() {
        $pdo = createConnection();
        // Test concurrent prepared statements
        $stmt = $pdo->prepare("SELECT ? as message, CONNECTION_ID() as conn_id");
        $stmt->execute(['prepared statement']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "completed: " . $result['message'] . " on connection " . $result['conn_id'] . "\n";
        return $result['message'];
    })
];

echo "waiting for all queries\n";
$results = awaitAllOrFail($coroutines);

echo "all queries completed\n";
echo "results count: " . count($results) . "\n";

foreach ($results as $i => $result) {
    echo "result[$i]: $result\n";
}

echo "end\n";

?>
--EXPECTF--
start
waiting for all queries
completed: %s on connection %d
completed: %s on connection %d
completed: %s on connection %d
completed: %s on connection %d
all queries completed
results count: 4
result[0]: %s
result[1]: %s
result[2]: %s
result[3]: %s
end