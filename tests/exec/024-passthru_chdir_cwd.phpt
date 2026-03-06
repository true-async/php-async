--TEST--
passthru() respects virtual CWD after chdir()
--FILE--
<?php

use function Async\spawn;

$cmd = PHP_OS_FAMILY === 'Windows' ? 'cd' : 'pwd';
$tmpdir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php_exec_cwd_test_' . getmypid();
mkdir($tmpdir);

spawn(function () use ($tmpdir, $cmd) {
    chdir($tmpdir);
    ob_start();
    passthru($cmd, $rc);
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
