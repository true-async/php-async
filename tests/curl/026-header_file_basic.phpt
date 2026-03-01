--TEST--
Async curl: CURLOPT_HEADERFUNCTION with PHP_CURL_FILE saves headers to file
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$header_file = tempnam(sys_get_temp_dir(), 'curl_header_');

$coroutine = spawn(function() use ($server, $header_file) {
    $hfp = fopen($header_file, 'w');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_WRITEHEADER, $hfp);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);
    fclose($hfp);

    echo "curl_exec returned body: " . ($body !== false ? "yes" : "no") . "\n";
    echo "errno: $errno\n";
    echo "HTTP Code: $http_code\n";
});

await($coroutine);

$headers = file_get_contents($header_file);
echo "Headers contain HTTP: " . (str_contains($headers, 'HTTP/') ? "yes" : "no") . "\n";
echo "Headers not empty: " . (strlen($headers) > 0 ? "yes" : "no") . "\n";

@unlink($header_file);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned body: yes
errno: 0
HTTP Code: 200
Headers contain HTTP: yes
Headers not empty: yes
Done
