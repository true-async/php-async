<?php
/**
 * Generate one .phpt per Scenario / Examples-row from every .feature file in
 * fuzzy-tests/.
 *
 * Output goes to fuzzy-tests/_generated/. The directory is wiped on every run
 * so stale files from removed scenarios don't linger.
 *
 * Usage:
 *   php fuzzy-tests/_harness/generate.php
 */

namespace Async\Chaos;

require_once __DIR__ . '/Gherkin.php';
require_once __DIR__ . '/Context.php';
require_once __DIR__ . '/StepRegistry.php';
require_once __DIR__ . '/Steps.php';

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

/** Max mutation variants emitted per scenario (override: `# @chaos-max N`). */
const CHAOS_EMIT_DEFAULT = 20;
/** Largest combination space we enumerate in memory before random drawing. */
const CHAOS_ENUM_CAP = 500;

/**
 * Selection alternatives for one mutation group.
 *   'one' → list of ints  (each is the chosen alternative index)
 *   'any' → list of int[] (each is a chosen subset, the full power set)
 */
function groupSelections(\Async\Chaos\GherkinMutationGroup $g): array {
    $k = count($g->alternatives);
    if ($g->mode === 'one') {
        return range(0, $k - 1);
    }
    $subsets = [];
    for ($mask = 0; $mask < (1 << $k); $mask++) {
        $s = [];
        for ($b = 0; $b < $k; $b++) {
            if ($mask & (1 << $b)) {
                $s[] = $b;
            }
        }
        $subsets[] = $s;
    }
    return $subsets;
}

/**
 * The mutation selections to emit for a scenario. Returns [null] when the
 * scenario has no mutation blocks. Otherwise: enumerate the full cartesian
 * product when small and sample it down to $emitMax; when the space is huge,
 * draw $emitMax distinct random selections directly. Deterministic in $seed.
 *
 * @param \Async\Chaos\GherkinMutationGroup[] $groups
 * @return array<?array<int,int|int[]>>
 */
function mutationVariants(array $groups, int $emitMax, int $seed): array {
    if (!$groups) {
        return [null];
    }
    $perGroup = array_map(static fn($g) => groupSelections($g), $groups);
    $total = 1;
    foreach ($perGroup as $sels) {
        $total *= count($sels);
    }
    mt_srand($seed);

    if ($total <= CHAOS_ENUM_CAP) {
        $combos = [[]];
        foreach ($perGroup as $sels) {
            $next = [];
            foreach ($combos as $c) {
                foreach ($sels as $s) {
                    $cc = $c;
                    $cc[] = $s;
                    $next[] = $cc;
                }
            }
            $combos = $next;
        }
        if (count($combos) > $emitMax) {
            shuffle($combos);   // deterministic: mt_srand() seeded above
            $combos = array_slice($combos, 0, $emitMax);
        }
        return $combos;
    }
    // Space too large to materialise — draw distinct random selections.
    $seen = [];
    $combos = [];
    $budget = $emitMax * 50;
    while (count($combos) < $emitMax && $budget-- > 0) {
        $c = [];
        foreach ($perGroup as $sels) {
            $c[] = $sels[mt_rand(0, count($sels) - 1)];
        }
        $key = json_encode($c);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $combos[] = $c;
    }
    return $combos;
}

/** Compact, filename-safe slug for one mutation selection. */
function mutationSlug(?array $combo): string {
    if ($combo === null) {
        return '';
    }
    $parts = [];
    foreach ($combo as $g => $sel) {
        if (is_array($sel)) {
            $parts[] = 'g' . $g . ($sel === [] ? 'none' : 'a' . implode('a', $sel));
        } else {
            $parts[] = 'g' . $g . 'o' . $sel;
        }
    }
    return implode('_', $parts);
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function ensureCleanOutDir(string $dir): void {
    rrmdir($dir);
    mkdir($dir, 0755, true);
}

function findFeatures(string $root): array {
    $found = [];
    $stack = [$root];
    while ($stack) {
        $dir = array_pop($stack);
        $base = basename($dir);
        if ($base === '_harness' || $base === '_generated') continue;
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $stack[] = $path;
            } elseif (str_ends_with($entry, '.feature')) {
                $found[] = $path;
            }
        }
    }
    sort($found);
    return $found;
}

