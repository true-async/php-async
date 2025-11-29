--TEST--
Scope SuperGlobals isolation - no inheritance from global
--FILE--
<?php

use function Async\await;

$scope = new \Async\Scope(inheritSuperglobals: false);
$coro = $scope->spawn(function() {
    $_GET['key'] = 'scope_value';
    var_dump($_GET);
});
await($coro);

// Global $_GET should be unaffected
var_dump($_GET ?? null);

?>
--EXPECT--
array(1) {
  ["key"]=>
  string(11) "scope_value"
}
NULL
