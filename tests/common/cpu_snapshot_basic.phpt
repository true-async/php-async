--TEST--
Async\CpuSnapshot::now: returns snapshot with sane fields
--FILE--
<?php

$s = Async\CpuSnapshot::now();

var_dump($s instanceof Async\CpuSnapshot);

// All fields are integers.
var_dump(is_int($s->wallNs));
var_dump(is_int($s->processUserNs));
var_dump(is_int($s->processSystemNs));
var_dump(is_int($s->systemIdleNs));
var_dump(is_int($s->systemBusyNs));
var_dump(is_int($s->cpuCount));

// Counters are non-negative; cpuCount is at least 1.
var_dump($s->wallNs > 0);
var_dump($s->processUserNs >= 0);
var_dump($s->processSystemNs >= 0);
var_dump($s->systemIdleNs >= 0);
var_dump($s->systemBusyNs >= 0);
var_dump($s->cpuCount >= 1);

// Monotonic: a later snapshot has wallNs >= previous one.
$s2 = Async\CpuSnapshot::now();
var_dump($s2->wallNs >= $s->wallNs);

// processUserNs + processSystemNs only grows.
var_dump(($s2->processUserNs + $s2->processSystemNs)
      >= ($s->processUserNs + $s->processSystemNs));

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
done
