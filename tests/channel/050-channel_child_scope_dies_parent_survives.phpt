--TEST--
Channel: child scope dies but parent stays alive — only the child-owned channel closes
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use Async\Scope;
use function Async\spawn;
use function Async\delay;
use function Async\await;

$child = new Scope();

$child_ch = null;
$child_reason = null;

$child->spawn(function () use (&$child_ch, &$child_reason) {
    // Created inside child scope — owner = child scope.
    $child_ch = new Channel(0, 0, 0);
    try {
        $child_ch->recv();
        echo "FAIL: child recv returned\n";
    } catch (ChannelException $e) {
        $child_reason = $e->reason->name;
    }
});

// Parent-owned channel: created at top-level, owner = main_scope.
$parent_ch = new Channel(0, 0, 0);

// Dispose child only.
spawn(function () use ($child) { delay(40); $child->dispose(); });

await(spawn(function () { delay(120); }));

echo "child_reason=", $child_reason, "\n";
echo "child_closed=", $child_ch && $child_ch->isClosed() ? "true" : "false", "\n";
echo "parent_closed=", $parent_ch->isClosed() ? "true" : "false", "\n";

// Parent channel still usable after child scope died.
$parent_ch->close();
echo "parent_close_ok\n";
?>
--EXPECT--
child_reason=SCOPE_DISPOSED
child_closed=true
parent_closed=false
parent_close_ok
