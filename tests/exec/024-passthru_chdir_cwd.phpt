--TEST--
passthru() respects virtual CWD after chdir()
--FILE--
<?php

use function Async\spawn;

$tmpdir = sys_get_temp_dir() . '/php_exec_cwd_test_' . getmypid();
mkdir($tmpdir);

spawn(function () use ($tmpdir) {
    chdir($tmpdir);
    ob_start();
    passthru('pwd', $rc);
    $result = trim(ob_get_clean());
    var_dump($result === $tmpdir);
    var_dump($rc);
    rmdir($tmpdir);
});
?>
--EXPECT--
bool(true)
int(0)
