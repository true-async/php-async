--TEST--
Async curl_write: CURLOPT_FILE with large response
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$tmpfile = tempnam(sys_get_temp_dir(), 'curl_write_large_');

$coroutine = spawn(function() use ($server, $tmpfile) {
    $fp = fopen($tmpfile, 'w');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    unset($ch);
    fclose($fp);

    echo "curl_exec returned: " . ($result ? "true" : "false") . "\n";
    echo "HTTP Code: $http_code\n";
    echo "Error: " . ($error ?: "none") . "\n";
});

await($coroutine);

$contents = file_get_contents($tmpfile);
$expected = str_repeat("ABCDEFGHIJ", 1000);
echo "File size: " . strlen($contents) . "\n";
echo "Content matches: " . ($contents === $expected ? "yes" : "no") . "\n";

@unlink($tmpfile);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned: true
HTTP Code: 200
Error: none
File size: 10000
Content matches: yes
Done
