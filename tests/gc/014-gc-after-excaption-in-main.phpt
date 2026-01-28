--TEST--
Correct GC behavior when the main coroutine is destroyed due to an exception.
--FILE--
<?php

register_shutdown_function(function () {
    printf("Shutdown\n");
});

class Cycle {
    public static $counter = 0;
    public $self;
    public function __construct() {
        $this->self = $this;
    }
    public function __destruct() {
        $id = self::$counter++;
        printf("%d: Start destruct\n", $id);
        printf("%d: End destruct\n", $id);
    }
}

$f = new Fiber(function () {
    new Cycle();
    new Cycle();
    gc_collect_cycles();
});

$f->start();

new Cycle();
new Cycle();
gc_collect_cycles();

throw new Exception("Trigger GC");

?>
--EXPECTF--
0: Start destruct
0: End destruct
1: Start destruct
1: End destruct
2: Start destruct
2: End destruct
3: Start destruct
3: End destruct

Fatal error: Uncaught Exception: Trigger GC in %s:%d
Stack trace:
#0 {main}
  thrown in %s on line %d
Shutdown