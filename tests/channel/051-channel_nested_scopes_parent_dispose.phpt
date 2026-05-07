--TEST--
Channel: parent scope disposed cascades to child — both channels close
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use Async\Scope;
use function Async\spawn;
use function Async\delay;
use function Async\await;

$parent = new Scope();
$child  = Scope::inherit($parent);

$results = [];

$parent->spawn(function () use (&$results) {
    $ch = new Channel(0, 0, 0);
    try {
        $ch->recv();
    } catch (ChannelException $e) {
        $results['parent'] = $e->reason->name;
    }
});

$child->spawn(function () use (&$results) {
    $ch = new Channel(0, 0, 0);
    try {
        $ch->recv();
    } catch (ChannelException $e) {
        $results['child'] = $e->reason->name;
    }
});

spawn(function () use ($parent) { delay(40); $parent->dispose(); });

await(spawn(function () { delay(150); }));

ksort($results);
foreach ($results as $k => $v) echo "$k=$v\n";
?>
--EXPECT--
child=SCOPE_DISPOSED
parent=SCOPE_DISPOSED
