<?php
/**
 * Generate one .phpt per Scenario / Examples-row from every .feature file in
 * fuzzy_tests/.
 *
 * Output goes to fuzzy_tests/_generated/. The directory is wiped on every run
 * so stale files from removed scenarios don't linger.
 *
 * Usage:
 *   php fuzzy_tests/_harness/generate.php
 */

namespace Async\Chaos;

require_once __DIR__ . '/Gherkin.php';

const FUZZY_DIR = __DIR__ . '/..';
const OUT_DIR   = __DIR__ . '/../_generated';

function slug(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim($s, '_');
    return $s !== '' ? $s : 'unnamed';
}

function rowSlug(array $row): string {
    $parts = [];
    foreach ($row as $k => $v) {
        $parts[] = slug($k) . slug((string)$v);
    }
    return implode('_', $parts);
}

function ensureCleanOutDir(string $dir): void {
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.phpt') as $f) { @unlink($f); }
    } else {
        mkdir($dir, 0755, true);
    }
}

function emitPhpt(string $path, string $featureRel, string $featureName, string $scenarioName, ?array $row, string $featureAbs): void {
    $rowComment = '';
    $rowArg = 'null';
    if ($row !== null) {
        $rowComment = ' (Examples: ' . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($row), $row)) . ')';
        $rowArg = var_export($row, true);
    }
    $title = $featureName . ' :: ' . $scenarioName . $rowComment;
    $titleEscaped = str_replace("\n", ' ', $title);
    $featureExport  = var_export($featureAbs, true);
    $scenarioExport = var_export($scenarioName, true);

    $content = <<<PHPT
--TEST--
{$titleEscaped}
--DESCRIPTION--
Auto-generated from {$featureRel}.
DO NOT EDIT — regenerate via fuzzy_tests/regen.sh.
--FILE--
<?php
require_once __DIR__ . '/../_harness/Runner.php';
\\Async\\Chaos\\Runner::runScenario(
    {$featureExport},
    {$scenarioExport},
    {$rowArg}
);
?>
--EXPECT--
PASS

PHPT;

    file_put_contents($path, $content);
}

function main(): int {
    ensureCleanOutDir(OUT_DIR);

    $features = glob(FUZZY_DIR . '/*.feature');
    if (!$features) {
        fwrite(STDERR, "[generate] no .feature files found in " . FUZZY_DIR . "\n");
        return 0;
    }

    $written = 0;
    foreach ($features as $featureAbs) {
        $featureBase = basename($featureAbs, '.feature');
        $featureRel  = 'fuzzy_tests/' . basename($featureAbs);
        $source = file_get_contents($featureAbs);
        try {
            $feature = Gherkin::parse($source);
        } catch (\Throwable $e) {
            fwrite(STDERR, "[generate] $featureRel: parse error: " . $e->getMessage() . "\n");
            continue;
        }

        foreach ($feature->scenarios as $idx => $scenario) {
            $scenarioSlug = slug($scenario->name);
            if ($scenario->isOutline) {
                foreach ($scenario->examples as $rowIdx => $row) {
                    $rs = rowSlug($row);
                    $name = sprintf('%s__%02d_%s__%s.phpt', $featureBase, $idx, $scenarioSlug, $rs);
                    emitPhpt(OUT_DIR . '/' . $name, $featureRel, $feature->name, $scenario->name, $row, $featureAbs);
                    $written++;
                }
            } else {
                $name = sprintf('%s__%02d_%s.phpt', $featureBase, $idx, $scenarioSlug);
                emitPhpt(OUT_DIR . '/' . $name, $featureRel, $feature->name, $scenario->name, null, $featureAbs);
                $written++;
            }
        }
    }

    echo "[generate] wrote $written .phpt files to " . OUT_DIR . "\n";
    return 0;
}

exit(main());
