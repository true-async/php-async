--TEST--
File offset stays in sync between dup'd fds (write)
--DESCRIPTION--
When two fds share the same open file description (via dup/redirect),
writes through async IO must advance the kernel file offset so the
other fd sees the correct position.
--FILE--
<?php
$file = tempnam(sys_get_temp_dir(), 'async_offset_');

$cmd = [PHP_BINARY, '-r', 'echo "Hello"; fprintf(STDERR, "World");'];
$proc = proc_open($cmd, [1 => ['file', $file, 'w'], 2 => ['redirect', 1]], $pipes);
proc_close($proc);

$contents = file_get_contents($file);
var_dump(strlen($contents));
// Both "Hello" and "World" must be present (order may vary)
var_dump(str_contains($contents, 'Hello'));
var_dump(str_contains($contents, 'World'));

unlink($file);
?>
--EXPECT--
int(10)
bool(true)
bool(true)
