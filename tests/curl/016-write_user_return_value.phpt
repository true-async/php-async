--TEST--
Async curl_write: CURLOPT_WRITEFUNCTION return value controls transfer
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

// Test 1: returning less than data length aborts the transfer
$coroutine = spawn(function() use ($server) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        // Return 0 to abort transfer
        return 0;
    });

    $result = curl_exec($ch);
    $errno = curl_errno($ch);

    unset($ch);

    echo "Abort test: curl_exec returned: " . ($result ? "true" : "false") . "\n";
    echo "Abort test: errno: $errno\n";
    // CURLE_WRITE_ERROR = 23
    echo "Abort test: is write error: " . ($errno === 23 ? "yes" : "no") . "\n";
});

await($coroutine);

// Test 2: returning exact data length succeeds
$coroutine2 = spawn(function() use ($server) {
    $total = 0;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$total) {
        $len = strlen($data);
        $total += $len;
        return $len;
    });

    $result = curl_exec($ch);
    $errno = curl_errno($ch);

    unset($ch);

    echo "Accept test: curl_exec returned: " . ($result ? "true" : "false") . "\n";
    echo "Accept test: errno: $errno\n";
    echo "Accept test: total bytes: $total\n";
});

await($coroutine2);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Abort test: curl_exec returned: false
Abort test: errno: 23
Abort test: is write error: yes
Accept test: curl_exec returned: true
Accept test: errno: 0
Accept test: total bytes: 11
Done
