--TEST--
Pipe timeout with concurrent coroutines
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "Start\n";

$php = getenv('TEST_PHP_EXECUTABLE');
if ($php === false) {
    die("skip no php executable defined");
}

// Fast child: responds immediately
$proc1 = proc_open(
    [$php, "-r", "echo \"fast\\n\";"],
    [0 => ["pipe", "r"], 1 => ["pipe", "w"]],
    $pipes1
);

// Slow child: sleeps 1s
$proc2 = proc_open(
    [$php, "-r", "sleep(1); echo \"slow\\n\";"],
    [0 => ["pipe", "r"], 1 => ["pipe", "w"]],
    $pipes2
);

stream_set_timeout($pipes1[1], 1);
stream_set_timeout($pipes2[1], 0, 100000); // 100ms

$fast = spawn(function() use ($pipes1) {
    $line = fgets($pipes1[1]);
    $meta = stream_get_meta_data($pipes1[1]);
    return "fast:" . trim($line) . ",timeout:" . ($meta['timed_out'] ? "yes" : "no");
});

$slow = spawn(function() use ($pipes2) {
    $line = fgets($pipes2[1]);
    $meta = stream_get_meta_data($pipes2[1]);
    return "slow:" . var_export($line, true) . ",timeout:" . ($meta['timed_out'] ? "yes" : "no");
});

[$results, $exceptions] = await_all([$fast, $slow]);

$output = $results;
sort($output);
foreach ($output as $r) {
    echo "$r\n";
}

fclose($pipes1[0]); fclose($pipes1[1]);
fclose($pipes2[0]); fclose($pipes2[1]);
proc_close($proc1);
proc_close($proc2);

echo "End\n";

?>
--EXPECT--
Start
fast:fast,timeout:no
slow:false,timeout:yes
End
