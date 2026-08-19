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
use function Async\delay;
use function Async\timeout;

echo "start\n";

// Test 1: Manual cancellation
echo "starting long query\n";
$queryStarted = false;
$coroutine = spawn(function() use (&$queryStarted) {
    try {
        $pdo = AsyncPDOMySQLTest::factory();
        
        // This query should take several seconds
        echo "echo\n";
        $queryStarted = true;
        $stmt = $pdo->query("SELECT SLEEP(5), 'long query completed' as message");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return "completed";
    } catch (Async\AsyncCancellation $e) {
        return "cancelled";
    }
});

// Wait for the query to be under way. A fixed sleep here would be a bet that
// connecting finishes inside it, and a loaded machine loses that bet: the
// cancellation then lands during connect, before the line above is reached.
while (!$queryStarted) {
    delay(5);
}

echo "cancelling long query\n";
$coroutine->cancel();

// Wait for the original coroutine (should be cancelled)
try {
    $result = await($coroutine);
    echo "original query result: " . $result . "\n";
} catch (Async\AsyncCancellation $e) {
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