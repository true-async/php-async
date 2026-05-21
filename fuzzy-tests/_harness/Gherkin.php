<?php
/**
 * Minimal Gherkin parser — subset.
 *
 * Supported:
 *   Feature: <name>
 *   Scenario: <name>
 *   Scenario Outline: <name>
 *   Given/When/Then/And/But  <step text>
 *   Examples:
 *     | col1 | col2 |
 *     | val1 | val2 |
 *   Comments starting with #
 *   <param> placeholder substitution from Examples row
 *
 *   Mutation blocks — a step slot that the generator expands into variants:
 *     One of:
 *       - <step text>
 *       - <step text>
 *     Any of:
 *       - <step text>
 *       - <step text>
 *   "One of" picks exactly one alternative per generated variant; "Any of"
 *   picks any subset (power set). Multiple blocks multiply combinatorially.
 *   `# @chaos-max N` caps the number of generated variants per scenario.
 *
 * Not supported (intentionally):
 *   Background, Tags, Rule, doc strings (""")
 *   Data tables on individual steps
 *
 * AST shape:
 *   Feature { name, scenarios: Scenario[], chaosMax: int }
 *   Scenario { name, steps: (Step|MutationGroup)[], examples: Row[]|null }
 *   Step { keyword: 'given'|'when'|'then', text, raw }
 *   MutationGroup { mode: 'one'|'any', keyword, alternatives: Step[] }
 */

namespace Async\Chaos;

final class GherkinFeature {
    /** @var GherkinScenario[] */
    public array $scenarios = [];
    /** Per-scenario cap on emitted mutation variants; null = generator default. */
    public ?int $chaosMax = null;
    public function __construct(public readonly string $name) {}
}

final class GherkinScenario {
    /** @var array<GherkinStep|GherkinMutationGroup> ordered step stream */
    public array $steps = [];
    /** @var array<string, string>[]|null */
    public ?array $examples = null;
    public bool $isOutline = false;
    public function __construct(public readonly string $name) {}

    /** Mutation groups in appearance order. */
    public function mutationGroups(): array {
        $groups = [];
        foreach ($this->steps as $node) {
            if ($node instanceof GherkinMutationGroup) {
                $groups[] = $node;
            }
        }
        return $groups;
    }
}

/**
 * A mutation block: a single slot in the step stream that the generator
 * expands into concrete variants.
 *   mode 'one' — exactly one alternative is chosen per variant
 *   mode 'any' — any subset of alternatives is chosen per variant
 */
final class GherkinMutationGroup {
    /** @var GherkinStep[] */
    public array $alternatives = [];
    public function __construct(
        public readonly string $mode,     // 'one' | 'any'
        public readonly string $keyword,  // phase: given|when|then
        public readonly int    $line,
    ) {}
}

final class GherkinStep {
    public function __construct(
        public readonly string $keyword,   // given | when | then
        public readonly string $text,
        public readonly int    $line,
    ) {}

    /** Substitute <param> placeholders from an Examples row. */
    public function substitute(array $row): self {
        $text = $this->text;
        foreach ($row as $k => $v) {
            $text = str_replace('<' . $k . '>', $v, $text);
        }
        return new self($this->keyword, $text, $this->line);
    }
}

