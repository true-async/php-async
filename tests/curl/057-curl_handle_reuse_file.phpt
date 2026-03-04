--TEST--
Reusing curl handle with CURLOPT_FILE does not crash (write IO callback cleanup)
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

await(spawn(function() use ($server) {
    $ch = curl_init();
    $fp = fopen('/dev/null', 'w');

    for ($i = 0; $i < 10; $i++) {
        curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_exec($ch);
    }

    fclose($fp);
    echo "PASS: handle reuse with file\n";
}));

async_test_server_stop($server);
?>
--EXPECT--
PASS: handle reuse with file
