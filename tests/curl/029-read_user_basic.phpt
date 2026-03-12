--TEST--
Async curl: CURLOPT_READFUNCTION provides data via PHP callback for PUT request
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $data = str_repeat("ABCDE", 200); // 1000 bytes
    $offset = 0;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/put");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_INFILESIZE, strlen($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_READFUNCTION, function($ch, $infile, $length) use ($data, &$offset) {
        $chunk = substr($data, $offset, $length);
        $offset += strlen($chunk);
        return $chunk;
    });

    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);

    echo "curl_exec returned: " . ($result !== false ? "yes" : "no") . "\n";
    echo "errno: $errno\n";
    echo "HTTP Code: $http_code\n";
    echo "Response: $result\n";
    echo "All data sent: " . ($offset === strlen($data) ? "yes" : "no ($offset)") . "\n";
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned: yes
errno: 0
HTTP Code: 200
Response: PUT received: 1000 bytes
All data sent: yes
Done
