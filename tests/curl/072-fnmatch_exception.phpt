--TEST--
Async curl: exception in CURLOPT_FNMATCH_FUNCTION callback
--EXTENSIONS--
curl
--SKIPIF--
<?php
if (!function_exists('pcntl_fork')) die("skip pcntl_fork() not available");
if (!in_array('ftp', curl_version()['protocols'], true)) die("skip curl built without ftp");
?>
--FILE--
<?php
require __DIR__ . '/../../../../ext/ftp/tests/server.inc';

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function () use ($socket_name) {
    $thrown = false;

    $ch = curl_init("ftp://$socket_name/f*");
    curl_setopt($ch, CURLOPT_WILDCARDMATCH, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FNMATCH_FUNCTION, function ($ch, $pattern, $fname) use (&$thrown) {
        if (!$thrown) {
            $thrown = true;
            throw new RuntimeException("fnmatch callback error");
        }

        return CURL_FNMATCHFUNC_NOMATCH;
    });

    curl_exec($ch);
    echo "curl_exec returned instead of throwing\n";
});

try {
    await($coroutine);
    echo "await returned without an exception\n";
} catch (\Throwable $e) {
    echo "caught: ", get_class($e), ": ", $e->getMessage(), "\n";
}

echo "Done\n";
?>
--EXPECT--
caught: RuntimeException: fnmatch callback error
Done
