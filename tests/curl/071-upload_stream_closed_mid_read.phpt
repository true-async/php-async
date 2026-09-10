--TEST--
Async curl: closing the upload stream under a parked read leaves the IO alive
--EXTENSIONS--
curl
--SKIPIF--
<?php
/* Below libcurl 8.11.1 the upload read is synchronous: curl_exec pushes the whole
 * body before the closing coroutine gets a turn, so there is no parked read to
 * close under. The subscription this test covers is not built there either -- it
 * lives behind the same version guard in ext/curl/curl_async.c. */
if (curl_version()['version_number'] < 0x080B01) {
    die('skip libcurl 8.11.1 or later is needed for a parked upload read');
}
?>
--FILE--
<?php

use function Async\delay;
use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

include __DIR__ . '/../../../../ext/curl/tests/server.inc';
$host = curl_cli_server_start();

echo "Start\n";

$tempname = tempnam(sys_get_temp_dir(), 'CURL_DATA');
file_put_contents($tempname, str_repeat('upload-', 60000));

$handle = fopen($tempname, 'rb');

$ch = curl_init($host . '/get.inc?test=input');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_READDATA, $handle);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Expect:', 'Content-Length: 300000']);
/* The responder echoes the body back; nothing here needs it. */
curl_setopt($ch, CURLOPT_WRITEFUNCTION, static fn ($resource, $data) => strlen($data));

/* The throttle spreads the upload over several seconds, so the close below falls
 * between two of curl's reads. With no read in flight the reactor holds no pin of
 * its own on the stream's IO, which is the state the subscription has to survive
 * on its own. */
curl_setopt($ch, CURLOPT_MAX_SEND_SPEED_LARGE, 65536);

$uploading = false;
curl_setopt($ch, CURLOPT_NOPROGRESS, false);
curl_setopt($ch, CURLOPT_PROGRESSFUNCTION,
    static function ($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use (&$uploading) {
        if ($uploaded > 0) {
            $uploading = true;
        }

        return 0;
    });

[$results, $errors] = await_all([
    spawn(static function () use ($ch) {
        curl_exec($ch);

        return 'transfer: ' . (curl_errno($ch) === 0 ? 'completed' : 'aborted');
    }),
    spawn(static function () use ($handle, &$uploading) {
        /* Ordered by an observable rather than by a count of turns, and bounded so
         * that a build whose progress callback never reports says so instead of
         * hanging. */
        $deadline = microtime(true) + 5.0;
        while (!$uploading && microtime(true) < $deadline) {
            suspend();
        }

        if (!$uploading) {
            echo "the upload never reported a byte\n";
        }

        delay(150);
        fclose($handle);

        return 'stream closed';
    }),
]);

foreach ($results as $result) {
    echo $result, "\n";
}

foreach ($errors as $error) {
    echo 'error: ', $error->getMessage(), "\n";
}

@unlink($tempname);

echo "End\n";
?>
--EXPECTF--
Start

Warning: fclose(): CURLOPT_INFILE resource has gone away, resetting to default in %s on line %d
transfer: aborted
stream closed
End
