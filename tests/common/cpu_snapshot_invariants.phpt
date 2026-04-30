--TEST--
Async\CpuSnapshot: final, readonly, private __construct, no dynamic properties
--FILE--
<?php

$ref = new ReflectionClass(Async\CpuSnapshot::class);
var_dump($ref->isFinal());

// __construct is private — `new` from outside must fail.
try {
    new Async\CpuSnapshot();
    echo "BAD: new succeeded\n";
} catch (\Error $e) {
    echo "new blocked\n";
}

$s = Async\CpuSnapshot::now();

// Properties are readonly.
foreach (['wallNs', 'processUserNs', 'processSystemNs',
          'systemIdleNs', 'systemBusyNs', 'cpuCount'] as $prop) {
    try {
        $s->{$prop} = 0;
        echo "BAD: write to $prop succeeded\n";
    } catch (\Error $e) {
        echo "readonly: $prop\n";
    }
}

// No dynamic properties.
try {
    $s->extra = 1;
    echo "BAD: dyn prop succeeded\n";
} catch (\Error $e) {
    echo "no dyn props\n";
}

// now() rejects extra args.
try {
    Async\CpuSnapshot::now(1);
} catch (\ArgumentCountError $e) {
    echo "now arity: ok\n";
}

echo "done\n";
?>
--EXPECT--
bool(true)
new blocked
readonly: wallNs
readonly: processUserNs
readonly: processSystemNs
readonly: systemIdleNs
readonly: systemBusyNs
readonly: cpuCount
no dyn props
now arity: ok
done
