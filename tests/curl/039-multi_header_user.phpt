--TEST--
Async curl multi: CURLOPT_HEADERFUNCTION callback in multi mode
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $headers1 = [];
    $headers2 = [];

    $mh = curl_multi_init();

    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch1, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$headers1) {
        $trimmed = trim($header);
        if ($trimmed !== '') {
            $headers1[] = $trimmed;
        }
        return strlen($header);
    });

    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch2, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$headers2) {
        $trimmed = trim($header);
        if ($trimmed !== '') {
            $headers2[] = $trimmed;
        }
        return strlen($header);
    });

    curl_multi_add_handle($mh, $ch1);
    curl_multi_add_handle($mh, $ch2);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    $body1 = curl_multi_getcontent($ch1);
    $body2 = curl_multi_getcontent($ch2);

    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_close($mh);

    echo "Body 1: $body1\n";
    echo "Headers 1 has HTTP: " . (str_contains($headers1[0], 'HTTP/') ? "yes" : "no") . "\n";
    echo "Headers 1 count > 0: " . (count($headers1) > 0 ? "yes" : "no") . "\n";

    echo "Body 2: $body2\n";
    echo "Headers 2 has HTTP: " . (str_contains($headers2[0], 'HTTP/') ? "yes" : "no") . "\n";

    $has_json_ct = false;
    foreach ($headers2 as $h) {
        if (str_contains(strtolower($h), 'content-type') && str_contains($h, 'application/json')) {
            $has_json_ct = true;
            break;
        }
    }
    echo "Headers 2 has JSON Content-Type: " . ($has_json_ct ? "yes" : "no") . "\n";
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Body 1: Hello World
Headers 1 has HTTP: yes
Headers 1 count > 0: yes
Body 2: {"message":"Hello JSON","status":"ok"}
Headers 2 has HTTP: yes
Headers 2 has JSON Content-Type: yes
Done
