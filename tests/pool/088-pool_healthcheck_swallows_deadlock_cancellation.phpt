--TEST--
Pool: a healthcheck that catches the deadlock's cancellation does not keep the process running
--FILE--
<?php

use Async\Pool;
use Async\Channel;
use function Async\delay;

$never = new Channel();
$checking = false;

$pool = new Pool(
    factory: fn () => 1,
    healthcheck: function ($r) use (&$checking) {
        $checking = true;
        $blocked = new Channel();

        try {
            $blocked->recv();
        } catch (\Throwable $e) {
            echo "check caught ", get_class($e), "\n";
        }

        return true;
    },
    min: 1,
    max: 1,
    healthcheckInterval: 10,
);

while (!$checking) {
    delay(1);
}

$never->recv();
?>
--EXPECTF--
%Acheck caught %s
%AFatal error: Uncaught Async\DeadlockError: %A
