--TEST--
Async curl_write: CURLOPT_WRITEFUNCTION callback throws exception
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        throw new RuntimeException("callback error");
    });

    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);

    echo "curl_exec returned: " . ($result ? "true" : "false") . "\n";
    echo "errno: $errno\n";
    // CURLE_WRITE_ERROR = 23
    echo "is write error: " . ($errno === 23 ? "yes" : "no") . "\n";
    echo "error contains 'writing': " . (str_contains($error, 'riting') ? "yes" : "no") . "\n";
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned: false
errno: 23
is write error: yes
error contains 'writing': yes
Done
