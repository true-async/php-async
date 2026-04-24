--TEST--
TaskGroup: queueLimit default is 2 × concurrency; explicit 0 = unbounded
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\await;

$driver = spawn(function() {
    /* Case 1: unbounded concurrency ⇒ no queuing ever (all spawn immediate). */
    $g1 = new TaskGroup();
    $done = 0;
    for ($i = 0; $i < 10; $i++) {
        $g1->spawn(function() use (&$done) { $done++; });
    }
    $g1->seal();
    await($g1->all());
    echo "unbounded: done=$done\n";

    /* Case 2: explicit queueLimit=0 with concurrency>0 means unbounded queue
     * (legacy). All 5 spawns return immediately even though only 1 can run. */
    $g2 = new TaskGroup(concurrency: 1, queueLimit: 0);
    $trace = [];
    for ($i = 1; $i <= 5; $i++) {
        $g2->spawn(function() use ($i, &$trace) { $trace[] = "task$i"; });
        $trace[] = "after$i";
    }
    $g2->seal();
    await($g2->all());
    echo "explicit_0: " . implode(",", array_slice($trace, 0, 10)) . "\n";

    /* Case 3: negative queueLimit rejected. */
    try {
        new TaskGroup(concurrency: 1, queueLimit: -1);
        echo "negative: FAIL (no exception)\n";
    } catch (\ValueError $e) {
        echo "negative: OK (" . $e->getMessage() . ")\n";
    }
});

await($driver);
?>
--EXPECT--
unbounded: done=10
explicit_0: after1,after2,after3,after4,after5,task1,task2,task3,task4,task5
negative: OK (Async\TaskGroup::__construct(): Argument #2 ($queueLimit) must be greater than or equal to 0)
