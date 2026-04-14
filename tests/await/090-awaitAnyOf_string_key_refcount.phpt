--TEST--
await_any_of() - string keys must have correct refcount
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_any_of;

function test(): void
{
    $key = str_repeat("e", 30);
    $coroutines = [$key => spawn(fn() => "result")];
    [$results, $errors] = await_any_of(1, $coroutines);

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
