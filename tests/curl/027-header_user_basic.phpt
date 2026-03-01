--TEST--
Async curl: CURLOPT_HEADERFUNCTION with user callback collects headers
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $headers = [];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$headers) {
        $trimmed = trim($header);
        if ($trimmed !== '') {
            $headers[] = $trimmed;
        }
        return strlen($header);
    });

    $body = curl_exec($ch);
    $errno = curl_errno($ch);

    unset($ch);

    echo "curl_exec returned body: " . ($body !== false ? "yes" : "no") . "\n";
    echo "errno: $errno\n";
    echo "Headers count > 0: " . (count($headers) > 0 ? "yes" : "no") . "\n";
    echo "Has HTTP status: " . (str_contains($headers[0], 'HTTP/') ? "yes" : "no") . "\n";

    $has_content_type = false;
    foreach ($headers as $h) {
        if (str_contains($h, 'Content-Type')) {
            $has_content_type = true;
            break;
        }
    }
    echo "Has Content-Type: " . ($has_content_type ? "yes" : "no") . "\n";
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
curl_exec returned body: yes
errno: 0
Headers count > 0: yes
Has HTTP status: yes
Has Content-Type: yes
Done
