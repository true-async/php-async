--TEST--
Channel: many channels share an owner scope — scope dispose closes them all
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use Async\Scope;
use function Async\spawn;
use function Async\delay;
use function Async\await;

$scope = new Scope();
$reasons = [];

$scope->spawn(function () use (&$reasons) {
    $channels = [];
    for ($i = 0; $i < 20; $i++) {
        $channels[$i] = new Channel(0, 0, 0);
    }
    $count = 0;
    for ($i = 0; $i < 20; $i++) {
        spawn(function () use ($channels, $i, &$reasons, &$count) {
            try {
                $channels[$i]->recv();
            } catch (ChannelException $e) {
                $reasons[$i] = $e->reason->name;
                $count++;
            }
        });
    }
    delay(200); // wait until scope dispose hits us
});

spawn(function () use ($scope) { delay(40); $scope->dispose(); });

await(spawn(function () { delay(250); }));

$all_disposed = (count($reasons) === 20);
foreach ($reasons as $r) {
    if ($r !== 'SCOPE_DISPOSED') { $all_disposed = false; break; }
}
echo "count=", count($reasons), "\n";
echo "all_scope_disposed=", $all_disposed ? "yes" : "no", "\n";
?>
--EXPECT--
count=20
all_scope_disposed=yes
