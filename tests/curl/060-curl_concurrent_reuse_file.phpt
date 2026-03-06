--TEST--
Concurrent curl handle reuse with CURLOPT_FILE in multiple coroutines
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await_all;

$server = async_test_server_start();
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

$results = [];
$coroutines = [];
for ($c = 0; $c < 3; $c++) {
    $coroutines[] = spawn(function() use ($server, $c, &$results, $nullDevice) {
        $ch = curl_init();
        $fp = fopen($nullDevice, 'w');

        for ($i = 0; $i < 5; $i++) {
            curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/");
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_exec($ch);
        }

        fclose($fp);
        $results[$c] = "coroutine $c done";
    });
}

await_all($coroutines);
ksort($results);
foreach ($results as $line) {
    echo $line . "\n";
}
echo "PASS: concurrent reuse\n";

async_test_server_stop($server);
?>
--EXPECT--
coroutine 0 done
coroutine 1 done
coroutine 2 done
PASS: concurrent reuse
