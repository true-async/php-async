--TEST--
Async curl: debug callback works in async context
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $debug_types = [];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_DEBUGFUNCTION, function($ch, $type, $data) use (&$debug_types) {
        $debug_types[$type] = ($debug_types[$type] ?? 0) + 1;
        return 0;
    });

    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);

    echo "curl_exec returned: " . ($result !== false ? "yes" : "no") . "\n";
    echo "errno: $errno\n";
    echo "HTTP Code: $http_code\n";
    echo "Response: $result\n";
    echo "Debug called: " . (array_sum($debug_types) > 0 ? "yes" : "no") . "\n";
    echo "Has CURLINFO_TEXT (0): " . (isset($debug_types[CURLINFO_TEXT]) ? "yes" : "no") . "\n";
    echo "Has CURLINFO_HEADER_OUT (2): " . (isset($debug_types[CURLINFO_HEADER_OUT]) ? "yes" : "no") . "\n";
    echo "Has CURLINFO_HEADER_IN (1): " . (isset($debug_types[CURLINFO_HEADER_IN]) ? "yes" : "no") . "\n";
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned: yes
errno: 0
HTTP Code: 200
Response: Hello World
Debug called: yes
Has CURLINFO_TEXT (0): yes
Has CURLINFO_HEADER_OUT (2): yes
Has CURLINFO_HEADER_IN (1): yes
Done
