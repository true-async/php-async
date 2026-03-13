--TEST--
File stream created in global scope without coroutine — clean shutdown
--FILE--
<?php
$tmpfile = tempnam(sys_get_temp_dir(), 'async_test_');

$fp = fopen($tmpfile, 'w');
fwrite($fp, "hello world");
// Do NOT close $fp — let shutdown handle cleanup

$fp2 = fopen($tmpfile, 'r');
$data = fread($fp2, 1024);
echo "Read: $data\n";
// Do NOT close $fp2 — let shutdown handle cleanup

@unlink($tmpfile);
echo "Done\n";
?>
--EXPECT--
Read: hello world
Done
