--TEST--
exec() respects virtual CWD after chdir()
--FILE--
<?php

use function Async\spawn;

$tmpdir = sys_get_temp_dir() . '/php_exec_cwd_test_' . getmypid();
mkdir($tmpdir);

spawn(function () use ($tmpdir) {
    chdir($tmpdir);
    exec('pwd', $output, $rc);
    var_dump(trim($output[0]) === $tmpdir);
    var_dump($rc);
    rmdir($tmpdir);
});
?>
--EXPECT--
bool(true)
int(0)
