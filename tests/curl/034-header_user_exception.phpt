--TEST--
Async curl: exception in CURLOPT_HEADERFUNCTION callback
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
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) {
        if (stripos($header, 'Content-Type') !== false) {
            throw new RuntimeException("Header callback error");
        }
        return strlen($header);
    });

    $result = curl_exec($ch);
    $errno = curl_errno($ch);

    echo "curl_exec returned: " . ($result !== false ? "data" : "false") . "\n";
    echo "errno: $errno\n";
});

try {
    await($coroutine);
} catch (\Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
%ADone
