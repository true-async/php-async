--TEST--
Scope SuperGlobals - nested scope inheritance
--FILE--
<?php

use function Async\await;

$parent = new \Async\Scope(inheritSuperglobals: false);
$coro_parent = $parent->spawn(function() {
    $_GET['parent_key'] = 'parent_value';

    $child = \Async\Scope::inherit();
    $coro_child = $child->spawn(function() {
        var_dump($_GET);

        $_GET['child_key'] = 'child_value';
        var_dump($_GET);
    });
    await($coro_child);

    var_dump($_GET);
});
await($coro_parent);

?>
--EXPECT--
array(1) {
  ["parent_key"]=>
  string(12) "parent_value"
}
array(2) {
  ["parent_key"]=>
  string(12) "parent_value"
  ["child_key"]=>
  string(11) "child_value"
}
array(1) {
  ["parent_key"]=>
  string(12) "parent_value"
}
