--TEST--
Async curl_write: multiple parallel curl_exec with CURLOPT_WRITEFUNCTION
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await_all;

$server = async_test_server_start();

$coroutines = [];
for ($i = 1; $i <= 3; $i++) {
    $id = $i;
    $coroutines[] = spawn(function() use ($server, $id) {
        $received = '';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$received) {
            $received .= $data;
            return strlen($data);
        });

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        unset($ch);

        return ['id' => $id, 'http_code' => $http_code, 'size' => strlen($received), 'data' => $received];
    });
}

[$results, $exceptions] = await_all($coroutines);

usort($results, fn($a, $b) => $a['id'] - $b['id']);

$expected = str_repeat("ABCDEFGHIJ", 1000);

foreach ($results as $r) {
    echo "Request {$r['id']}: HTTP {$r['http_code']}, ";
    echo "size={$r['size']}, ";
    echo "match=" . ($r['data'] === $expected ? "yes" : "no") . "\n";
}

echo "Exceptions: " . count(array_filter($exceptions)) . "\n";

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Request 1: HTTP 200, size=10000, match=yes
Request 2: HTTP 200, size=10000, match=yes
Request 3: HTTP 200, size=10000, match=yes
Exceptions: 0
Done
