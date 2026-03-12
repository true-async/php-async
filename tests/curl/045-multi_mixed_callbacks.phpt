--TEST--
Async curl multi: mixed callback modes across handles (RETURNTRANSFER + FILE + WRITEFUNCTION)
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$tmpfile = tempnam(sys_get_temp_dir(), 'curl_multi_mixed_');

$coroutine = spawn(function() use ($server, $tmpfile) {
    $fp = fopen($tmpfile, 'w');
    $user_data = '';

    $mh = curl_multi_init();

    // Handle 1: RETURNTRANSFER
    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);

    // Handle 2: CURLOPT_FILE
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch2, CURLOPT_FILE, $fp);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);

    // Handle 3: CURLOPT_WRITEFUNCTION
    $ch3 = curl_init();
    curl_setopt($ch3, CURLOPT_URL, "http://localhost:{$server->port}/large");
    curl_setopt($ch3, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch3, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$user_data) {
        $user_data .= $data;
        return strlen($data);
    });

    curl_multi_add_handle($mh, $ch1);
    curl_multi_add_handle($mh, $ch2);
    curl_multi_add_handle($mh, $ch3);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    $r1 = curl_multi_getcontent($ch1);
    $e1 = curl_errno($ch1);
    $e2 = curl_errno($ch2);
    $e3 = curl_errno($ch3);

    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_remove_handle($mh, $ch3);
    curl_multi_close($mh);

    fclose($fp);

    echo "Handle 1 (RETURNTRANSFER): $r1\n";
    echo "Handle 1 errno: $e1\n";
    echo "Handle 2 errno: $e2\n";
    echo "Handle 3 errno: $e3\n";
    echo "Handle 3 (WRITEFUNCTION) length: " . strlen($user_data) . "\n";
});

await($coroutine);

$file_content = file_get_contents($tmpfile);
echo "Handle 2 (FILE): $file_content\n";

@unlink($tmpfile);
async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Handle 1 (RETURNTRANSFER): Hello World
Handle 1 errno: 0
Handle 2 errno: 0
Handle 3 errno: 0
Handle 3 (WRITEFUNCTION) length: 10000
Handle 2 (FILE): {"message":"Hello JSON","status":"ok"}
Done
