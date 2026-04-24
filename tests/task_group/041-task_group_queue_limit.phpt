--TEST--
TaskGroup: queueLimit — spawn() suspends when pending queue is full (backpressure)
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\await;

/* concurrency=1, queueLimit=2:
 *   - 1 task can run at a time
 *   - up to 2 tasks can be pending
 *   - the 4th spawn() must suspend until a slot frees
 */
$driver = spawn(function() {
    $group = new TaskGroup(concurrency: 1, queueLimit: 2);
    $log = [];

    for ($i = 1; $i <= 4; $i++) {
        $log[] = "before_spawn_$i";
        $group->spawn(function() use ($i, &$log) {
            $log[] = "task_{$i}_start";
            \Async\delay(10);
            $log[] = "task_{$i}_done";
        });
        $log[] = "after_spawn_$i";
    }
    $group->seal();
    await($group->all());

    /* Invariants:
     *   - spawns 1–3 return immediately (queue not yet full at push time).
     *   - spawn 4 is forced to suspend; it must not complete before task 1 finishes
     *     (task 1 must run-and-finish to free the first queue slot).
     */
    $after_spawn_1 = array_search("after_spawn_1", $log, true);
    $after_spawn_3 = array_search("after_spawn_3", $log, true);
    $after_spawn_4 = array_search("after_spawn_4", $log, true);
    $task_1_done   = array_search("task_1_done",   $log, true);

    echo ($after_spawn_1 !== false && $after_spawn_1 < $after_spawn_3) ? "spawn_1_3_immediate: OK\n" : "spawn_1_3_immediate: FAIL\n";
    echo ($after_spawn_4 > $task_1_done) ? "spawn_4_waits: OK\n" : "spawn_4_waits: FAIL\n";
    echo "total_tasks: " . count(array_filter($log, fn($l) => str_ends_with($l, "_done"))) . "\n";
});

await($driver);
?>
--EXPECT--
spawn_1_3_immediate: OK
spawn_4_waits: OK
total_tasks: 4
