--TEST--
Async curl_write: CURLOPT_WRITEFUNCTION with large response and multiple callback invocations
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $received = '';
    $call_count = 0;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$received, &$call_count) {
        $call_count++;
        $received .= $data;
        return strlen($data);
    });

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);

    echo "curl_exec returned: " . ($result ? "true" : "false") . "\n";
    echo "HTTP Code: $http_code\n";
    echo "Callback invocations: " . ($call_count >= 1 ? "at least 1" : "0") . "\n";
    echo "Total received: " . strlen($received) . "\n";

    $expected = str_repeat("ABCDEFGHIJ", 1000);
    echo "Content matches: " . ($received === $expected ? "yes" : "no") . "\n";
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned: true
HTTP Code: 200
Callback invocations: at least 1
Total received: 10000
Content matches: yes
Done
