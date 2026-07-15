--TEST--
Future: map()/catch()/finally() on a temporary source resolve the derived Future (issue #193)
--FILE--
<?php

use function Async\await;

// The child futures live in source->child_futures, owned by the source PHP object. When the source
// is a one-liner temporary (or unset() right after), it used to be freed while its state was still
// pending, dropping the whole chain -- the derived Future never resolved and the script deadlocked.

// map()
$s = new Async\FutureState();
$f = (new Async\Future($s))->map(fn($v) => $v * 2);
$s->complete(21);
echo "map: ", $f->await(), "\n";

// catch()
$s = new Async\FutureState();
$f = (new Async\Future($s))->catch(fn($e) => "caught:" . $e->getMessage());
$s->error(new Exception("boom"));
echo "catch: ", $f->await(), "\n";

// finally()
$s = new Async\FutureState();
$ran = false;
$f = (new Async\Future($s))->finally(function () use (&$ran) { $ran = true; });
$s->complete(7);
echo "finally: ", $f->await(), " ran=", $ran ? "y" : "n", "\n";

// unset() the source right after deriving
$s = new Async\FutureState();
$base = new Async\Future($s);
$f = $base->map(fn($v) => $v + 1);
unset($base);
$s->complete(10);
echo "unset: ", $f->await(), "\n";

// chained on a temporary
$s = new Async\FutureState();
$f = (new Async\Future($s))->map(fn($v) => $v * 2)->map(fn($v) => $v + 1);
$s->complete(5);
echo "chain: ", $f->await(), "\n";

echo "done\n";
?>
--EXPECT--
map: 42
catch: caught:boom
finally: 7 ran=y
unset: 11
chain: 11
done
