--TEST--
Async curl: CURLFile upload of nonexistent file returns error
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/upload");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);

    // Use a file that definitely does not exist
    $nonexistent = sys_get_temp_dir() . '/curl_upload_NONEXISTENT_' . uniqid() . '.txt';
    $file = curl_file_create($nonexistent, 'text/plain', 'ghost.txt');
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => $file]);

    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);

    echo "curl_exec returned: " . var_export($result, true) . "\n";
    echo "errno: $errno\n";
    // CURLE_READ_ERROR = 26 or CURLE_ABORTED_BY_CALLBACK = 42
    echo "has error: " . ($errno !== 0 ? "yes" : "no") . "\n";
    echo "error message: " . ($error ?: "none") . "\n";
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned: false
errno: %d
has error: yes
error message: %s
Done
