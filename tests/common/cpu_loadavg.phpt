--TEST--
Async\loadavg: array of three floats on POSIX, null on Windows
--FILE--
<?php

use function Async\loadavg;

$la = loadavg();

if (PHP_OS_FAMILY === 'Windows') {
    var_dump($la === null);
} else {
    var_dump(is_array($la));
    var_dump(count($la) === 3);
    var_dump(is_float($la[0]));
    var_dump(is_float($la[1]));
    var_dump(is_float($la[2]));
    var_dump($la[0] >= 0.0);
    var_dump($la[1] >= 0.0);
    var_dump($la[2] >= 0.0);
}

try {
    loadavg(1);
} catch (\ArgumentCountError $e) {
    echo "arity: ok\n";
}

echo "done\n";
?>
--EXPECTF--
%A
arity: ok
done
