--TEST--
Memory exhaustion in one coroutine ends the request while another coroutine is suspended
--SKIPIF--
<?php
$zend_mm_enabled = getenv("USE_ZEND_ALLOC");
if ($zend_mm_enabled === "0") {
    die("skip Zend MM disabled");
}
?>
--INI--
memory_limit=2M
--FILE--
<?php

use function Async\spawn;
use function Async\delay;

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

spawn(function() {
    echo "Coroutine A started\n";
    delay(50);
    echo "Coroutine A resumed after the memory error\n";
});

spawn(function() {
    delay(10);
    echo "Coroutine B allocates\n";
    $data = str_repeat('x', 10000000);
    echo "Coroutine B should not reach\n";
});

?>
--EXPECTF--
Coroutine A started
Coroutine B allocates

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
Shutdown function called
