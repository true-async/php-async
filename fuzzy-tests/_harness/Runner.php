<?php
/**
 * One-shot helper used from .phpt files: load a .feature, run it, print PASS/FAIL.
 */

namespace Async\Chaos;

require_once __DIR__ . '/Gherkin.php';
require_once __DIR__ . '/StepRegistry.php';
require_once __DIR__ . '/Context.php';
require_once __DIR__ . '/Steps.php';
require_once __DIR__ . '/Executor.php';

final class Runner {
    /**
     * Run a single scenario from a feature, optionally with a specific Examples
     * row and a specific mutation-block selection.
     *
     * @param string                $featureFile  absolute path to .feature
     * @param string                $scenarioName exact Scenario name (Outline name without row data)
     * @param array<string,string>|null $row     when provided, scenario is treated as Outline with one row
     * @param array<int,int|int[]>|null $mutationSelection  per-group choice:
     *        int for a "One of:" block, int[] for an "Any of:" block
     */
    public static function runScenario(string $featureFile, string $scenarioName, ?array $row = null, ?array $mutationSelection = null, ?int $genSeed = null): void {
        $genSeed ??= (int)(getenv('CHAOS_GEN_SEED') ?: 1);

        $source = @file_get_contents($featureFile);
        if ($source === false) {
            echo "FAIL\nfeature file not readable: $featureFile\n";
            return;
        }

        try {
            $feature = Gherkin::parse($source);
        } catch (\Throwable $e) {
            echo "FAIL\nparse error: " . $e->getMessage() . "\n";
            return;
        }

        $picked = null;
        foreach ($feature->scenarios as $s) {
            if ($s->name === $scenarioName) { $picked = $s; break; }
        }
        if ($picked === null) {
            echo "FAIL\nscenario not found: $scenarioName\n";
            return;
        }

        // Build a synthetic feature with just the chosen scenario, with any
        // mutation blocks flattened down to the selected alternatives.
        $synth = new GherkinFeature($feature->name);
        $synthScenario = new GherkinScenario($picked->name);
        $synthScenario->steps = Gherkin::flatten($picked->steps, $mutationSelection);
        if ($row !== null) {
            $synthScenario->isOutline = true;
            $synthScenario->examples = [$row];
        }
        $synth->scenarios[] = $synthScenario;

        $registry = StandardSteps::register(new StepRegistry());
        $executor = new Executor($registry, $genSeed);
        [$pass, $fail, $reports] = $executor->run($synth);
        if ($fail === 0) {
            echo "PASS\n";
        } else {
            echo "FAIL\n";
            foreach ($reports as $r) {
                echo " - $r\n";
            }
        }
    }

    public static function runFeature(string $featureFile, ?int $genSeed = null): void {
        $genSeed ??= (int)(getenv('CHAOS_GEN_SEED') ?: 1);

        $source = file_get_contents($featureFile);
        if ($source === false) {
            echo "FAIL\nfeature file not readable: $featureFile\n";
            return;
        }

        try {
            $feature = Gherkin::parse($source);
        } catch (\Throwable $e) {
            echo "FAIL\nparse error: " . $e->getMessage() . "\n";
            return;
        }

        $registry = StandardSteps::register(new StepRegistry());
        $executor = new Executor($registry, $genSeed);

        [$pass, $fail, $reports] = $executor->run($feature);
        if ($fail === 0) {
            echo "PASS\n";
        } else {
            echo "FAIL\npassed: $pass; failed: $fail\n";
            foreach ($reports as $r) {
                echo " - $r\n";
            }
        }
    }
}
