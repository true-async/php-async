--TEST--
current_context() - values written before the first coroutine survive and are visible
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\current_context;

// Written at top level, before anything has spawned: there is no scope to anchor a context to
// until the scheduler launches, so this used to write into a detached context and vanish.
current_context()->set('early', 'E');

echo "same object: ", var_export(current_context() === current_context(), true), "\n";
echo "early, from top level: ", var_export(current_context()->find('early'), true), "\n";
echo "early, from coroutine: ", var_export(await(spawn(fn() => current_context()->find('early'))), true), "\n";

current_context()->set('late', 'L');
echo "late, from coroutine:  ", var_export(await(spawn(fn() => current_context()->find('late'))), true), "\n";

// The duplicate-key guard only bites on a context that is actually kept.
try {
    current_context()->set('early', 'other');
} catch (Async\AsyncException $exception) {
    echo "duplicate set: ", $exception->getMessage(), "\n";
}

echo "early is unchanged: ", var_export(current_context()->find('early'), true), "\n";
?>
--EXPECT--
same object: true
early, from top level: 'E'
early, from coroutine: 'E'
late, from coroutine:  'L'
duplicate set: Context key already exists and replace is false
early is unchanged: 'E'
