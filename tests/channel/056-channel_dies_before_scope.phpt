--TEST--
Channel: channel ZVAL released before scope ends — free_obj must del_callback safely
--FILE--
<?php

use Async\Channel;
use Async\Scope;
use function Async\spawn;
use function Async\delay;
use function Async\await;

$scope = new Scope();
$marker = "alive";

// Create lots of channels inside the scope; let each one go out of scope so
// free_obj runs while the scope is still alive. Tests del_callback path.
$scope->spawn(function () {
    for ($i = 0; $i < 50; $i++) {
        $local = new Channel(0, 0, 0);
        // $local goes out of scope at end of iteration → free_obj
    }
});

// Scope continues to live, then we explicitly dispose it. If the per-channel
// callback wasn't properly removed in free_obj, scope's notify would call
// freed memory here and crash.
spawn(function () use ($scope) { delay(30); $scope->dispose(); });

await(spawn(function () { delay(80); }));

echo "marker=", $marker, "\n";
echo "ok\n";
?>
--EXPECT--
marker=alive
ok
