--TEST--
Output Buffer: ob_start() inside display handler triggers Fatal error (proc_open capture)
--FILE--
<?php
$php = getenv('TEST_PHP_EXECUTABLE');

$descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$proc = proc_open([$php, '-n', '-r', 'ob_start(function(){ob_start();});'], $descriptorspec, $pipes);
fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

proc_close($proc);

echo "STDOUT: [$stdout]\n";
echo "STDERR: [$stderr]\n";
?>
--XLEAK--
Fatal error path leaks due to zend_bailout() longjmp skipping cleanup
--EXPECTF--
STDOUT: [
Fatal error: ob_start(): Cannot use output buffering in output buffering display handlers in Command line code on line 1
Stack trace:
#0 Command line code(1): ob_start()
#1 [internal function]: {closure:Command line code:1}(%s)
#2 {main}
]
STDERR: []