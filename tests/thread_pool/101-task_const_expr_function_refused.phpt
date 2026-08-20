--TEST--
ThreadPool: a function written into a constant expression is refused on transfer
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;

// A first-class callable and a partial application stay unevaluated trees that
// name a function resolved against the thread that compiled them, and cache
// that thread's zend_function pointer beside the name. A closure written the
// same way is an op_array the tree points at rather than owns, and that
// op_array stays in the snapshot arena, so a task storing one crashed the
// worker as soon as the arena was reused. A constant expression reaches an
// op_array through three tables the worker would have to replay: the literals,
// where a parameter default lands, the static variables, and the attributes.
//
// The reason is asserted, not only the exception class: the same class comes
// out of the illegal-declaration branch, and a closure carrying a declaration
// inside a constant expression takes both routes.
function tp_target($a, $b) { return "$a-$b"; }

#[Attribute]
class TpMark { public function __construct(public $cb = null) {} }

spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    $cases = [
        'first-class callable' => static function($cb = tp_target(...)) { return $cb('q', 'z'); },
        'partial application' => static function($cb = tp_target(?, 'z')) { return $cb('q'); },
        'partial in an array' => static function($cb = [tp_target(?, 'z')]) { return $cb[0]('q'); },
        'attribute argument' => #[TpMark(tp_target(?, 'z'))] static function() { return 'ran'; },
        'nested default' => static function() {
            $g = static function($cb = tp_target(?, 'z')) { return $cb('q'); };
            return $g();
        },
        'closure' => static function($f = static function() { return 'inner'; }) { return $f(); },
        'closure in an attribute' => #[TpMark(static function() { return 'inner'; })] static function() { return 'ran'; },
        'plain closure' => static function() { return 'ran'; },
    ];

    foreach ($cases as $name => $closure) {
        try {
            $pool->submit($closure);
            echo $name, ": accepted\n";
        } catch (\Throwable $e) {
            $reason = $e->getPrevious()?->getMessage() ?? $e->getMessage();
            echo $name, ": ", get_class($e), ": ", strstr($reason, ' at ', true) ?: $reason, "\n";
        }
    }

    $pool->close();
});

?>
--EXPECT--
first-class callable: Async\ThreadPoolException: Cannot transfer closure to another thread: first-class callable in a constant expression
partial application: Async\ThreadPoolException: Cannot transfer closure to another thread: first-class callable in a constant expression
partial in an array: Async\ThreadPoolException: Cannot transfer closure to another thread: first-class callable in a constant expression
attribute argument: Async\ThreadPoolException: Cannot transfer closure to another thread: first-class callable in a constant expression
nested default: Async\ThreadPoolException: Cannot transfer closure to another thread: first-class callable in a constant expression
closure: Async\ThreadPoolException: Cannot transfer closure to another thread: closure in a constant expression
closure in an attribute: Async\ThreadPoolException: Cannot transfer closure to another thread: closure in a constant expression
plain closure: accepted
