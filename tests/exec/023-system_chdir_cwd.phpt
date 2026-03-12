--TEST--
system() respects virtual CWD after chdir()
--SKIPIF--
<?php
// JIT + --repeat causes heap-use-after-free in zend_jit_rope_end (not async-specific)
if (ini_get("opcache.jit") && ini_get("opcache.jit") !== "0" && ini_get("opcache.jit") !== "off") echo "skip JIT rope_end UAF with --repeat";
?>
--FILE--
<?php

use function Async\spawn;

$cmd = PHP_OS_FAMILY === 'Windows' ? 'cd' : 'pwd';
$tmpdir = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'php_exec_cwd_test_' . getmypid();
mkdir($tmpdir);

spawn(function () use ($tmpdir, $cmd) {
    chdir($tmpdir);
    ob_start();
    system($cmd, $rc);
    $result = str_replace('\\', '/', trim(ob_get_clean()));
    $expected = str_replace('\\', '/', $tmpdir);
    var_dump($result === $expected);
    var_dump($rc);
    rmdir($tmpdir);
});
?>
--EXPECT--
bool(true)
int(0)
