--TEST--
Async curl_write: CURLOPT_FILE with JSON response written to file
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$tmpfile = tempnam(sys_get_temp_dir(), 'curl_write_json_');

$coroutine = spawn(function() use ($server, $tmpfile) {
    $fp = fopen($tmpfile, 'w');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);
    fclose($fp);

    $contents = file_get_contents($tmpfile);
    $decoded = json_decode($contents, true);

    echo "curl_exec returned: " . ($result ? "true" : "false") . "\n";
    echo "HTTP Code: $http_code\n";
    echo "Message: {$decoded['message']}\n";
    echo "Status: {$decoded['status']}\n";
});

await($coroutine);

@unlink($tmpfile);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned: true
HTTP Code: 200
Message: Hello JSON
Status: ok
Done
