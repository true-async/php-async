--TEST--
await_any_of_or_fail() - string keys must have correct refcount
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_any_of_or_fail;

function test(): void
{
    $key = str_repeat("d", 30);
    $coroutines = [$key => spawn(fn() => "result")];
    $results = await_any_of_or_fail(1, $coroutines);

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
