--TEST--
Channel: TaskGroup cancel on tasks waiting on a channel
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use Async\TaskGroup;
use function Async\spawn;
use function Async\delay;
use function Async\await;

$group = new TaskGroup(concurrency: 4);
$results = [];

$group->spawn(function () use (&$results) {
    $ch = new Channel(0, 0, 0);
    spawn(function () use (&$ch, &$results) {
        try {
            $ch->recv();
            $results['recv'] = 'FAIL_RETURNED';
        } catch (\Throwable $e) {
            $results['recv'] = get_class($e) . ':' .
                (property_exists($e, 'reason') ? $e->reason->name : $e->getMessage());
        }
    });
    delay(200);
});

spawn(function () use ($group) { delay(40); $group->cancel(); });

await(spawn(function () { delay(200); }));

foreach ($results as $k => $v) echo "$k=$v\n";
echo "ok\n";
?>
--EXPECT--
recv=Async\ChannelException:SCOPE_DISPOSED
ok
