--TEST--
Closing CURLOPT_FILE stream before curl_close does not crash
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

    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_exec($ch);

    // Close file BEFORE curl handle — IO ref must keep it alive
    fclose($fp);

    // Second request after fclose — must not crash
    $fp2 = fopen('/dev/null', 'w');
    curl_setopt($ch, CURLOPT_FILE, $fp2);
    curl_exec($ch);
    fclose($fp2);

    echo "PASS: early fclose\n";
}));

async_test_server_stop($server);
?>
--EXPECT--
PASS: early fclose
