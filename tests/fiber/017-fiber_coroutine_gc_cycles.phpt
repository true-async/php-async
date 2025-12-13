--TEST--
Fiber and Coroutine GC with circular references
--FILE--
<?php

use function Async\spawn;
use function Async\await;

class Node {
    public $fiber;
    public $ref;
    public $data;

    public function __construct() {
        $this->data = str_repeat("x", 1000);
    }
}

$c = spawn(function() {
    $node1 = new Node();
    $node2 = new Node();

    // Create circular reference
    $node1->ref = $node2;
    $node2->ref = $node1;

    // Store in fiber
    $node1->fiber = new Fiber(function() use ($node1, $node2) {
        echo "F-start\n";
        Fiber::suspend($node2);
        echo "F-resume\n";
        return $node1;
    });

    $result = $node1->fiber->start();
    echo "Got: " . ($result === $node2 ? "node2" : "other") . "\n";

    $result = $node1->fiber->resume();
    echo "Got: " . ($result === $node1 ? "node1" : "other") . "\n";

    // Break references and trigger GC
    $node1 = null;
    $node2 = null;
});

await($c);
gc_collect_cycles();

echo "OK\n";
?>
--EXPECT--
F-start
Got: node2
F-resume
Got: node1
OK
