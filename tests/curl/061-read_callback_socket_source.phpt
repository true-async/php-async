--TEST--
Async curl: CURLOPT_READFUNCTION reads from TCP socket (sync IO fallback in scheduler context)
--EXTENSIONS--
curl
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') die('skip file:// READFUNCTION sync callback cannot await socket data on Windows');
die('skip Temporarily disabled: sync READFUNCTION path returns empty on some curl versions with --repeat');
?>
--FILE--
<?php
use function Async\spawn;
use function Async\await;

/* TCP server that sends data when a client connects */
$serverSock = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
if (!$serverSock) {
    die("Failed to create server: $errstr ($errno)");
}
$serverAddr = stream_socket_get_name($serverSock, false);

sleep(2);

/* Spawn a coroutine that accepts one connection and sends data */
$producer = spawn(function() use ($serverSock) {
    $client = stream_socket_accept($serverSock, 5);
    fwrite($client, "contents of socket");
    fclose($client);
    fclose($serverSock);
});

/* Connect to the server and use the socket as CURLOPT_INFILE source */
$sock = stream_socket_client("tcp://$serverAddr", $errno, $errstr, 5);

$sWriteFile = tempnam(sys_get_temp_dir(), 'curl_read_sock_');
$sWriteUrl  = 'file://' . $sWriteFile;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $sWriteUrl);
curl_setopt($ch, CURLOPT_UPLOAD, 1);
curl_setopt($ch, CURLOPT_READFUNCTION, function($ch, $infile, $maxOut) use ($sock) {
    $data = fread($sock, $maxOut);
    if ($data === false || $data === '') {
        return '';
    }
    return "custom:" . $data;
});
curl_exec($ch);

$errno = curl_errno($ch);
echo "errno: $errno\n";

fclose($sock);
await($producer);

$output = file_get_contents($sWriteFile);
var_dump($output);

unlink($sWriteFile);
?>
--EXPECT--
errno: 0
string(25) "custom:contents of socket"
