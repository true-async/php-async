--TEST--
Async curl multi: CURLOPT_WRITEHEADER saves headers to file in multi mode
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$header_file = tempnam(sys_get_temp_dir(), 'curl_multi_header_');

$coroutine = spawn(function() use ($server, $header_file) {
    $hfp = fopen($header_file, 'w');

    $mh = curl_multi_init();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_WRITEHEADER, $hfp);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    curl_multi_add_handle($mh, $ch);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    $body = curl_multi_getcontent($ch);
    $errno = curl_errno($ch);

    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);

    fclose($hfp);

    echo "errno: $errno\n";
    echo "Body: $body\n";
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
errno: 0
Body: {"message":"Hello JSON","status":"ok"}
Headers contain HTTP: yes
Headers not empty: yes
Done
