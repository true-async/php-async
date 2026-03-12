--TEST--
Async curl: exception in CURLOPT_XFERINFOFUNCTION callback
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $call_count = 0;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, function($ch, $dltotal, $dlnow, $ultotal, $ulnow) use (&$call_count) {
        $call_count++;
        if ($call_count >= 2) {
            throw new RuntimeException("Progress callback error");
        }
        return 0;
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
