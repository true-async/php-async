--TEST--
PDO MySQL: Async prepare and execute statements
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

echo "start\n";

$coroutine = spawn(function() {
    $dsn = getenv('PDO_MYSQL_TEST_DSN') ?: 'mysql:host=localhost;dbname=test';
    $user = getenv('PDO_MYSQL_TEST_USER') ?: 'root';
    $pass = getenv('PDO_MYSQL_TEST_PASS') ?: '';
    
    try {
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create temporary table
        $pdo->exec("DROP TABLE IF EXISTS async_test");
        $pdo->exec("CREATE TEMPORARY TABLE async_test (id INT, name VARCHAR(50))");
        
        // Test prepared statement
        $stmt = $pdo->prepare("INSERT INTO async_test (id, name) VALUES (?, ?)");
        $stmt->execute([1, 'first']);
        $stmt->execute([2, 'second']);
        echo "inserted records\n";
        
        // Test prepared select
        $stmt = $pdo->prepare("SELECT * FROM async_test WHERE id = ?");
        $stmt->execute([1]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "selected: " . $result['name'] . "\n";
        
        // Test count
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM async_test");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "count: " . $count['cnt'] . "\n";
        
        return "completed";
    } catch (Exception $e) {
        echo "error: " . $e->getMessage() . "\n";
        return "failed";
    }
});

$result = await($coroutine);
echo "result: " . $result . "\n";
echo "end\n";

?>
--EXPECT--
start
inserted records
selected: first
count: 2
result: completed
end