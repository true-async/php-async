--TEST--
shell_exec() respects virtual CWD after chdir()
--FILE--
<?php

use function Async\spawn;

$tmpdir = sys_get_temp_dir() . '/php_exec_cwd_test_' . getmypid();
mkdir($tmpdir);

spawn(function () use ($tmpdir) {
    chdir($tmpdir);
    $result = trim(shell_exec('pwd'));
    var_dump($result === $tmpdir);
    rmdir($tmpdir);
});
?>
--EXPECT--
bool(true)
