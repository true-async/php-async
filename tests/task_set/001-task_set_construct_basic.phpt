--TEST--
TaskSet: __construct() - basic creation without arguments
--FILE--
<?php

use Async\TaskSet;

$set = new TaskSet();

var_dump($set instanceof TaskSet);
var_dump($set->count());
var_dump($set->isFinished());
var_dump($set->isSealed());

echo "done\n";
?>
--EXPECT--
bool(true)
int(0)
bool(true)
bool(false)
done
