--TEST--
Async curl_write: CURLOPT_FILE to broken pipe triggers write error
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    // Create a socket pair, close the read end → writes get EPIPE
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $fp = $pair[0];
    fclose($pair[1]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $result = curl_exec($ch);
    $errno = curl_errno($ch);

    fclose($fp);

    echo "curl_exec returned: " . ($result ? "true" : "false") . "\n";
    echo "errno: $errno\n";
    // CURLE_WRITE_ERROR = 23
    echo "is write error: " . ($errno === 23 ? "yes" : "no") . "\n";
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned: false
errno: 23
is write error: yes
Done
