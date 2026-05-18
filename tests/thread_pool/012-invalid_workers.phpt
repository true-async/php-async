--TEST--
ThreadPool: workers=0 → auto-detect; negative → ValueError
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;

// workers=0 (and the default) is auto — should equal available_parallelism().
$auto = Async\available_parallelism();
$p = new ThreadPool(0);
echo "Auto matches available_parallelism: ", ($p->getWorkerCount() === $auto ? "yes" : "no"), "\n";
$p->close();

try {
    new ThreadPool(-1);
} catch (\ValueError $e) {
    echo "Negative: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECTF--
Auto matches available_parallelism: yes
Negative: %smust be between 0 and %d
Done
