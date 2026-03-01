--TEST--
Async curl_write: CURLOPT_FILE downloads response to file
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$tmpfile = tempnam(sys_get_temp_dir(), 'curl_write_file_');

$coroutine = spawn(function() use ($server, $tmpfile) {
    $fp = fopen($tmpfile, 'w');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

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
echo "File contents: $contents\n";
echo "File size: " . strlen($contents) . "\n";

@unlink($tmpfile);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned: true
HTTP Code: 200
Error: none
File contents: Hello World
File size: 11
Done
