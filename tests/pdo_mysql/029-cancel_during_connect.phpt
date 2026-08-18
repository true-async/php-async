--TEST--
PDO MySQL: a cancellation landing mid-connect surfaces as AsyncCancellation
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

// Cancel at a spread of delays so that some attempts land while the driver is
// still connecting. There the socket is torn down under it and the driver
// reports a connection error of its own - the caller must still see the
// cancellation, or catch (AsyncCancellation) stops working.
$outcomes = [];

for ($delay = 0; $delay <= 4000; $delay += 200) {
    $coroutine = spawn(function() {
        try {
            AsyncPDOMySQLTest::factory();
            return "connected";
        } catch (Async\AsyncCancellation $e) {
            return "cancelled";
        } catch (Throwable $e) {
            return "leaked " . get_class($e);
        }
    });

    if ($delay > 0) {
        usleep($delay);
    }

    $coroutine->cancel();

    try {
        $outcomes[await($coroutine)] = true;
    } catch (Async\AsyncCancellation $e) {
        $outcomes["cancelled before start"] = true;
    } catch (Throwable $e) {
        $outcomes["leaked " . get_class($e)] = true;
    }
}

$seen = array_keys($outcomes);
sort($seen);

foreach ($seen as $outcome) {
    echo $outcome, "\n";
}

echo "end\n";
?>
--EXPECT--
cancelled
cancelled before start
connected
end
