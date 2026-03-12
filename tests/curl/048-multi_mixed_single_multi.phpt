--TEST--
Async curl: concurrent coroutines mixing curl_exec and curl_multi
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await_all;

$server = async_test_server_start();

// Coroutine 1: simple curl_exec (single mode)
$c1 = spawn(function() use ($server) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $result = curl_exec($ch);
    $errno = curl_errno($ch);

    return ['mode' => 'single', 'len' => strlen($result), 'errno' => $errno];
});

// Coroutine 2: curl_multi with multiple handles
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

    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_close($mh);

    return ['mode' => 'multi', 'r1' => $r1, 'r2' => $r2];
});

// Coroutine 3: curl_exec with WRITEFUNCTION
$c3 = spawn(function() use ($server) {
    $body = '';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $d) use (&$body) {
        $body .= $d;
        return strlen($d);
    });

    curl_exec($ch);
    $errno = curl_errno($ch);

    return ['mode' => 'single_cb', 'body' => $body, 'errno' => $errno];
});

[$results, $exceptions] = await_all([$c1, $c2, $c3]);

// Sort by mode for deterministic output
usort($results, fn($a, $b) => strcmp($a['mode'], $b['mode']));

foreach ($results as $r) {
    if ($r['mode'] === 'multi') {
        echo "multi: r1={$r['r1']}, r2={$r['r2']}\n";
    } elseif ($r['mode'] === 'single') {
        echo "single: len={$r['len']}, errno={$r['errno']}\n";
    } else {
        echo "single_cb: body={$r['body']}, errno={$r['errno']}\n";
    }
}

echo "Exceptions: " . count(array_filter($exceptions)) . "\n";

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
multi: r1=Hello World, r2={"message":"Hello JSON","status":"ok"}
single: len=10000, errno=0
single_cb: body={"message":"Hello JSON","status":"ok"}, errno=0
Exceptions: 0
Done
