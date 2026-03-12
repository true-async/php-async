--TEST--
Output Buffer: ob_start auto-flush after file_put_contents
--FILE--
<?php

file_put_contents(sys_get_temp_dir() . '/ob_test_async', 'test data');

ob_start();
echo "buffered output\n";

// No explicit ob_end_flush — PHP should auto-flush at shutdown

?>
--EXPECT--
buffered output
