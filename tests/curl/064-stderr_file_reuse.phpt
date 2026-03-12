--TEST--
Async curl: CURLOPT_STDERR file handle reuse after fclose + unlink
--DESCRIPTION--
Reproduces curl_setopt_basic002 failure: after setting CURLOPT_STDERR to a file,
fclose + unlink + re-fopen the same path. On Windows with async IO, the dup'd fd
from php_stdiop_cast(PHP_STREAM_AS_STDIO) may keep the file locked.
--EXTENSIONS--
curl
--FILE--
<?php

include __DIR__ . '/../../../../ext/curl/tests/server.inc';
$host = curl_cli_server_start();

$temp_file = tempnam(sys_get_temp_dir(), 'CURL_STDERR');

// First: open file, set as CURLOPT_STDERR, exec curl, close file, unlink
$handle = fopen($temp_file, 'w');

$ch = curl_init();
curl_setopt($ch, CURLOPT_VERBOSE, 1);
curl_setopt($ch, CURLOPT_STDERR, $handle);
$curl_content = curl_exec($ch);

fclose($handle);
unset($handle);

$content = file_get_contents($temp_file);
echo "First stderr captured: " . (strlen($content) > 0 ? "yes" : "no") . "\n";

$unlink_ok = @unlink($temp_file);
echo "Unlink succeeded: " . ($unlink_ok ? "yes" : "no") . "\n";

// Second: re-open same path, set new CURLOPT_STDERR, exec curl with URL
$handle = fopen($temp_file, 'w');
if ($handle === false) {
    echo "FAIL: cannot reopen temp file (Permission denied - file still locked)\n";
} else {
    echo "Reopen succeeded: yes\n";
    ob_start();
    curl_setopt($ch, CURLOPT_URL, "{$host}/");
    curl_setopt($ch, CURLOPT_STDERR, $handle);
    $data = curl_exec($ch);
    ob_end_clean();

    fclose($handle);
    unset($handle);

    $content = file_get_contents($temp_file);
    echo "Second stderr captured: " . (strlen($content) > 0 ? "yes" : "no") . "\n";
}

unset($ch);
@unlink($temp_file);
echo "Done\n";
?>
--EXPECTF--
First stderr captured: yes
Unlink succeeded: yes
Reopen succeeded: yes
Second stderr captured: yes
Done
