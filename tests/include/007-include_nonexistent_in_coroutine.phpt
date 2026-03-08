--TEST--
include nonexistent file inside coroutine - warning handling
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$c1 = spawn(function() {
    $result = @include __DIR__ . '/nonexistent_file.inc';
    var_dump($result);
    echo "coroutine continues\n";
});

await($c1);

echo "done\n";
?>
--EXPECT--
bool(false)
coroutine continues
done
