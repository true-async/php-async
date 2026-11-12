--TEST--
Bailout: a fatal error disposes a live Async\Timeout (no leftover libuv timer)
--FILE--
<?php
/*
 * php_error_cb() marks every object as destructed before a fatal bails out,
 * so dtor_obj does not run for a Timeout that is alive at that moment. Its
 * timer must be closed from free_obj: an open handle fails uv_loop_close().
 *
 * A debug build prints one "leftover libuv handle" line per survivor on
 * stderr, after the fatal error. No trailing %A for that reason.
 */
$timeout = Async\timeout(5000);

eval('function async_timeout_dup() {}');
eval('function async_timeout_dup() {}');
?>
--EXPECTF--
Fatal error: Cannot redeclare function async_timeout_dup() (previously declared in %s) in %s on line %d
