--TEST--
PDO MySQL: Async cancellation test
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
use function Async\timeout;

echo "start\n";

// Test 1: Manual cancellation
echo "starting long query\n";
$coroutine = spawn(function() {
    try {
        $pdo = AsyncPDOMySQLTest::factory();
        
        // This query should take several seconds
        echo "echo\n";
        $stmt = $pdo->query("SELECT SLEEP(5), 'long query completed' as message");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return "completed";
    } catch (Async\CancellationException $e) {
        return "cancelled";
    }
});

// Wait a bit, then cancel the coroutine
usleep(100000); // 0.1 seconds

echo "cancelling long query\n";
$coroutine->cancel();

// Wait for the original coroutine (should be cancelled)
try {
    $result = await($coroutine);
    echo "original query result: " . $result . "\n";
} catch (Async\CancellationException $e) {
    echo "original query was cancelled\n";
}

echo "manual cancel result: cancellation_sent\n";

echo "end\n";
?>
--EXPECT--
start
starting long query
echo
cancelling long query
original query result: cancelled
manual cancel result: cancellation_sent
end