--TEST--
Async curl: CURLOPT_HEADERFUNCTION with __call trampoline in coroutine
--EXTENSIONS--
curl
--FILE--
<?php

use function Async\spawn;

spawn(function () {
    $o = new class {
        public function __call(string $name, array $arguments) {
            echo 'Trampoline for ', $name, PHP_EOL;
            return strlen($arguments[1]);
        }
    };
    $callback = [$o, 'trampoline'];

    $log_file = tempnam(sys_get_temp_dir(), 'php-curl-trampoline-header');

    $fp = fopen($log_file, 'w+');
    fwrite($fp, "test");
    fclose($fp);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, $callback);
    curl_setopt($ch, CURLOPT_URL, 'file://' . $log_file);
    curl_exec($ch);

    @unlink($log_file);
});
?>
--EXPECT--
Trampoline for trampoline
