--TEST--
MySQLi: Basic async connection test
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
        // Initialize database first
        $mysqli = AsyncMySQLiTest::initDatabase();
        echo "connected\n";
        
        // Test simple query
        $result = $mysqli->query("SELECT 1 as test");
        if ($result) {
            $row = $result->fetch_assoc();
            echo "query result: " . $row['test'] . "\n";
            $result->free();
        }
        
        $mysqli->close();
        echo "closed\n";
        
        return "success";
    } catch (Exception $e) {
        echo "error: " . $e->getMessage() . "\n";
        return "failed";
    }
});

$result = await($coroutine);
echo "awaited: " . $result . "\n";
echo "end\n";

?>
--EXPECT--
start
connected
query result: 1
closed
awaited: success
end