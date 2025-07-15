--TEST--
MySQLi: Async result handling and fetch methods
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
        $mysqli = AsyncMySQLiTest::initDatabase();
        
        // Create test data
        $mysqli->query("DROP TEMPORARY TABLE IF EXISTS result_test");
        $mysqli->query("CREATE TEMPORARY TABLE result_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), score DECIMAL(5,2), active BOOLEAN)");
        
        $mysqli->query("INSERT INTO result_test (name, score, active) VALUES ('Alice', 95.5, 1)");
        $mysqli->query("INSERT INTO result_test (name, score, active) VALUES ('Bob', 87.3, 0)");
        $mysqli->query("INSERT INTO result_test (name, score, active) VALUES ('Charlie', 92.8, 1)");
        $mysqli->query("INSERT INTO result_test (name, score, active) VALUES ('Diana', 89.1, 1)");
        echo "test data created\n";
        
        // Test fetch_assoc
        echo "testing fetch_assoc:\n";
        $result = $mysqli->query("SELECT name, score FROM result_test WHERE id = 1");
        if ($result) {
            $row = $result->fetch_assoc();
            echo "  name: {$row['name']}, score: {$row['score']}\n";
            $result->free();
        }
        
        // Test fetch_array (numeric)
        echo "testing fetch_array (numeric):\n";
        $result = $mysqli->query("SELECT name, score FROM result_test WHERE id = 2");
        if ($result) {
            $row = $result->fetch_array(MYSQLI_NUM);
            echo "  [0]: {$row[0]}, [1]: {$row[1]}\n";
            $result->free();
        }
        
        // Test fetch_array (both)
        echo "testing fetch_array (both):\n";
        $result = $mysqli->query("SELECT name, score FROM result_test WHERE id = 3");
        if ($result) {
            $row = $result->fetch_array(MYSQLI_BOTH);
            echo "  by name: {$row['name']}, by index: {$row[1]}\n";
            $result->free();
        }
        
        // Test fetch_object
        echo "testing fetch_object:\n";
        $result = $mysqli->query("SELECT name, score, active FROM result_test WHERE id = 4");
        if ($result) {
            $obj = $result->fetch_object();
            echo "  object->name: $obj->name, object->score: $obj->score, object->active: $obj->active\n";
            $result->free();
        }
        
        // Test fetch_row
        echo "testing fetch_row:\n";
        $result = $mysqli->query("SELECT id, name FROM result_test WHERE active = 1 ORDER BY id LIMIT 2");
        if ($result) {
            while ($row = $result->fetch_row()) {
                echo "  row: id={$row[0]}, name={$row[1]}\n";
            }
            $result->free();
        }
        
        // Test fetch_all
        echo "testing fetch_all (MYSQLI_ASSOC):\n";
        $result = $mysqli->query("SELECT name, score FROM result_test WHERE active = 1 ORDER BY score DESC");
        if ($result) {
            $all_rows = $result->fetch_all(MYSQLI_ASSOC);
            foreach ($all_rows as $i => $row) {
                echo "  row $i: {$row['name']} (score: {$row['score']})\n";
            }
            $result->free();
        }
        
        // Test result metadata
        echo "testing result metadata:\n";
        $result = $mysqli->query("SELECT id, name, score, active FROM result_test LIMIT 1");
        if ($result) {
            echo "  num_rows: " . $result->num_rows . "\n";
            echo "  field_count: " . $result->field_count . "\n";
            
            // Get field info
            $fields = $result->fetch_fields();
            echo "  fields:\n";
            foreach ($fields as $field) {
                echo "    {$field->name} (type: {$field->type}, length: {$field->length})\n";
            }
            
            $result->free();
        }
        
        // Test data_seek
        echo "testing data_seek:\n";
        $result = $mysqli->query("SELECT name FROM result_test ORDER BY id");
        if ($result) {
            $result->data_seek(2); // Jump to 3rd row (index 2)
            $row = $result->fetch_assoc();
            echo "  row at index 2: {$row['name']}\n";
            $result->free();
        }
        
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
--EXPECTF--
start
test data created
testing fetch_assoc:
  name: Alice, score: 95.50
testing fetch_array (numeric):
  [0]: Bob, [1]: 87.30
testing fetch_array (both):
  by name: Charlie, by index: 92.80
testing fetch_object:
  object->name: Diana, object->score: 89.10, object->active: 1
testing fetch_row:
  row: id=1, name=Alice
  row: id=3, name=Charlie
testing fetch_all (MYSQLI_ASSOC):
  row 0: Alice (score: 95.50)
  row 1: Charlie (score: 92.80)
  row 2: Diana (score: 89.10)
testing result metadata:
  num_rows: 1
  field_count: 4
  fields:
    id (type: %d, length: %d)
    name (type: %d, length: %d)
    score (type: %d, length: %d)
    active (type: %d, length: %d)
testing data_seek:
  row at index 2: Charlie
result: completed
end