<?php
/**
 * freeze.php — turn a failing chaos run into a permanent, deterministic .phpt.
 *
 * The chaos suite is a (program × value-fuzz × schedule) matrix: a generated
 * .phpt pins the program (feature / scenario / Examples row / mutation
 * selection), while the two fuzz seeds come from the environment —
 * CHAOS_GEN_SEED (value fuzz) and TRUE_ASYNC_SCHED (scheduler fuzz). A
 * failure is only reproducible if you replay the *same* pair.
 *
 * This tool reads a generated .phpt and emits a frozen copy under _frozen/
 * with both seeds pinned in an --ENV-- block, so the case reproduces on
 * every run with no environment setup — a turn-key regression test.
 *
 * Usage:
 *   php _harness/freeze.php <generated-or-frozen .phpt> [--sched=random:N]
 *                          [--gen=M] [--out=PATH]
 *
 * --sched / --gen default to the current TRUE_ASYNC_SCHED / CHAOS_GEN_SEED
 * environment, so the common path is simply:
 *
 *   TRUE_ASYNC_SCHED=random:42 CHAOS_GEN_SEED=7 \
 *       php _harness/freeze.php _generated/io/backpressure__03_*.phpt
 *
 * The frozen .phpt lives at _frozen/<topic>/<name>__sched-<slug>__gen-<M>.phpt
 * — the same harness-relative depth as _generated/<topic>/, so the file's
 * own require paths keep working. _frozen/ is committed (unlike _generated/).
 */

function fail(string $msg): never {
    fwrite(STDERR, "freeze: $msg\n");
    exit(1);
}

/** Split a .phpt into its --SECTION-- => body map (order not preserved). */
function parse_phpt(string $text): array {
    $sections = [];
    $current = null;
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^--([A-Z_]+)--\s*$/', $line, $m)) {
            $current = $m[1];
            $sections[$current] = '';
            continue;
        }
        if ($current !== null) {
            $sections[$current] .= $line . "\n";
        }
    }
    return array_map(static fn(string $b): string => rtrim($b, "\n"), $sections);
}

$argv = $_SERVER['argv'];
$src = null;
$sched = getenv('TRUE_ASYNC_SCHED') ?: 'fifo';
$gen   = getenv('CHAOS_GEN_SEED') ?: '1';
$out   = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--sched=')) {
        $sched = substr($arg, 8);
    } elseif (str_starts_with($arg, '--gen=')) {
        $gen = substr($arg, 6);
    } elseif (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
    } elseif (str_starts_with($arg, '--')) {
        fail("unknown option: $arg");
    } elseif ($src === null) {
        $src = $arg;
    } else {
        fail("unexpected argument: $arg");
    }
}

if ($src === null) {
    fail("missing source .phpt — see the header of this file for usage");
}
if (!is_file($src)) {
    fail("not a file: $src");
}
if (!ctype_digit((string) $gen)) {
    fail("--gen must be a non-negative integer, got: $gen");
}

$sections = parse_phpt((string) file_get_contents($src));
if (!isset($sections['FILE'])) {
    fail("source has no --FILE-- section: $src");
}

// The generated --FILE-- body bakes in a machine-absolute feature path.
// Rewrite it to a __DIR__-relative path so the frozen file is portable, and
// pick up the topic for the output location.
$body = $sections['FILE'];
$topic = null;
$body = preg_replace_callback(
    "#'[^']*/([A-Za-z0-9_]+)/([A-Za-z0-9_]+\\.feature)'#",
    static function (array $m) use (&$topic): string {
        $topic = $m[1];
        return "__DIR__ . '/../../{$m[1]}/{$m[2]}'";
    },
    $body,
    1,
    $count
);
if ($count !== 1 || $topic === null) {
    fail("could not locate the .feature path in the source --FILE-- body");
}

$schedSlug = preg_replace('/[^A-Za-z0-9]+/', '', $sched) ?: 'fifo';
$name = preg_replace('/\\.phpt$/', '', basename($src));

if ($out === null) {
    $out = __DIR__ . "/../_frozen/$topic/{$name}__sched-{$schedSlug}__gen-{$gen}.phpt";
}

$testName = trim($sections['TEST'] ?? $name);
$envLines = "CHAOS_GEN_SEED=$gen";
if ($sched !== '' && $sched !== 'fifo') {
    $envLines = "TRUE_ASYNC_SCHED=$sched\n" . $envLines;
}

$frozen = "--TEST--\n"
    . "frozen [sched=$sched gen=$gen]: $testName\n"
    . "--DESCRIPTION--\n"
    . "Frozen chaos case — a deterministic replay of one (program x value-fuzz\n"
    . "x schedule) point, with both fuzz seeds pinned in --ENV-- below. Do not\n"
    . "edit; re-freeze via fuzzy-tests/_harness/freeze.php.\n"
    . "--ENV--\n"
    . $envLines . "\n";

if (isset($sections['SKIPIF'])) {
    $frozen .= "--SKIPIF--\n" . $sections['SKIPIF'] . "\n";
}

$frozen .= "--FILE--\n" . $body . "\n"
    . "--EXPECT--\n"
    . trim($sections['EXPECT'] ?? 'PASS') . "\n";

$dir = dirname($out);
if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
    fail("cannot create output directory: $dir");
}
if (file_put_contents($out, $frozen) === false) {
    fail("cannot write: $out");
}

echo "froze -> $out\n";
