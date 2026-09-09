--TEST--
Async curl: an upload read ignores another coroutine's completion on the same handle
--EXTENSIONS--
curl
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

include __DIR__ . '/../../../../ext/curl/tests/server.inc';
$host = curl_cli_server_start();

echo "Start\n";

$tempname = tempnam(sys_get_temp_dir(), 'CURL_DATA');
file_put_contents($tempname, str_repeat('upload-', 150000));

/* The handle curl uploads from is written by another coroutine, so every write
 * completes on the event curl's read is parked on. What reaches the server is
 * whatever the two coroutines left in the file, hence the responder that
 * answers with the method alone. */
$handle = fopen($tempname, 'r+b');

$ch = curl_init($host . '/get.inc?test=method');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_READDATA, $handle);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Expect:', 'Content-Length: ' . filesize($tempname)]);

[$results, $errors] = await_all([
    spawn(function () use ($ch) {
        $response = curl_exec($ch);

        return 'response: ' . (is_string($response) ? $response : 'error #' . curl_errno($ch));
    }),
    spawn(function () use ($handle) {
        $chunk = str_repeat('w', 8192);
        for ($i = 0; $i < 100; $i++) {
            @fwrite($handle, $chunk);
        }

        return 'writes done';
    }),
]);

foreach ($results as $result) {
    echo $result, "\n";
}

foreach ($errors as $error) {
    echo 'error: ', $error->getMessage(), "\n";
}

fclose($handle);
@unlink($tempname);

echo "End\n";
?>
--EXPECT--
Start
response: POST
writes done
End
