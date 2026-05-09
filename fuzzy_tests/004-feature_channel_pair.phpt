--TEST--
Chaos: Gherkin feature — channel send/recv pair invariants
--FILE--
<?php
require_once __DIR__ . '/_harness/Runner.php';
\Async\Chaos\Runner::runFeature(__DIR__ . '/channel_pair.feature');
?>
--EXPECT--
PASS
