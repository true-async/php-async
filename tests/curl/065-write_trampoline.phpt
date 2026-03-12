--TEST--
Async curl: CURLOPT_WRITEFUNCTION with __call trampoline in coroutine
--EXTENSIONS--
curl
--FILE--
<?php

use function Async\spawn;

class TrampolineTest {
    public function __call(string $name, array $arguments) {
        echo 'Trampoline for ', $name, PHP_EOL;
        return 0;
    }
}

spawn(function () {
    $o = new TrampolineTest();
    $callback = [$o, 'trampoline'];

    $log_file = tempnam(sys_get_temp_dir(), 'php-curl-trampoline-write');

    $fp = fopen($log_file, 'w+');
    fwrite($fp, "test");
    fclose($fp);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $callback);
    curl_setopt($ch, CURLOPT_URL, 'file://' . $log_file);
    curl_exec($ch);

    @unlink($log_file);
});
?>
--EXPECT--
Trampoline for trampoline
