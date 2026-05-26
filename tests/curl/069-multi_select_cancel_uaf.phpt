--TEST--
curl_multi_select: AsyncCancellation must not leave stale waker subscription (#145)
--DESCRIPTION--
When a coroutine parked in curl_multi_select() is cancelled, the SUSPEND-
failure path used to `return CURLM_INTERNAL_ERROR` directly, skipping the
finally: label that calls zend_async_waker_clean(). The coroutine's
resolve callback stayed subscribed to the multi event. During the user's
finally block, curl_multi_close()/curl_multi_remove_handle() drove libcurl
through multi_socket_cb(CURL_POLL_REMOVE), which fires CALLBACKS_NOTIFY
on the multi event — re-resuming the still-running coroutine and pushing
a stale entry into the scheduler runqueue. The next dequeue executed the
already-finalized coroutine and dereferenced its freed fcall, causing
heap-use-after-free and the runtime warning "Attempt to finalize a
coroutine that is still in the queue".
--EXTENSIONS--
curl
--FILE--
<?php

use function Async\spawn;
use function Async\delay;
use function Async\await_all;

$srv = stream_socket_server('tcp://127.0.0.1:0', $errno);
$addr = stream_socket_get_name($srv, false);

$accept = spawn(function () use ($srv) {
    while (true) {
        $c = @stream_socket_accept($srv, 30);
        if (!$c) return;
        while (($l = @fgets($c)) !== false && $l !== "\r\n") {}
        @fwrite($c, "HTTP/1.1 200 OK\r\nContent-Length: 1048576\r\n\r\n");
        for ($i = 0; $i < 1000; $i++) {
            delay(50);
            if (@fwrite($c, str_repeat('x', 64)) === false) return;
        }
        @fclose($c);
    }
});

$fetcher = spawn(function () use ($addr) {
    $mh = curl_multi_init();
    $easy = [];
    foreach ([1, 2] as $_) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://$addr/");
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, fn($ch, $d) => strlen($d));
        curl_multi_add_handle($mh, $ch);
        $easy[] = $ch;
    }
    try {
        do {
            curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh, 1.0);
        } while ($active);
    } catch (\Async\AsyncCancellation $e) {
        echo "cancelled\n";
    } finally {
        foreach ($easy as $ch) {
            @curl_multi_remove_handle($mh, $ch);
            @curl_close($ch);
        }
        @curl_multi_close($mh);
    }
});

$killer = spawn(function () use ($fetcher) {
    delay(30);
    $fetcher->cancel();
});

await_all([$fetcher, $killer]);
$accept->cancel();

echo "OK\n";
?>
--EXPECT--
cancelled
OK
