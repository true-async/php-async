--TEST--
Async curl: progress callback works in async context
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $progress_called = 0;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, function($ch, $dltotal, $dlnow, $ultotal, $ulnow) use (&$progress_called) {
        $progress_called++;
        return 0; // continue
    });

    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $length = strlen($result);

    unset($ch);

    echo "curl_exec returned: " . ($result !== false ? "yes" : "no") . "\n";
    echo "errno: $errno\n";
    echo "HTTP Code: $http_code\n";
    echo "Response length: $length\n";
    echo "Progress called: " . ($progress_called > 0 ? "yes ($progress_called times)" : "no") . "\n";
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned: yes
errno: 0
HTTP Code: 200
Response length: 10000
Progress called: yes (%d times)
Done