/**
 * Map of requirement tags → PHP snippets that decide whether to skip.
 * Each snippet runs in --SKIPIF--; if any prints "skip ...", the test
 * is skipped on that platform.
 */
const SKIP_RULES = [
    'unix-sockets' => 'if (PHP_OS_FAMILY === "Windows") { echo "skip unix-domain sockets not supported"; exit; }',
    'tcp'          => '/* TCP loopback is portable; no skip */',
    'sockets'      => 'if (!function_exists("socket_import_stream")) { echo "skip ext/sockets required"; exit; }',
    'curl'         => 'if (!extension_loaded("curl")) { echo "skip ext/curl required"; exit; }',
    'fork'         => 'if (!function_exists("pcntl_fork")) { echo "skip fork() not available"; exit; }',
    'tty'          => 'if (PHP_OS_FAMILY === "Windows") { echo "skip TTY semantics differ on Windows"; exit; }',
    'zts'          => 'if (!ZEND_THREAD_SAFE) { echo "skip requires Thread-Safe (ZTS) PHP build"; exit; }',
    // Toxiproxy is opt-in: the test runs only where a Toxiproxy admin
    // endpoint actually answers, and skips everywhere else (dev machines,
    // per-PR CI). The probe is a plain TCP connect to the admin port.
    'toxiproxy'    => <<<'PROBE'
$tp = getenv("CHAOS_TOXIPROXY") ?: "127.0.0.1:8474";
$cp = strrpos($tp, ":");
$th = $cp === false ? $tp : substr($tp, 0, $cp);
$tport = $cp === false ? 8474 : (int)substr($tp, $cp + 1);
$ts = @stream_socket_client("tcp://$th:$tport", $te, $tm, 2);
if ($ts === false) { echo "skip Toxiproxy not running at $tp (set CHAOS_TOXIPROXY)"; exit; }
fclose($ts);
PROBE,
];

function buildSkipIfBlock(array $requires): string {
    if (!$requires) {
        return '';
    }
    $lines = ['<?php'];
    foreach ($requires as $tag) {
        $rule = SKIP_RULES[$tag] ?? null;
        if ($rule === null || str_starts_with($rule, '/*')) {
            continue;
        }
        $lines[] = $rule;
    }
    if (count($lines) === 1) {
        return ''; // only no-op rules collected
    }
    $lines[] = '?>';
    return "--SKIPIF--\n" . implode("\n", $lines) . "\n";
}

function emitPhpt(
    string  $path,
    string  $featureRel,
    string  $featureName,
    string  $scenarioName,
    ?array  $row,
    ?array  $mutationSelection,
    string  $featureAbs,
    string  $relativeHarness,
    array   $requires = []
): void {
    $rowComment = '';
    $rowArg = 'null';
    if ($row !== null) {
        $rowComment = ' (Examples: ' . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($row), $row)) . ')';
        $rowArg = var_export($row, true);
    }
    $mutComment = '';
    $mutArg = 'null';
    if ($mutationSelection !== null) {
        $bits = [];
        foreach ($mutationSelection as $g => $sel) {
            $bits[] = "g$g=" . (is_array($sel) ? '{' . implode(',', $sel) . '}' : $sel);
        }
        $mutComment = ' (Mutation: ' . implode(', ', $bits) . ')';
        $mutArg = var_export($mutationSelection, true);
    }
    $title = $featureName . ' :: ' . $scenarioName . $rowComment . $mutComment;
    $titleEscaped = str_replace("\n", ' ', $title);
    $featureExport  = var_export($featureAbs, true);
    $scenarioExport = var_export($scenarioName, true);

    $skipIf = buildSkipIfBlock($requires);

    $content = <<<PHPT
--TEST--
{$titleEscaped}
--DESCRIPTION--
Auto-generated from {$featureRel}.
DO NOT EDIT — regenerate via fuzzy-tests/regen.sh.
{$skipIf}--FILE--
<?php
require_once __DIR__ . '/{$relativeHarness}/Runner.php';
\\Async\\Chaos\\Runner::runScenario(
    {$featureExport},
    {$scenarioExport},
    {$rowArg},
    {$mutArg}
);
?>
--EXPECT--
PASS

