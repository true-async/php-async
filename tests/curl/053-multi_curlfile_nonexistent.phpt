--TEST--
Async curl multi: CURLFile upload of nonexistent file returns error
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

$coroutine = spawn(function() use ($server) {
    $mh = curl_multi_init();

    $nonexistent = sys_get_temp_dir() . '/curl_multi_NONEXISTENT_' . uniqid() . '.txt';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$server->port}/upload");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => curl_file_create($nonexistent, 'text/plain', 'ghost.txt')]);

    curl_multi_add_handle($mh, $ch);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    while ($info = curl_multi_info_read($mh)) {
        if ($info['msg'] === CURLMSG_DONE) {
            echo "Transfer errno: " . $info['result'] . "\n";
            echo "Has error: " . ($info['result'] !== 0 ? "yes" : "no") . "\n";
        }
    }

    $errno = curl_errno($ch);
    $error = curl_error($ch);
    echo "curl_errno: $errno\n";
    echo "curl_error: " . ($error ? "present" : "none") . "\n";

    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
});

await($coroutine);

async_test_server_stop($server);
echo "Done\n";
?>
--EXPECTF--
Transfer errno: %d
Has error: yes
curl_errno: %d
curl_error: present
Done
