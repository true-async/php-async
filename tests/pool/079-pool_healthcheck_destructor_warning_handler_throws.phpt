--TEST--
Pool: an error handler that throws on the destructor warning does not stop the healthcheck
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\delay;

set_error_handler(function (int $no, string $message) {
    echo "handler: $message\n";
    throw new ErrorException($message);
});

$checked = [];

$pool = new Pool(
    factory: function () {
        static $n = 0;
        return ++$n;
    },
    destructor: function ($r) {
        throw new RuntimeException("close failed on #$r");
    },
    healthcheck: function ($r) use (&$checked) {
        $checked[] = $r;
        return false;
    },
    min: 2,
    max: 2,
    healthcheckInterval: 20,
);

$other = spawn(function () {
    delay(60);
    return "unrelated coroutine finished";
});

delay(40);
sort($checked);
echo "checked: ", implode(",", array_unique($checked)), "; count ", $pool->count(), "\n";
echo await($other), "\n";
echo "done\n";
?>
--EXPECT--
handler: Pool: resource destructor threw RuntimeException: close failed on #1
handler: Pool: resource destructor threw RuntimeException: close failed on #2
checked: 1,2; count 0
unrelated coroutine finished
done