PHPT;

    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $content);
}

/** Match a flat list of steps against the registry, union the requires tags. */
function collectStepsRequires(\Async\Chaos\StepRegistry $registry, array $steps): array {
    $tags = [];
    foreach ($steps as $step) {
        $hit = $registry->match($step->text);
        if ($hit === null) {
            continue; // Runner reports unmatched steps at run time
        }
        [$def, ] = $hit;
        foreach ($def->requires as $t) {
            if (!in_array($t, $tags, true)) {
                $tags[] = $t;
            }
        }
    }
    return $tags;
}

function main(): int {
    ensureCleanOutDir(OUT_DIR);

    /* Build the step registry once so we can inspect per-scenario
     * platform requirements and emit --SKIPIF-- blocks accordingly. */
    $registry = new StepRegistry();
    StandardSteps::register($registry);

    $features = findFeatures(FUZZY_DIR);
    if (!$features) {
        fwrite(STDERR, "[generate] no .feature files found under " . FUZZY_DIR . "\n");
        return 0;
    }

    $fuzzyDirReal = realpath(FUZZY_DIR);
    $written = 0;
    foreach ($features as $featureAbs) {
        $featureBase = basename($featureAbs, '.feature');
        $relFromFuzzy = ltrim(substr(realpath($featureAbs), strlen($fuzzyDirReal)), '/');
        $featureRel = 'fuzzy-tests/' . $relFromFuzzy;
        $subdir     = dirname($relFromFuzzy);   // "channel" or "." for root features
        // From _generated/<subdir>/foo.phpt to fuzzy-tests/_harness/.
        // root (subdir='.'):  _generated/foo.phpt → ../_harness
        // one level (channel): _generated/channel/foo.phpt → ../../_harness
        $relativeHarness = $subdir === '.'
            ? '../_harness'
            : str_repeat('../', substr_count($subdir, '/') + 2) . '_harness';

        $source = file_get_contents($featureAbs);
        try {
            $feature = Gherkin::parse($source);
        } catch (\Throwable $e) {
            fwrite(STDERR, "[generate] $featureRel: parse error: " . $e->getMessage() . "\n");
            continue;
        }

        $outSubdir = OUT_DIR . ($subdir === '.' ? '' : '/' . $subdir);

        foreach ($feature->scenarios as $idx => $scenario) {
            $scenarioSlug = slug($scenario->name);
            $groups   = $scenario->mutationGroups();
            $emitMax  = $feature->chaosMax ?? CHAOS_EMIT_DEFAULT;
            $seed     = crc32($featureRel . '::' . $scenario->name);
            $variants = mutationVariants($groups, $emitMax, $seed);   // [null] if none
            $rows     = $scenario->isOutline ? $scenario->examples : [null];

            foreach ($variants as $combo) {
                // SKIPIF reflects exactly the steps this variant runs.
                $flatSteps = \Async\Chaos\Gherkin::flatten($scenario->steps, $combo);
                $requires  = collectStepsRequires($registry, $flatSteps);
                $mutSlug   = mutationSlug($combo);

                foreach ($rows as $row) {
                    $suffix = [];
                    if ($mutSlug !== '')  { $suffix[] = $mutSlug; }
                    if ($row !== null)    { $suffix[] = rowSlug($row); }
                    $name = $suffix === []
                        ? sprintf('%s__%02d_%s.phpt', $featureBase, $idx, $scenarioSlug)
                        : sprintf('%s__%02d_%s__%s.phpt',
                            $featureBase, $idx, $scenarioSlug, implode('_', $suffix));
                    emitPhpt(
                        $outSubdir . '/' . $name,
                        $featureRel, $feature->name, $scenario->name, $row, $combo,
                        $featureAbs, $relativeHarness, $requires
                    );
                    $written++;
                }
            }
        }
    }

    echo "[generate] wrote $written .phpt files to " . OUT_DIR . "\n";
    return 0;
}

exit(main());
