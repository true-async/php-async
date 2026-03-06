--TEST--
Reusing curl handle with different CURLOPT_FILE streams each iteration
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

await(spawn(function() use ($server, $nullDevice) {
    $ch = curl_init();

    for ($i = 0; $i < 5; $i++) {
        $fp = fopen($nullDevice, 'w');
        curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_exec($ch);
        fclose($fp);
    }

    echo "PASS: different fp each iteration\n";
}));

async_test_server_stop($server);
?>
--EXPECT--
PASS: different fp each iteration
