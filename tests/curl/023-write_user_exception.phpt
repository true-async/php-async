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

    try {
        curl_exec($ch);
        echo "no exception\n";
    } catch (RuntimeException $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
caught: callback error
Done
