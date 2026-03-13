--TEST--
Mixed resources (curl + socket + file) in global scope without coroutine — clean shutdown
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';
require_once __DIR__ . '/../stream/stream_helper.php';

// 1. Curl
$server = async_test_server_start();
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
echo "Curl: $response\n";

// 2. Socket pair
$sockets = create_socket_pair();
list($sock1, $sock2) = $sockets;
fwrite($sock1, "socket data");
$data = fread($sock2, 1024);
echo "Socket: $data\n";

// 3. File
$tmpfile = tempnam(sys_get_temp_dir(), 'async_test_');
$fp = fopen($tmpfile, 'w+');
fwrite($fp, "file data");
rewind($fp);
$fdata = fread($fp, 1024);
echo "File: $fdata\n";

// Do NOT close anything — let shutdown handle all cleanup
async_test_server_stop($server);
@unlink($tmpfile);
echo "Done\n";
?>
--EXPECT--
Curl: Hello World
Socket: socket data
File: file data
Done
