--TEST--
Async curl: CURLOPT_READFUNCTION reads from TCP socket (sync IO fallback on Windows)
--EXTENSIONS--
curl
--SKIPIF--
<?php
if (PHP_OS_FAMILY !== 'Windows') die('skip Windows only');
?>
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    /* Connect to HTTP server — data comes from external process,
     * no coroutine dependency, so sync poll in scheduler context works. */
    $sock = stream_socket_client("tcp://localhost:{$server->port}", $errno, $errstr, 5);
    fwrite($sock, "GET / HTTP/1.0\r\nHost: localhost\r\n\r\n");

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
        return $data;
    });
    curl_exec($ch);

    $errno = curl_errno($ch);
    echo "errno: $errno\n";

    fclose($sock);

    $output = file_get_contents($sWriteFile);
    echo (str_contains($output, 'Hello World') ? "Content: OK" : "Content: FAIL") . "\n";

    @unlink($sWriteFile);
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECT--
errno: 0
Content: OK
Done
