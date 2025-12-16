--TEST--
PDO MySQL: Order-independent concurrent test example
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

// Initialize database first
AsyncPDOMySQLTest::initDatabase();

$task_a = spawn(function() {
    $pdo = AsyncPDOMySQLTest::factory();
    $stmt = $pdo->query("SELECT 'task_a' as task, CONNECTION_ID() as conn_id");
    return $stmt->fetch(PDO::FETCH_ASSOC);
});

$task_b = spawn(function() {
    $pdo = AsyncPDOMySQLTest::factory();
    $stmt = $pdo->query("SELECT 'task_b' as task, CONNECTION_ID() as conn_id");
    return $stmt->fetch(PDO::FETCH_ASSOC);
});

$task_c = spawn(function() {
    $pdo = AsyncPDOMySQLTest::factory();
    $stmt = $pdo->query("SELECT 'task_c' as task, CONNECTION_ID() as conn_id");
    return $stmt->fetch(PDO::FETCH_ASSOC);
});

$results = [
    await($task_a),
    await($task_b),
    await($task_c)
];

// Check if we got valid results
if (empty($results)) {
    echo "Error: No results received from coroutines\n";
    echo "end\n";
    exit;
}

// Assert function - check results without depending on order
$tasks = array_map(function($r) { 
    return is_array($r) && isset($r['task']) ? $r['task'] : 'unknown'; 
}, $results);
sort($tasks);

$connIds = array_map(function($r) { 
    return is_array($r) && isset($r['conn_id']) ? $r['conn_id'] : 0; 
}, $results);
$uniqueConnIds = array_unique($connIds);

echo "tasks completed: " . implode(', ', $tasks) . "\n";
echo "unique connections: " . count($uniqueConnIds) . "\n";
echo "total tasks: " . count($results) . "\n";

// Verify we got all expected tasks
$expectedTasks = ['task_a', 'task_b', 'task_c'];
if ($tasks === $expectedTasks) {
    echo "task verification: passed\n";
} else {
    echo "task verification: failed\n";
}

// Verify connection isolation
if (count($uniqueConnIds) === count($results)) {
    echo "connection isolation: passed\n";
} else {
    echo "connection isolation: failed\n";
}

echo "concurrent test completed\n";
echo "end\n";

?>
--EXPECT--
start
tasks completed: task_a, task_b, task_c
unique connections: 3
total tasks: 3
task verification: passed
connection isolation: passed
concurrent test completed
end