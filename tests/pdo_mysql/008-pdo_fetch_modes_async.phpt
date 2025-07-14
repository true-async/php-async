--TEST--
PDO MySQL: Async fetch modes
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
        
        // Create test data
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS fetch_test");
        $pdo->exec("CREATE TEMPORARY TABLE fetch_test (id INT, name VARCHAR(50), age INT, email VARCHAR(100))");
        
        $stmt = $pdo->prepare("INSERT INTO fetch_test (id, name, age, email) VALUES (?, ?, ?, ?)");
        $stmt->execute([1, 'Alice', 25, 'alice@example.com']);
        $stmt->execute([2, 'Bob', 30, 'bob@example.com']);
        $stmt->execute([3, 'Charlie', 35, 'charlie@example.com']);
        echo "test data created\n";
        
        // Test FETCH_ASSOC
        echo "testing FETCH_ASSOC:\n";
        $stmt = $pdo->query("SELECT id, name, age FROM fetch_test WHERE id = 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        foreach ($row as $key => $value) {
            echo "  $key: $value\n";
        }
        
        // Test FETCH_NUM
        echo "testing FETCH_NUM:\n";
        $stmt = $pdo->query("SELECT id, name, age FROM fetch_test WHERE id = 2");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        for ($i = 0; $i < count($row); $i++) {
            echo "  [$i]: {$row[$i]}\n";
        }
        
        // Test FETCH_BOTH
        echo "testing FETCH_BOTH:\n";
        $stmt = $pdo->query("SELECT id, name FROM fetch_test WHERE id = 3");
        $row = $stmt->fetch(PDO::FETCH_BOTH);
        echo "  by key 'name': {$row['name']}\n";
        echo "  by index [1]: {$row[1]}\n";
        
        // Test FETCH_OBJ
        echo "testing FETCH_OBJ:\n";
        $stmt = $pdo->query("SELECT name, age, email FROM fetch_test WHERE id = 1");
        $obj = $stmt->fetch(PDO::FETCH_OBJ);
        echo "  object->name: $obj->name\n";
        echo "  object->age: $obj->age\n";
        echo "  object->email: $obj->email\n";
        
        // Test fetchAll with FETCH_ASSOC
        echo "testing fetchAll FETCH_ASSOC:\n";
        $stmt = $pdo->query("SELECT name, age FROM fetch_test ORDER BY id");
        $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all_rows as $i => $row) {
            echo "  row $i: {$row['name']} (age {$row['age']})\n";
        }
        
        // Test fetchColumn
        echo "testing fetchColumn:\n";
        $stmt = $pdo->query("SELECT name FROM fetch_test ORDER BY age DESC");
        while ($name = $stmt->fetchColumn()) {
            echo "  name: $name\n";
        }
        
        // Test fetchAll with FETCH_KEY_PAIR
        echo "testing FETCH_KEY_PAIR:\n";
        $stmt = $pdo->query("SELECT id, name FROM fetch_test ORDER BY id");
        $pairs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($pairs as $id => $name) {
            echo "  id $id: $name\n";
        }
        
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
test data created
testing FETCH_ASSOC:
  id: 1
  name: Alice
  age: 25
testing FETCH_NUM:
  [0]: 2
  [1]: Bob
  [2]: 30
testing FETCH_BOTH:
  by key 'name': Charlie
  by index [1]: Charlie
testing FETCH_OBJ:
  object->name: Alice
  object->age: 25
  object->email: alice@example.com
testing fetchAll FETCH_ASSOC:
  row 0: Alice (age 25)
  row 1: Bob (age 30)
  row 2: Charlie (age 35)
testing fetchColumn:
  name: Charlie
  name: Bob
  name: Alice
testing FETCH_KEY_PAIR:
  id 1: Alice
  id 2: Bob
  id 3: Charlie
result: completed
end