--TEST--
Scope SuperGlobals inheritance from global
--FILE--
<?php

use function Async\await;

$_GET['global_key'] = 'global_value';

$scope = new \Async\Scope(inheritSuperglobals: true);
$coro = $scope->spawn(function() {
    var_dump($_GET);
});
await($coro);

?>
--EXPECT--
array(1) {
  ["global_key"]=>
  string(12) "global_value"
}