final class Gherkin {
    public static function parse(string $source): GherkinFeature {
        $lines = explode("\n", $source);
        $feature = null;
        $scenario = null;
        $inExamples = false;
        $examplesHeader = null;
        $lastKeyword = 'given';
        $mutationGroup = null;   // open GherkinMutationGroup, or null

        foreach ($lines as $i => $rawLine) {
            $lineNo = $i + 1;
            $line = trim($rawLine);

            // Skip blanks and comments — but honour the `# @chaos-max N` knob.
            if ($line === '' || str_starts_with($line, '#')) {
                if ($feature !== null
                    && preg_match('/^#\s*@chaos-max\s+(\d+)\s*$/i', $line, $cm)) {
                    $feature->chaosMax = (int)$cm[1];
                }
                continue;
            }

            // A bullet line inside an open mutation block is one alternative.
            if ($mutationGroup !== null && preg_match('/^-\s+(.+)$/', $line, $m)) {
                $mutationGroup->alternatives[] =
                    new GherkinStep($mutationGroup->keyword, trim($m[1]), $lineNo);
                continue;
            }
            // Anything else closes the mutation block.
            $mutationGroup = null;

            // Examples table parsing has a different lexer — markdown-style |
            if ($inExamples && str_starts_with($line, '|')) {
                $cells = self::parseTableRow($line);
                if ($examplesHeader === null) {
                    $examplesHeader = $cells;
                } else {
                    $row = [];
                    foreach ($examplesHeader as $idx => $col) {
                        $row[$col] = $cells[$idx] ?? '';
                    }
                    $scenario->examples[] = $row;
                }
                continue;
            }
            // Anything else closes Examples mode
            $inExamples = false;
            $examplesHeader = null;

            if (preg_match('/^Feature:\s*(.*)$/i', $line, $m)) {
                $feature = new GherkinFeature(trim($m[1]));
                continue;
            }

            if (preg_match('/^Scenario Outline:\s*(.*)$/i', $line, $m)) {
                $scenario = new GherkinScenario(trim($m[1]));
                $scenario->isOutline = true;
                $scenario->examples = [];
                $feature->scenarios[] = $scenario;
                $lastKeyword = 'given';
                continue;
            }

            if (preg_match('/^Scenario:\s*(.*)$/i', $line, $m)) {
                $scenario = new GherkinScenario(trim($m[1]));
                $feature->scenarios[] = $scenario;
                $lastKeyword = 'given';
                continue;
            }

            if (preg_match('/^Examples:\s*$/i', $line)) {
                $inExamples = true;
                continue;
            }

            // Mutation block header: "One of:" / "Any of:". Following lines
            // that start with "- " are its alternatives.
            if ($scenario !== null && preg_match('/^(One|Any) of:\s*$/i', $line, $m)) {
                $mode = strtolower($m[1]) === 'one' ? 'one' : 'any';
                $mutationGroup = new GherkinMutationGroup($mode, $lastKeyword, $lineNo);
                $scenario->steps[] = $mutationGroup;
                continue;
            }

            // Step keywords matter only inside a Scenario. Before the first
            // Scenario:, the same words may appear as English prose in the
            // Feature description ("And the scope itself..."), so we skip
            // keyword matching there.
            if ($scenario !== null
                && preg_match('/^(Given|When|Then|And|But)\s+(.+)$/i', $line, $m)) {
                $kw = strtolower($m[1]);
                if ($kw === 'and' || $kw === 'but') {
                    $kw = $lastKeyword;
                } else {
                    $lastKeyword = $kw;
                }
                $scenario->steps[] = new GherkinStep($kw, trim($m[2]), $lineNo);
                continue;
            }

            // Free-text Feature description: anything that lives between
            // "Feature:" and the first "Scenario:" and isn't a recognised
            // keyword. Silently ignore.
            if ($scenario === null && $feature !== null) {
                continue;
            }

            throw new \RuntimeException("Unrecognized line $lineNo: $line");
        }

        if ($feature === null) {
            throw new \RuntimeException('No Feature: header found');
        }
        foreach ($feature->scenarios as $sc) {
            foreach ($sc->steps as $node) {
                if ($node instanceof GherkinMutationGroup && count($node->alternatives) < 1) {
                    throw new \RuntimeException(
                        "Mutation block at line {$node->line} has no '- ' alternatives");
                }
            }
        }
        return $feature;
    }

    /**
     * Flatten a mixed step stream into plain steps by applying a mutation
     * selection: selection[groupIndex] is an int (for 'one') or int[] (for
     * 'any'). A null selection uses the default — first alternative for
     * 'one', every alternative for 'any'.
     *
     * @param array<GherkinStep|GherkinMutationGroup> $steps
     * @return GherkinStep[]
     */
    public static function flatten(array $steps, ?array $selection = null): array {
        $flat = [];
        $groupIdx = 0;
        foreach ($steps as $node) {
            if (!($node instanceof GherkinMutationGroup)) {
                $flat[] = $node;
                continue;
            }
            $sel = $selection[$groupIdx] ?? null;
            if ($node->mode === 'one') {
                $pick = is_int($sel) ? $sel : 0;
                $flat[] = $node->alternatives[$pick]
                    ?? $node->alternatives[0];
            } else {
                $picks = is_array($sel)
                    ? $sel
                    : array_keys($node->alternatives);
                foreach ($picks as $idx) {
                    if (isset($node->alternatives[$idx])) {
                        $flat[] = $node->alternatives[$idx];
                    }
                }
            }
            $groupIdx++;
        }
        return $flat;
    }

    /** Parse a markdown-style table row "| a | b | c |" → ['a','b','c']. */
    private static function parseTableRow(string $line): array {
        $line = trim($line);
        $line = trim($line, '|');
        $cells = explode('|', $line);
        return array_map('trim', $cells);
    }
}
