--TEST--
Scope SuperGlobals - multiple superglobals isolation
--FILE--
<?php

use function Async\await;

$scope = new \Async\Scope(inheritSuperglobals: false);
$coro = $scope->spawn(function() {
    $_POST['post_key'] = 'post_value';
    $_COOKIE['cookie_key'] = 'cookie_value';

    var_dump($_POST);
    var_dump($_COOKIE);
});
await($coro);

var_dump($_POST ?? null);
var_dump($_COOKIE ?? null);

?>
--EXPECT--
array(1) {
  ["post_key"]=>
  string(10) "post_value"
}
array(1) {
  ["cookie_key"]=>
  string(12) "cookie_value"
}
NULL
NULL
