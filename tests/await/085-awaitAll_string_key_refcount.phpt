--TEST--
await_all() - string keys must have correct refcount
--DESCRIPTION--
When await_all() receives an array with string keys, the returned
$results and $errors arrays reuse those keys. Each hash table must
properly addref the key string. Otherwise i_free_compiled_variables
triggers a heap-use-after-free when multiple CVs share the same
zend_string key pointer.
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_all;

function test(): void
{
    $key = str_repeat("x", 30);
    $coroutines = [$key => spawn(fn() => "result")];
    [$results, $errors] = await_all($coroutines);

    if ($results[$key] !== "result") {
        echo "FAIL: unexpected result\n";
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
