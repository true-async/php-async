--TEST--
await_first_success() - string keys must have correct refcount
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_first_success;

function test(): void
{
    $key = str_repeat("c", 30);
    $coroutines = [$key => spawn(fn() => "result")];
    [$result, $errors] = await_first_success($coroutines);

    if ($result !== "result") {
        echo "Expected 'result', got " . var_export($result, true) . "\n";
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
