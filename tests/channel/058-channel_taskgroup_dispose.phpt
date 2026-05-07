--TEST--
Channel: TaskGroup dispose closes channels owned by its scope
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use Async\TaskGroup;
use function Async\spawn;
use function Async\delay;
use function Async\await;

$group = new TaskGroup(concurrency: 4);
$ch = null;
$reasons = [];

// Channel created inside a TaskGroup task — its owner scope is the group's scope.
$group->spawn(function () use (&$ch) {
    $ch = new Channel(0, 0, 0);
    delay(300);
});

// Several other tasks inside the same group blocking on it.
for ($i = 0; $i < 4; $i++) {
    $group->spawn(function () use (&$ch, &$reasons, $i) {
        // Wait until $ch exists.
        while ($ch === null) { delay(5); }
        try {
            $ch->recv();
            $reasons[$i] = "FAIL";
        } catch (ChannelException $e) {
            $reasons[$i] = $e->reason->name;
        } catch (\Throwable $e) {
            $reasons[$i] = "OTHER:" . get_class($e);
        }
    });
}

// Dispose the group while tasks are blocked.
spawn(function () use ($group) { delay(40); $group->dispose(); });

await(spawn(function () { delay(200); }));

ksort($reasons);
foreach ($reasons as $i => $r) echo "$i=$r\n";
echo "ch_closed=", $ch->isClosed() ? "true" : "false", "\n";
?>
--EXPECT--
0=SCOPE_DISPOSED
1=SCOPE_DISPOSED
2=SCOPE_DISPOSED
3=SCOPE_DISPOSED
ch_closed=true
