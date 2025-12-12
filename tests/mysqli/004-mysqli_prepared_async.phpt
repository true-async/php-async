--TEST--
MySQLi: Async prepared statements
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

echo "start\n";

$coroutine = spawn(function() {
    try {
        $mysqli = AsyncMySQLiTest::factory();
        
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
        $stmt = $mysqli->prepare("SELECT id, name, score FROM async_prepared_test WHERE score > ? AND active = ? ORDER BY id");
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
updated user1 with bonus 5 points
affected rows: 1
user1 new score: 90
result: completed
end