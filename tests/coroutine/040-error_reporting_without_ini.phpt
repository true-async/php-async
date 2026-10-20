--TEST--
A coroutine inherits E_ALL when the error_reporting INI directive is not set
--FILE--
<?php
$php = getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY;
$code = <<<'PHP'
echo error_reporting() === E_ALL ? "E_ALL" : error_reporting(), "\n";
Async\await(Async\spawn(function () {
    echo error_reporting() === E_ALL ? "E_ALL" : error_reporting(), "\n";
    echo $undefined;
    echo "after\n";
}));
PHP;

$process = proc_open([$php, '-n', '-r', $code], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, []);
echo stream_get_contents($pipes[1]);
fclose($pipes[1]);
proc_close($process);
?>
--EXPECTF--
E_ALL
E_ALL

Warning: Undefined variable $undefined in Command line code on line 4
after
