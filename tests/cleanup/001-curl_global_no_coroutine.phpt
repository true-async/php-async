--TEST--
Curl handle created in global scope without coroutine — clean shutdown
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

$server = async_test_server_start();

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);

$response = curl_exec($ch);
echo "Response: $response\n";

// Do NOT close $ch — let shutdown handle cleanup
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECT--
Response: Hello World
Done
