--TEST--
Concurrent reads from one shared file handle deliver every byte exactly once
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "Start\n";

/* Records of a fixed width, each holding its own index, so that a record read
 * twice or lost is visible in the counts below. */
const RECORDS      = 2000;
const RECORD_SIZE  = 16;

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
$fp = fopen($tmpfile, 'w');
for ($i = 0; $i < RECORDS; $i++) {
    fwrite($fp, sprintf("%015d\n", $i));
}
fclose($fp);

$handle = fopen($tmpfile, 'r');

$reader = function () use ($handle) {
    $data = '';
    while (!feof($handle)) {
        $chunk = fread($handle, 4096);
        if ($chunk === false) {
            return false;
        }
        $data .= $chunk;
    }
    return $data;
};

[$results, $exceptions] = await_all([spawn($reader), spawn($reader)]);

$records = [];
$bytes   = 0;
foreach ($results as $data) {
    if ($data === false) {
        echo "fread() failed\n";
        continue;
    }
    $bytes += strlen($data);
    foreach (str_split($data, RECORD_SIZE) as $record) {
        $records[] = $record;
    }
}

printf("bytes: %d of %d\n", $bytes, RECORDS * RECORD_SIZE);
printf("records: %d, unique: %d\n", count($records), count(array_unique($records)));
echo "Exceptions: " . count($exceptions) . "\n";

fclose($handle);
unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
bytes: 32000 of 32000
records: 2000, unique: 2000
Exceptions: 0
End
