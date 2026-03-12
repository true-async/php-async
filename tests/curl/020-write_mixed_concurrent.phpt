--TEST--
Async curl_write: mixed CURLOPT_FILE and CURLOPT_WRITEFUNCTION in parallel
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await_all;

$server = async_test_server_start();

$tmpfile = tempnam(sys_get_temp_dir(), 'curl_write_mixed_');

// Coroutine 1: CURLOPT_FILE
$c1 = spawn(function() use ($server, $tmpfile) {
    $fp = fopen($tmpfile, 'w');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);
    fclose($fp);

    return ['mode' => 'FILE', 'http_code' => $http_code];
});

// Coroutine 2: CURLOPT_WRITEFUNCTION
$c2 = spawn(function() use ($server) {
    $received = '';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$received) {
        $received .= $data;
        return strlen($data);
    });

    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);

    return ['mode' => 'USER', 'http_code' => $http_code, 'size' => strlen($received), 'data' => $received];
});

// Coroutine 3: CURLOPT_RETURNTRANSFER (for comparison)
$c3 = spawn(function() use ($server) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    unset($ch);

    return ['mode' => 'RETURN', 'http_code' => $http_code, 'size' => strlen($response), 'data' => $response];
});

[$results, $exceptions] = await_all([$c1, $c2, $c3]);

$expected = str_repeat("ABCDEFGHIJ", 1000);

// FILE result
$file_contents = file_get_contents($tmpfile);
echo "FILE: HTTP {$results[0]['http_code']}, size=" . strlen($file_contents) . ", match=" . ($file_contents === $expected ? "yes" : "no") . "\n";

// USER result
echo "USER: HTTP {$results[1]['http_code']}, size={$results[1]['size']}, match=" . ($results[1]['data'] === $expected ? "yes" : "no") . "\n";

// RETURN result
echo "RETURN: HTTP {$results[2]['http_code']}, size={$results[2]['size']}, match=" . ($results[2]['data'] === $expected ? "yes" : "no") . "\n";

echo "Exceptions: " . count(array_filter($exceptions)) . "\n";

@unlink($tmpfile);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
FILE: HTTP 200, size=10000, match=yes
USER: HTTP 200, size=10000, match=yes
RETURN: HTTP 200, size=10000, match=yes
Exceptions: 0
Done
