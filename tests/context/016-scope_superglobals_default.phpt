--TEST--
Scope SuperGlobals - default behavior is inherit=true
--FILE--
<?php

use function Async\await;

$_GET['global_key'] = 'global_value';

$scope = new \Async\Scope(); // Default should be inherit=true
var_dump($scope->isInheritSuperglobals());

$coro = $scope->spawn(function() {
    var_dump($_GET);
});
await($coro);

?>
--EXPECT--
bool(true)
array(1) {
  ["global_key"]=>
  string(12) "global_value"
}
