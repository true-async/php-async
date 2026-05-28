--TEST--
Scope: allowZombies() - basic usage and default flag inversion
--FILE--
<?php

use Async\Scope;

// new Scope() is now Not-Safe by default; allowZombies() flips it to Safe
// and returns $this so the call can be chained.
$scope = new Scope();
$same = $scope->allowZombies();

var_dump($same === $scope);
var_dump($same instanceof Scope);

// asNotSafely() and allowZombies() are inverses; chaining keeps identity.
$scope2 = new Scope();
$result = $scope2->allowZombies()->asNotSafely();
var_dump($result === $scope2);

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
