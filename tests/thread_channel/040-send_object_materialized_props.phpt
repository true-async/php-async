--TEST--
ThreadChannel: send object whose property table was materialized (var_dump/get_object_vars/foreach/cast)
--DESCRIPTION--
Regression: the cross-thread transfer guard rejected objects whose lazy
properties HashTable had been materialized (e.g. by var_dump), because declared
properties stored as IS_INDIRECT slots were miscounted as dynamic properties.
Only genuine (non-indirect) dynamic properties must be rejected.
--FILE--
<?php

use Async\ThreadChannel;

class Point {
    public int $x = 1;
    public int $y = 2;
    public string $label = "p";
    public ?Point $next = null;
}

function materialize_var_dump(object $o): void { ob_start(); var_dump($o); ob_end_clean(); }
function materialize_get_vars(object $o): void { get_object_vars($o); }
function materialize_foreach(object $o): void { foreach ($o as $_) {} }
function materialize_cast(object $o): void { (array) $o; }

$ch = new ThreadChannel(16);

// Each declared-only object must transfer regardless of how its property
// table was materialized.
foreach (['plain' => null, 'var_dump' => 'materialize_var_dump',
          'get_object_vars' => 'materialize_get_vars', 'foreach' => 'materialize_foreach',
          '(array) cast' => 'materialize_cast'] as $how => $fn) {
    $o = new Point();
    $o->x = 10;
    $o->label = $how;
    if ($fn !== null) { $fn($o); }
    $ch->send($o);
    $r = $ch->recv();
    echo "$how: x={$r->x} y={$r->y} label={$r->label}\n";
}

// Nested object property survives materialization + transfer.
$head = new Point();
$head->label = "head";
$head->next = new Point();
$head->next->label = "tail";
materialize_var_dump($head);
$ch->send($head);
$r = $ch->recv();
echo "nested: {$r->label} -> {$r->next->label}\n";

// unset() a declared property leaves an IS_INDIRECT slot pointing at UNDEF in
// the materialized table; that must not be mistaken for a dynamic property.
$u = new Point();
unset($u->label);
materialize_var_dump($u);
$ch->send($u);
$r = $ch->recv();
echo "unset: sent_ok x={$r->x}\n";

// Empty stdClass has no dynamic properties: transfers fine.
$ch->send(new stdClass());
echo "stdClass empty: " . get_class($ch->recv()) . "\n";

// A genuine dynamic property must still be rejected...
$d = new Point();
@($d->dyn = 99);
try { $ch->send($d); echo "FAIL: dynamic accepted\n"; }
catch (\Throwable $e) { echo "dynamic: " . $e->getMessage() . "\n"; }

// ...even after the table was materialized (declared IS_INDIRECT + dynamic mixed).
$d2 = new Point();
@($d2->dyn = 1);
materialize_var_dump($d2);
try { $ch->send($d2); echo "FAIL: dynamic-after-dump accepted\n"; }
catch (\Throwable $e) { echo "dynamic-after-dump: " . $e->getMessage() . "\n"; }

// stdClass with properties is all-dynamic: rejected.
$s = new stdClass();
$s->a = 1;
try { $ch->send($s); echo "FAIL: stdClass prop accepted\n"; }
catch (\Throwable $e) { echo "stdClass prop: " . $e->getMessage() . "\n"; }

echo "Done\n";
?>
--EXPECT--
plain: x=10 y=2 label=plain
var_dump: x=10 y=2 label=var_dump
get_object_vars: x=10 y=2 label=get_object_vars
foreach: x=10 y=2 label=foreach
(array) cast: x=10 y=2 label=(array) cast
nested: head -> tail
unset: sent_ok x=1
stdClass empty: stdClass
dynamic: Cannot transfer object with dynamic properties between threads
dynamic-after-dump: Cannot transfer object with dynamic properties between threads
stdClass prop: Cannot transfer object with dynamic properties between threads
Done
