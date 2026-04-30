--TEST--
Async\loadavg: array of three floats on POSIX, null on Windows
--FILE--
<?php

use function Async\loadavg;

$la = loadavg();

$ok = PHP_OS_FAMILY === 'Windows'
    ? $la === null
    : is_array($la) && count($la) === 3
        && is_float($la[0]) && is_float($la[1]) && is_float($la[2])
        && $la[0] >= 0.0 && $la[1] >= 0.0 && $la[2] >= 0.0;
var_dump($ok);

try {
    loadavg(1);
} catch (\ArgumentCountError $e) {
    echo "arity: ok\n";
}

echo "done\n";
?>
--EXPECT--
bool(true)
arity: ok
done
