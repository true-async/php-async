--TEST--
An EH_THROW window opened by another coroutine does not repaint our warnings
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

/* PDO::__construct replaces the error handling mode for the duration of the
 * connect (zend_replace_error_handling(EH_THROW, pdo_exception_ce)), and the
 * connect parks the coroutine. While it sleeps, a warning raised anywhere else
 * must stay a warning: if the window were global, the engine would turn it into
 * a PDOException carrying someone else's message. 10.255.255.1 is unroutable, so
 * the connect stays parked until it times out. */
$connector = spawn(function () {
    try {
        new PDO('mysql:host=10.255.255.1;port=3306;dbname=x', 'u', 'p', [PDO::ATTR_TIMEOUT => 3]);
    } catch (Throwable $e) {
        return 'connect ended';
    }

    return 'connect ended';
});

$victim = spawn(function () {
    delay(50);

    try {
        $decoded = @gzdecode('');
        return 'suppressed, got ' . var_export($decoded, true);
    } catch (Throwable $e) {
        return 'leaked ' . $e::class . ': ' . $e->getMessage();
    }
});

echo await($victim), "\n";
echo await($connector), "\n";
echo "Done\n";
?>
--EXPECT--
suppressed, got false
connect ended
Done
