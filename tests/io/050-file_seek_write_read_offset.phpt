--TEST--
fseek + fwrite + fread maintain correct file offsets
--FILE--
<?php
$file = tempnam(sys_get_temp_dir(), 'async_seek_');
$fh = fopen($file, 'w+');

// Write initial data
fwrite($fh, "0123456789");
var_dump(ftell($fh)); // 10

// Seek back and overwrite middle
fseek($fh, 3);
fwrite($fh, "XYZ");
var_dump(ftell($fh)); // 6

// Seek to start and read all
fseek($fh, 0);
var_dump(fread($fh, 100)); // "012XYZ6789"
var_dump(ftell($fh)); // 10

fclose($fh);
unlink($file);
?>
--EXPECT--
int(10)
int(6)
string(10) "012XYZ6789"
int(10)
