--TEST--
Coroutines reading compressed and stored entries of one phar at once
--SKIPIF--
<?php
if (!extension_loaded('phar')) die('skip phar extension required');
if (!extension_loaded('zlib')) die('skip zlib extension required');
?>
--INI--
phar.readonly=0
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "Start\n";

/* Every entry of an archive is decompressed into one stream shared by the
 * archive, at the offset the entry remembers before it starts writing. The
 * bodies differ per entry, so bytes taken from another entry's span show up as
 * a mismatch rather than as a shorter read. */
$archive = sys_get_temp_dir() . '/async_phar_' . getmypid() . '.phar';
@unlink($archive);

$bodies = [];
for ($i = 0; $i < 4; $i++) {
    $bodies["file$i.txt"] = str_repeat("entry-$i-", 40000);
}

$phar = new Phar($archive);
foreach ($bodies as $name => $body) {
    $phar[$name] = $body;
}
$phar->compressFiles(Phar::GZ);
unset($phar);

$readers = [];
foreach ($bodies as $name => $body) {
    $readers[] = spawn(function () use ($archive, $name, $body) {
        $read = @file_get_contents('phar://' . $archive . '/' . $name);
        if ($read === $body) {
            return "$name intact";
        }

        return "$name broken: " . strlen((string) $read) . " bytes of " . strlen($body);
    });
}

[$results, $errors] = await_all($readers);

foreach ($results as $result) {
    echo $result, "\n";
}

foreach ($errors as $error) {
    echo 'error: ', $error->getMessage(), "\n";
}

@unlink($archive);

/* A stored entry is read through the archive stream directly, while a
 * compressed one is read through the decompression above: the two paths take
 * the archive stream in the same order, or one of them waits for a side the
 * other will not release. */
$mixed = sys_get_temp_dir() . '/async_phar_mixed_' . getmypid() . '.phar';
@unlink($mixed);

$stored = str_repeat('stored-', 60000);
$packed = str_repeat('packed-', 60000);

$phar = new Phar($mixed);
$phar['stored.txt'] = $stored;
$phar['packed.txt'] = $packed;
$phar['packed.txt']->compress(Phar::GZ);
unset($phar);

$handle = fopen('phar://' . $mixed . '/stored.txt', 'rb');

[$results, $errors] = await_all([
    spawn(fn() => @file_get_contents('phar://' . $mixed . '/packed.txt') === $packed
        ? 'packed.txt intact' : 'packed.txt broken'),
    spawn(function () use ($handle, $stored) {
        fseek($handle, 120000);

        return fread($handle, 1024) === substr($stored, 120000, 1024)
            ? 'stored.txt intact' : 'stored.txt broken';
    }),
]);

foreach ($results as $result) {
    echo $result, "\n";
}

foreach ($errors as $error) {
    echo 'error: ', $error->getMessage(), "\n";
}

fclose($handle);
@unlink($mixed);

echo "End\n";
?>
--EXPECT--
Start
file0.txt intact
file1.txt intact
file2.txt intact
file3.txt intact
packed.txt intact
stored.txt intact
End
