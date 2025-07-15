--TEST--
PDO MySQL: Basic async connection test
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

// Test async PDO connection in coroutine
echo "start\n";

$coroutine = spawn(function() {
    try {
        // Initialize database first
        $pdo = AsyncPDOMySQLTest::initDatabase();
        echo "connected\n";
        
        // Test simple query
        $stmt = $pdo->query("SELECT 1 as test");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "query result: " . $result['test'] . "\n";
        
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
awaited: success
end