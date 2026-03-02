--TEST--
Async curl: large file PUT via CURLOPT_INFILE (1MB)
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$upload_file = tempnam(sys_get_temp_dir(), 'curl_read_large_');
$size = 1024 * 1024; // 1MB
file_put_contents($upload_file, str_repeat("X", $size));

$coroutine = spawn(function() use ($server, $upload_file, $size) {
    $fp = fopen($upload_file, 'r');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/put");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch, CURLOPT_INFILE, $fp);
    curl_setopt($ch, CURLOPT_INFILESIZE, $size);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);
    fclose($fp);

    echo "curl_exec returned: " . ($result !== false ? "yes" : "no") . "\n";
    echo "errno: $errno\n";
    echo "HTTP Code: $http_code\n";
    echo "Response: $result\n";
});

await($coroutine);

@unlink($upload_file);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned: yes
errno: 0
HTTP Code: 200
Response: PUT received: 1048576 bytes
Done
