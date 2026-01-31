--TEST--
Future::failed() - location tracking
--FILE--
<?php

use Async\Future;

$future = Future::failed(new Exception("error")); $line = __LINE__;

$createdFileAndLine = $future->getCreatedFileAndLine();
$completedFileAndLine = $future->getCompletedFileAndLine();

var_dump(str_contains($createdFileAndLine[0], basename(__FILE__)));
var_dump($createdFileAndLine[1] === $line);
var_dump(str_contains($completedFileAndLine[0], basename(__FILE__)));
var_dump($completedFileAndLine[1] === $line);

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
