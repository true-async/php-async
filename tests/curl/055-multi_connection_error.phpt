--TEST--
Async curl multi: connection error (invalid port) reports error via curl_multi_info_read
--EXTENSIONS--
curl
--FILE--
<?php
use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    $mh = curl_multi_init();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1:1/nonexistent");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);

    curl_multi_add_handle($mh, $ch);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) break;
        if ($active > 0) curl_multi_select($mh, 1.0);
    } while ($active > 0);

    while ($info = curl_multi_info_read($mh)) {
        if ($info['msg'] === CURLMSG_DONE) {
            echo "Has error: " . ($info['result'] !== 0 ? "yes" : "no") . "\n";
        }
    }

    $errno = curl_errno($ch);
    $error = curl_error($ch);
    echo "curl_errno: " . ($errno !== 0 ? "nonzero" : "0") . "\n";
    echo "curl_error: " . (!empty($error) ? "present" : "none") . "\n";

    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
});

await($coroutine);
echo "Done\n";
?>
--EXPECTF--
Has error: yes
curl_errno: nonzero
curl_error: present
Done
