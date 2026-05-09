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
