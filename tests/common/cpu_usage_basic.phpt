--TEST--
Async\cpu_usage: first call returns zeros, second call returns delta
--FILE--
<?php

use function Async\cpu_usage;

$keys = ['process_cores', 'process_percent', 'system_percent',
         'cpu_count', 'interval_sec', 'loadavg'];

// First call seeds the internal "previous" snapshot — every numeric field is 0.
$first = cpu_usage();
var_dump(is_array($first));
foreach ($keys as $k) {
    var_dump(array_key_exists($k, $first));
}
var_dump($first['process_cores']   === 0.0);
var_dump($first['process_percent'] === 0.0);
var_dump($first['system_percent']  === 0.0);
var_dump($first['interval_sec']    === 0.0);
var_dump(is_int($first['cpu_count']) && $first['cpu_count'] >= 1);

// Burn CPU so the delta is measurable.
$x = 0;
for ($i = 0; $i < 5_000_000; $i++) { $x += $i; }

$second = cpu_usage();
var_dump($second['interval_sec']   > 0.0);
var_dump($second['process_cores']  >= 0.0);
var_dump($second['process_cores']  <= (float) $second['cpu_count'] + 0.01);
var_dump($second['process_percent'] >= 0.0);
var_dump($second['process_percent'] <= 100.5);
var_dump($second['system_percent']  >= 0.0);
var_dump($second['system_percent']  <= 100.0);
var_dump($second['cpu_count']      === $first['cpu_count']);

// loadavg: array of three floats on POSIX, null on Windows.
if (PHP_OS_FAMILY === 'Windows') {
    var_dump($second['loadavg'] === null);
} else {
    var_dump(is_array($second['loadavg']));
    var_dump(count($second['loadavg']) === 3);
    var_dump(is_float($second['loadavg'][0]));
}

// Arity check.
try {
    cpu_usage(1);
} catch (\ArgumentCountError $e) {
    echo "arity: ok\n";
}

echo "done\n";
?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
arity: ok
done
