--TEST--
Async curl: a handle is not reset or reconfigured by another coroutine while curl_exec() runs on it; its callback is refused a reset as without TrueAsync
--EXTENSIONS--
curl
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

include __DIR__ . '/../../../../ext/curl/tests/server.inc';
$host = curl_cli_server_start();

$h = curl_init($host . '/get.inc?test=bigbody&mib=4');
$started = false;
$bytes = 0;
$first = true;

curl_setopt($h, CURLOPT_WRITEFUNCTION, function ($h, $data) use (&$started, &$bytes, &$first) {
    if ($first) {
        $first = false;
        try {
            curl_reset($h);
            echo "callback curl_reset: allowed\n";
        } catch (Error $e) {
            echo "callback: ", $e->getMessage(), "\n";
        }
    }
    $started = true;
    $bytes += strlen($data);
    return strlen($data);
});

$transfer = spawn(function () use ($h) {
    return curl_exec($h);
});

$other = spawn(function () use ($h, &$started) {
    while (!$started) {
        suspend();
    }

    $calls = [
        'curl_reset' => fn() => curl_reset($h),
        'curl_setopt' => fn() => curl_setopt($h, CURLOPT_URL, 'http://localhost/'),
        'curl_setopt_array' => fn() => curl_setopt_array($h, [CURLOPT_URL => 'http://localhost/']),
        'curl_copy_handle' => fn() => curl_copy_handle($h),
        'curl_exec' => fn() => curl_exec($h),
        'curl_multi_add_handle' => fn() => curl_multi_add_handle(curl_multi_init(), $h),
    ];

    foreach ($calls as $name => $call) {
        try {
            $call();
            echo "other $name: allowed\n";
        } catch (Error $e) {
            echo "other: ", $e->getMessage(), "\n";
        }
    }
});

await($other);
var_dump(await($transfer));
var_dump($bytes === 4 * 1024 * 1024, curl_error($h));

// After the transfer the handle is free again.
curl_setopt($h, CURLOPT_URL, $host . '/get.inc');
curl_setopt($h, CURLOPT_WRITEFUNCTION, null);
curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
var_dump(await(spawn(fn() => curl_exec($h))));
?>
--EXPECT--
callback: curl_reset(): Attempt to reset cURL handle from a callback
other: curl_reset(): Attempt to use cURL handle while curl_exec() is in progress on it
other: curl_setopt(): Attempt to use cURL handle while curl_exec() is in progress on it
other: curl_setopt_array(): Attempt to use cURL handle while curl_exec() is in progress on it
other: curl_copy_handle(): Attempt to use cURL handle while curl_exec() is in progress on it
other: curl_exec(): Attempt to use cURL handle while curl_exec() is in progress on it
other: curl_multi_add_handle(): Attempt to use cURL handle while curl_exec() is in progress on it
bool(true)
bool(true)
string(0) ""
string(25) "Hello World!
Hello World!"
