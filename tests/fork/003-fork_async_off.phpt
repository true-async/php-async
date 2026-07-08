--TEST--
pcntl_fork() is allowed unchanged when the async engine was never activated
--EXTENSIONS--
pcntl
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
?>
--FILE--
<?php

// No spawn/await: the async engine stays OFF, so fork() behaves like plain PHP
// with no guard and no reactor reinit.
$pid = pcntl_fork();

if ($pid === 0) {
    exit(7);
}

$status = 0;
pcntl_waitpid($pid, $status);

echo "child exit code: ", pcntl_wexitstatus($status), "\n";

?>
--EXPECT--
child exit code: 7
