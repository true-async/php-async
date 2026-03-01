--TEST--
Async curl_write: CURLOPT_WRITEFUNCTION with JSON response
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $chunks = [];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$chunks) {
        $chunks[] = $data;
        return strlen($data);
    });

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);

    $body = implode('', $chunks);
    $decoded = json_decode($body, true);

    echo "curl_exec returned: " . ($result ? "true" : "false") . "\n";
    echo "HTTP Code: $http_code\n";
    echo "Chunk count: " . count($chunks) . "\n";
    echo "Message: {$decoded['message']}\n";
    echo "Status: {$decoded['status']}\n";
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned: true
HTTP Code: 200
Chunk count: %d
Message: Hello JSON
Status: ok
Done
