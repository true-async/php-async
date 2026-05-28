--TEST--
Channel: 2 channels + 2 coroutine groups (separate scopes), one scope dies, the other continues
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use Async\Scope;
use function Async\spawn;
use function Async\delay;
use function Async\await;

$scope_a = (new Scope())->allowZombies();
$scope_b = (new Scope())->allowZombies();

$ch_a = null;
$ch_b = null;
$results = [];

// Group A: 3 receivers on $ch_a, owner = scope_a
$scope_a->spawn(function () use (&$ch_a, &$results) {
    $ch_a = new Channel(0, 0, 0);
    for ($i = 0; $i < 3; $i++) {
        spawn(function () use (&$ch_a, &$results, $i) {
            try {
                $ch_a->recv();
                $results["a$i"] = "ok";
            } catch (ChannelException $e) {
                $results["a$i"] = $e->reason->name;
            }
        });
    }
    delay(300);
});

// Group B: 1 producer + 1 receiver on $ch_b, owner = scope_b
$scope_b->spawn(function () use (&$ch_b, &$results) {
    $ch_b = new Channel(2, 0, 0);
    spawn(function () use (&$ch_b, &$results) {
        try {
            $results['b_recv'] = $ch_b->recv();
        } catch (ChannelException $e) {
            $results['b_recv'] = $e->reason->name;
        }
    });
    delay(60);
    $ch_b->send("from-b");
    delay(300);
});

// Kill only scope A.
spawn(function () use ($scope_a) { delay(40); $scope_a->dispose(); });

await(spawn(function () { delay(250); }));

ksort($results);
foreach ($results as $k => $v) echo "$k=$v\n";

echo "ch_a_closed=", $ch_a->isClosed() ? "true" : "false", "\n";
echo "ch_b_closed=", $ch_b->isClosed() ? "true" : "false", "\n";
?>
--EXPECT--
a0=SCOPE_DISPOSED
a1=SCOPE_DISPOSED
a2=SCOPE_DISPOSED
b_recv=from-b
ch_a_closed=true
ch_b_closed=false
