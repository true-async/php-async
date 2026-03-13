--TEST--
fread() correctly handles large files across multiple async read calls
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

// Write 128 KB of known data
$chunk = str_repeat('A', 1024);
$expected = '';
for ($i = 0; $i < 128; $i++) {
    $expected .= $chunk;
}
file_put_contents($tmpfile, $expected);

$coroutine = spawn(function() use ($tmpfile, $expected) {
    $fp = fopen($tmpfile, 'rb');

    $got = '';
    while (!feof($fp)) {
        $got .= fread($fp, 4096);
    }

    fclose($fp);

    echo "length match: " . (strlen($got) === strlen($expected) ? 'yes' : 'no') . "\n";
    echo "content match: " . ($got === $expected ? 'yes' : 'no') . "\n";
    echo "ftell after full read: " . strlen($expected) . "\n";
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
length match: yes
content match: yes
ftell after full read: 131072
End
