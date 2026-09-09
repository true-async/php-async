--TEST--
Async curl: a failed upload read releases the request it was given
--EXTENSIONS--
curl
--SKIPIF--
<?php
/* The evidence is the leak report, which only a debug build prints. */
if (!ZEND_DEBUG_BUILD) die('skip debug build required');
if (PHP_OS_FAMILY === 'Windows') die('skip a file read is served synchronously there');
?>
--INI--
report_memory_leaks=1
--FILE--
<?php

include __DIR__ . '/../../../../ext/curl/tests/server.inc';
$host = curl_cli_server_start();

echo "Start\n";

$tempname = tempnam(sys_get_temp_dir(), 'CURL_DATA');

/* Opened for writing only, so every read of it fails. The failed request is
 * reported to curl with its own exception, and nobody but curl can release it;
 * a debug build prints what is left behind, which is what this test reads. */
$handle = fopen($tempname, 'wb');

$ch = curl_init($host . '/get.inc?test=method');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_READDATA, $handle);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Expect:', 'Content-Length: 200000']);

$response = curl_exec($ch);
echo 'upload: ', is_string($response) ? 'sent' : 'failed', "\n";

fclose($handle);
@unlink($tempname);

echo "End\n";
?>
--EXPECT--
Start
upload: failed
End
