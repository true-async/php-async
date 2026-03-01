--TEST--
Async curl_write: multiple parallel curl_exec with CURLOPT_FILE
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await_all;

$server = async_test_server_start();

$tmpfiles = [];
for ($i = 1; $i <= 3; $i++) {
    $tmpfiles[$i] = tempnam(sys_get_temp_dir(), "curl_write_concurrent_{$i}_");
}

$coroutines = [];
for ($i = 1; $i <= 3; $i++) {
    $id = $i;
    $coroutines[] = spawn(function() use ($server, $tmpfiles, $id) {
        $fp = fopen($tmpfiles[$id], 'w');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/large");
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        unset($ch);
        fclose($fp);

        return ['id' => $id, 'result' => $result, 'http_code' => $http_code];
    });
}

[$results, $exceptions] = await_all($coroutines);

// Sort by id for deterministic output
usort($results, fn($a, $b) => $a['id'] - $b['id']);

$expected = str_repeat("ABCDEFGHIJ", 1000);

foreach ($results as $r) {
    $contents = file_get_contents($tmpfiles[$r['id']]);
    echo "Request {$r['id']}: HTTP {$r['http_code']}, ";
    echo "size=" . strlen($contents) . ", ";
    echo "match=" . ($contents === $expected ? "yes" : "no") . "\n";
}

echo "Exceptions: " . count(array_filter($exceptions)) . "\n";

foreach ($tmpfiles as $f) {
    @unlink($f);
}

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Request 1: HTTP 200, size=10000, match=yes
Request 2: HTTP 200, size=10000, match=yes
Request 3: HTTP 200, size=10000, match=yes
Exceptions: 0
Done
