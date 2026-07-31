--TEST--
An EH_THROW window opened while we were parked does not follow us on resume
--EXTENSIONS--
pdo_mysql
--SKIPIF--
<?php
if (!function_exists('Async\spawn')) die("skip TrueAsync runtime not available\n");
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;

/* Companion to 016. There the victim is created while the window is already
 * open, so what protects it is starting outside one. Here it is parked long
 * before the window exists and wakes up in the middle of it, so what protects
 * it is the switch carrying error_handling per fiber. */
$victim = spawn(function () {
    delay(10);
    delay(100);

    $decoded = @gzdecode('');

    return 'suppressed, got ' . var_export($decoded, true);
});

$connector = spawn(function () {
    delay(30);

    try {
        new PDO('mysql:host=10.255.255.1;port=3306;dbname=x', 'u', 'p', [PDO::ATTR_TIMEOUT => 3]);
    } catch (Throwable $e) {
        return 'connect ended';
    }

    return 'connect ended';
});

try {
    echo await($victim), "\n";
} catch (Throwable $e) {
    echo 'leaked ', $e::class, ': ', $e->getMessage(), "\n";
}

echo await($connector), "\n";
echo "Done\n";
?>
--EXPECT--
suppressed, got false
connect ended
Done
