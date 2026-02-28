--TEST--
curl_exec with CURLFile upload crashes with fiber assertion
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();
echo "Server started on localhost:{$server->port}\n";

// Create a test file
$tmpfile = tempnam(sys_get_temp_dir(), 'curl_upload_test_');
file_put_contents($tmpfile, 'hello world');

$coroutine = spawn(function() use ($server, $tmpfile) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/upload");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);

    $file = curl_file_create($tmpfile, 'text/plain', 'test.txt');
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => $file]);

    echo "Before curl_exec\n";
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    echo "HTTP Code: $http_code\n";
    echo "Error: " . ($error ?: "none") . "\n";
    echo "Response: $response\n";
});

await($coroutine);

@unlink($tmpfile);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Server started on localhost:%d
Before curl_exec
HTTP Code: 200
Error: none
Response: %s
Done
