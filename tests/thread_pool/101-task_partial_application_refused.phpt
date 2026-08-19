--TEST--
ThreadPool: a partial application in a constant expression is refused, in a default and in an attribute
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;

// A partial application stays an unevaluated tree that names a function
// resolved against the thread that compiled it, and caches that thread's
// zend_function pointer beside the name. A constant expression reaches an
// op_array through two tables the worker would have to replay: the literals,
// where a parameter default lands, and the attributes.
function tp_target($a, $b) { return "$a-$b"; }

#[Attribute]
class TpMark { public function __construct(public $cb = null) {} }

spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    $cases = [
        'default' => static function($cb = tp_target(?, 'z')) { return $cb('q'); },
        'default in an array' => static function($cb = [tp_target(?, 'z')]) { return $cb[0]('q'); },
        'attribute' => #[TpMark(tp_target(?, 'z'))] static function() { return 'ran'; },
        'nested default' => static function() {
            $g = static function($cb = tp_target(?, 'z')) { return $cb('q'); };
            return $g();
        },
        'plain closure' => static function() { return 'ran'; },
    ];

    foreach ($cases as $name => $closure) {
        try {
            $pool->submit($closure);
            echo $name, ": accepted\n";
        } catch (\Throwable $e) {
            echo $name, ": ", get_class($e), "\n";
        }
    }

    $pool->close();
});

?>
--EXPECT--
default: Async\ThreadPoolException
default in an array: Async\ThreadPoolException
attribute: Async\ThreadPoolException
nested default: Async\ThreadPoolException
plain closure: accepted
