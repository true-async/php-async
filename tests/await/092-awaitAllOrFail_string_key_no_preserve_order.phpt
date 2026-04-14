--TEST--
await_all_or_fail() - string keys refcount with preserve_key_order=false
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_all_or_fail;

function test(): void
{
    $key = str_repeat("g", 30);
    $coroutines = [$key => spawn(fn() => "result")];
    $results = await_all_or_fail($coroutines, null, false);

    if ($results[$key] !== "result") {
        echo "FAIL\n";
        return;
    }

    echo "ok\n";
}

for ($i = 0; $i < 10; $i++) {
    await(spawn(fn() => test()));
}

echo "done\n";
?>
--EXPECT--
ok
ok
ok
ok
ok
ok
ok
ok
ok
ok
done
