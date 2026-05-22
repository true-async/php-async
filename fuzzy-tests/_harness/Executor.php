<?php
/**
 * Feature executor.
 *
 * Iterates Scenarios (or Outline × Examples), constructs a fresh Context per
 * scenario, dispatches each step against the StepRegistry. Then-steps are
 * invariant assertions: a handler must throw on violation. The executor
 * collects failures as text and reports a single PASS / FAIL line at the end.
 *
 * Phases:
 *   Given — set up entities (channels, scopes, planned coroutine actions)
 *   When  — record per-coroutine actions (NOT spawn yet)
 *   Then  — assertions
 *
 * Coroutines are actually spawned inside execute(): when transitioning from
 * When to Then we call $ctx->run() which spawns every planned coroutine,
 * runs await_all, and exposes counters/results.
 */

namespace Async\Chaos;

require_once __DIR__ . '/Gherkin.php';
require_once __DIR__ . '/StepRegistry.php';
require_once __DIR__ . '/Context.php';

use Async\Chaos\GherkinFeature;
use Async\Chaos\GherkinScenario;
use Async\Chaos\GherkinStep;

final class Executor {
    public function __construct(
        private readonly StepRegistry $registry,
        private readonly int          $genSeed,
    ) {}

    /** Run every Scenario; return [pass_count, fail_count, fail_reports[]]. */
    public function run(GherkinFeature $feature): array {
        $pass = 0;
        $fails = [];
        foreach ($feature->scenarios as $scenario) {
            if ($scenario->isOutline) {
                foreach ($scenario->examples as $row) {
                    [$ok, $msg] = $this->runOne($scenario, $row);
                    if ($ok) { $pass++; }
                    else     { $fails[] = "{$scenario->name} [" . self::rowSummary($row) . "]: $msg"; }
                }
            } else {
                [$ok, $msg] = $this->runOne($scenario, []);
                if ($ok) { $pass++; }
                else     { $fails[] = "{$scenario->name}: $msg"; }
            }
        }
        return [$pass, count($fails), $fails];
    }

    private function runOne(GherkinScenario $scenario, array $row): array {
        $ctx = new Context($this->genSeed);
        $phase = 'given';
        try {
            // Flatten any mutation blocks. runScenario() pre-flattens with the
            // chosen selection; here (whole-feature runs) the default applies.
            $steps = Gherkin::flatten($scenario->steps);
            foreach ($steps as $step) {
                $effective = $row ? $step->substitute($row) : $step;

                if ($effective->keyword === 'when' && $phase === 'given') {
                    $phase = 'when';
                }
                if ($effective->keyword === 'then' && $phase !== 'then') {
                    $ctx->run();   // spawn + await_all + close channels
                    $phase = 'then';
                }

                $this->dispatch($ctx, $effective);
            }
            // Edge case: scenario with no Then — run anyway so leaks are detected
            if ($phase !== 'then') {
                $ctx->run();
            }
            return [true, ''];
        } catch (\Throwable $e) {
            // On failure, attach the low-level chaos event log (EvilPeer
            // toxic sequences, client I/O traces) so the exact sequence that
            // produced the failure is visible without a re-run.
            $msg = $e->getMessage();
            if ($ctx->events) {
                $msg .= "\n   chaos-log:";
                foreach ($ctx->events as $ev) {
                    $msg .= "\n     · " . $ev;
                }
            }
            return [false, $msg];
        }
    }

    private function dispatch(Context $ctx, GherkinStep $step): void {
        $hit = $this->registry->match($step->text);
        if ($hit === null) {
            throw new \RuntimeException("No step definition matches: '{$step->text}' (line {$step->line})");
        }
        [$def, $caps] = $hit;
        ($def->handler)($ctx, ...$caps);
    }

    private static function rowSummary(array $row): string {
        $parts = [];
        foreach ($row as $k => $v) { $parts[] = "$k=$v"; }
        return implode(' ', $parts);
    }
}
