--TEST--
Pool: exit() in a resource destructor while the pool is freed at script end
--EXTENSIONS--
true_async
--FILE--
<?php

// Freeing the pool destroys its idle resources, which runs user code. Run
// from the engine's object free at shutdown, exit() there bailed out of it
// and left the pool unfreed (a leak under ASan or valgrind).
$pool = new Async\Pool(
    factory: function () {
        static $n = 0;
        return ++$n;
    },
    destructor: function ($resource) {
        echo "destructor $resource\n";
        exit(3);
    },
    min: 2,
    max: 2,
);

echo "end\n";
?>
--EXPECT--
end
destructor 1
