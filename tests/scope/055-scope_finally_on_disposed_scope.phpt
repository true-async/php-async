--TEST--
Scope: finally() invoked on a scope whose internal scope has already been released runs the callable immediately
--FILE--
<?php

use Async\Scope;

// Covers scope.c:542-555 — METHOD(finally) scope==NULL branch that calls
// the supplied callable synchronously when the scope has already been disposed.

class Holder {
    public function __construct(public Scope $s) {}
    public function __destruct() {
        echo "destruct calls finally\n";
        $this->s->finally(function (Scope $x) {
            echo "late finally fired\n";
        });
        echo "destruct returned\n";
    }
}

function setup(): Holder {
    $scope = new Scope();
    $holder = new Holder($scope);
    // Create a cycle so GC is involved; also register a finally on the scope
    // that references the holder to ensure the cycle is real.
    $scope->finally(function () use ($holder) {});
    return $holder;
}

$h = setup();
gc_collect_cycles();
unset($h);

echo "end\n";

?>
--EXPECT--
end
destruct calls finally
late finally fired
destruct returned
