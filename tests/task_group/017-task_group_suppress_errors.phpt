--TEST--
TaskGroup: suppressErrors() marks errors as handled
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { throw new \RuntimeException("err"); });

    $group->close();
    $group->all(ignoreErrors: true);

    $group->suppressErrors();

    echo "errors suppressed\n";
    echo "error count: " . count($group->getErrors()) . "\n";
    echo "done\n";
});
?>
--EXPECT--
errors suppressed
error count: 1
done
