--TEST--
Sequential async file writes maintain correct offsets
--DESCRIPTION--
Multiple sequential writes to a file must produce the correct total
content without gaps or overwrites.
--FILE--
<?php
$file = tempnam(sys_get_temp_dir(), 'async_seq_');
$fh = fopen($file, 'w');

fwrite($fh, "AAA");
fwrite($fh, "BBB");
fwrite($fh, "CCC");

var_dump(ftell($fh));
fclose($fh);

var_dump(file_get_contents($file));
unlink($file);
?>
--EXPECT--
int(9)
string(9) "AAABBBCCC"
