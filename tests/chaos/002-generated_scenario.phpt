--TEST--
Chaos: auto-generated scenario, predicted multiset matches actual output
--DESCRIPTION--
Builds a scenario from a seeded generator (CHAOS_GEN_SEED env, default 1),
predicts its output multiset analytically, runs the scenario, and asserts
that actual output equals the prediction as a sorted multiset.

Vary scheduler interleaving with TRUE_ASYNC_SCHED — output multiset must
remain identical because the scenario uses no shared state.
--FILE--
<?php
require_once __DIR__ . '/_harness/Scenario.php';

use Async\Chaos\Generator;

$genSeed = (int)(getenv('CHAOS_GEN_SEED') ?: 1);

$gen = new Generator($genSeed);
$scenario = $gen->printOnly();

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
