--TEST--
curl_multi cleanup via GC should not cause use-after-free
--DESCRIPTION--
When a CurlMultiHandle is destroyed by GC (without explicit curl_multi_close),
curl_multi_cleanup() internally calls the socket callback (multi_socket_cb)
for connection teardown. If the async event was already freed by curl_async_dtor,
this causes a heap-use-after-free on the poll_list hash table.
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

function test_multi_gc_cleanup($port) {
    $mh = curl_multi_init();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$port}/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_multi_add_handle($mh, $ch);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active > 0);

    $result = curl_multi_getcontent($ch);
    echo "Result: $result\n";

    // Do NOT call curl_multi_remove_handle or curl_multi_close.
    // Drop all references so GC destroys the multi handle
    // while libcurl connections are still in the pool.
    unset($ch, $mh);

    // Force GC to collect the multi handle
    gc_collect_cycles();

    echo "GC completed without crash\n";
}

$coroutine = spawn(fn() => test_multi_gc_cleanup($server->port));
await($coroutine);

echo "Done\n";

async_test_server_stop($server);
?>
--EXPECT--
Result: Hello World
GC completed without crash
Done
