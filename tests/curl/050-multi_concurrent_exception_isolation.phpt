--TEST--
Async curl multi: exception in one coroutine does not affect another coroutine's curl_multi
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await_all;

$server = async_test_server_start();

// Coroutine 1: throws exception in WRITEFUNCTION — should propagate
$c1 = spawn(function() use ($server) {
    $mh = curl_multi_init();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        throw new RuntimeException("coro1 callback error");
    });

    curl_multi_add_handle($mh, $ch);

    // Exception propagates from curl_multi_exec — don't catch it here
    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
});

// Coroutine 2: normal operation, should complete successfully
$c2 = spawn(function() use ($server) {
    $mh = curl_multi_init();

    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);

    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);

    curl_multi_add_handle($mh, $ch1);
    curl_multi_add_handle($mh, $ch2);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    $r1 = curl_multi_getcontent($ch1);
    $r2 = curl_multi_getcontent($ch2);
    $e1 = curl_errno($ch1);
    $e2 = curl_errno($ch2);

    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_close($mh);

    return ['r1' => $r1, 'r2' => $r2, 'e1' => $e1, 'e2' => $e2];
});

[$results, $exceptions] = await_all([$c1, $c2]);

// Coroutine 1 should have thrown — exception in $exceptions
if (isset($exceptions[0]) && $exceptions[0] instanceof RuntimeException) {
    echo "Coro 1 exception: " . $exceptions[0]->getMessage() . "\n";
} else {
    echo "Coro 1: no exception (unexpected)\n";
}

// Coroutine 2 should succeed regardless
echo "Coro 2 r1: {$results[1]['r1']}\n";
echo "Coro 2 r2: {$results[1]['r2']}\n";
echo "Coro 2 e1: {$results[1]['e1']}\n";
echo "Coro 2 e2: {$results[1]['e2']}\n";

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Coro 1 exception: coro1 callback error
Coro 2 r1: Hello World
Coro 2 r2: {"message":"Hello JSON","status":"ok"}
Coro 2 e1: 0
Coro 2 e2: 0
Done
