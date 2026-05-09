--TEST--
Chaos: 1-sender + 1-receiver Channel pair, predictable multiset
--DESCRIPTION--
One Channel, one sender coroutine sending N values, one receiver recv()ing N times.
Output multiset is deterministic regardless of scheduler interleaving:
{ "sent:ch:0".."sent:ch:N-1", "recv:ch:0".."recv:ch:N-1" }.

Vary scheduler with TRUE_ASYNC_SCHED, vary buffer capacity / message count
via env CHAOS_CH_CAP and CHAOS_CH_MSGS.
--FILE--
<?php
require_once __DIR__ . '/_harness/Scenario.php';

use Async\Chaos\Generator;

$genSeed = (int)(getenv('CHAOS_GEN_SEED') ?: 1);
$cap     = (int)(getenv('CHAOS_CH_CAP')   ?: 0);
$msgs    = (int)(getenv('CHAOS_CH_MSGS')  ?: 5);

$gen = new Generator($genSeed);
$scenario = $gen->singlePairChannel($cap, $msgs);

$predicted = $scenario->predictedOutput();
$actual    = $scenario->run();

if ($predicted === $actual) {
    echo "PASS\n";
} else {
    echo "FAIL\n";
    echo "predicted: " . json_encode($predicted) . "\n";
    echo "actual:    " . json_encode($actual) . "\n";
}
?>
--EXPECT--
PASS
