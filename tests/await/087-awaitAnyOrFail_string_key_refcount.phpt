--TEST--
await_any_or_fail() - string keys must have correct refcount
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_any_or_fail;

function test(): void
{
    $key = str_repeat("b", 30);
    $coroutines = [$key => spawn(fn() => "result")];
    $result = await_any_or_fail($coroutines);

    if ($result !== "result") {
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
