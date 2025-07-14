--TEST--
MySQLi: Async prepared statements
--EXTENSIONS--
async
mysqli
--SKIPIF--
<?php
if (!extension_loaded('mysqli')) die('skip mysqli not available');
if (!getenv('MYSQL_TEST_HOST')) die('skip MYSQL_TEST_HOST not set');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "start\n";

$coroutine = spawn(function() {
    $host = getenv("MYSQL_TEST_HOST") ?: "127.0.0.1";
    $port = getenv("MYSQL_TEST_PORT") ?: 3306;
    $user = getenv("MYSQL_TEST_USER") ?: "root";
    $passwd = getenv("MYSQL_TEST_PASSWD") ?: "";
    $db = getenv("MYSQL_TEST_DB") ?: "test";
    
    try {
        $mysqli = new mysqli($host, $user, $passwd, $db, $port);
        
        if ($mysqli->connect_error) {
            throw new Exception("Connection failed: " . $mysqli->connect_error);
        }
        
        // Create test table
        $mysqli->query("DROP TEMPORARY TABLE IF EXISTS async_prepared_test");
        $mysqli->query("CREATE TEMPORARY TABLE async_prepared_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), score INT, active BOOLEAN)");
        echo "table created\n";
        
        // Test INSERT prepared statement
        $stmt = $mysqli->prepare("INSERT INTO async_prepared_test (name, score, active) VALUES (?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        // Insert multiple records
        $name = "user1"; $score = 85; $active = true;
        $stmt->bind_param("sii", $name, $score, $active);
        $stmt->execute();
        
        $name = "user2"; $score = 92; $active = false;
        $stmt->bind_param("sii", $name, $score, $active);
        $stmt->execute();
        
        $name = "user3"; $score = 78; $active = true;
        $stmt->bind_param("sii", $name, $score, $active);
        $stmt->execute();
        
        $stmt->close();
        echo "inserted records with prepared statement\n";
        
        // Test SELECT prepared statement
        $stmt = $mysqli->prepare("SELECT id, name, score FROM async_prepared_test WHERE score > ? AND active = ?");
        if (!$stmt) {
            throw new Exception("Prepare SELECT failed: " . $mysqli->error);
        }
        
        $min_score = 80;
        $is_active = true;
        $stmt->bind_param("ii", $min_score, $is_active);
        $stmt->execute();
        
        $result = $stmt->get_result();
        echo "records with score > $min_score and active = $is_active:\n";
        
        while ($row = $result->fetch_assoc()) {
            echo "  id: {$row['id']}, name: {$row['name']}, score: {$row['score']}\n";
        }
        
        $stmt->close();
        
        // Test UPDATE prepared statement
        $stmt = $mysqli->prepare("UPDATE async_prepared_test SET score = score + ? WHERE name = ?");
        if (!$stmt) {
            throw new Exception("Prepare UPDATE failed: " . $mysqli->error);
        }
        
        $bonus = 5;
        $target_name = "user1";
        $stmt->bind_param("is", $bonus, $target_name);
        $stmt->execute();
        
        echo "updated $target_name with bonus $bonus points\n";
        echo "affected rows: " . $stmt->affected_rows . "\n";
        
        $stmt->close();
        
        // Verify update
        $result = $mysqli->query("SELECT name, score FROM async_prepared_test WHERE name = 'user1'");
        $row = $result->fetch_assoc();
        echo "user1 new score: {$row['score']}\n";
        $result->free();
        
        $mysqli->close();
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
table created
inserted records with prepared statement
records with score > 80 and active = 1:
  id: 1, name: user1, score: 85
  id: 2, name: user2, score: 92
updated user1 with bonus 5 points
affected rows: 1
user1 new score: 90
result: completed
end