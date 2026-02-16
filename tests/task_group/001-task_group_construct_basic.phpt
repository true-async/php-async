--TEST--
TaskGroup: __construct() - basic creation without arguments
--FILE--
<?php

use Async\TaskGroup;

$group = new TaskGroup();

var_dump($group instanceof TaskGroup);
var_dump($group->count());
var_dump($group->isFinished());
var_dump($group->isSealed());

echo "done\n";
?>
--EXPECT--
bool(true)
int(0)
bool(true)
bool(false)
done
