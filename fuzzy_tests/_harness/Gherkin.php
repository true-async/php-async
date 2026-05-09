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
 * Not supported (intentionally):
 *   Background, Tags, Rule, doc strings (""")
 *   Data tables on individual steps
 *
 * AST shape:
 *   Feature { name, scenarios: Scenario[] }
 *   Scenario { name, steps: Step[], examples: Row[]|null }
 *   Step { keyword: 'given'|'when'|'then', text, raw }
 */

namespace Async\Chaos;

final class GherkinFeature {
    /** @var GherkinScenario[] */
    public array $scenarios = [];
    public function __construct(public readonly string $name) {}
}

final class GherkinScenario {
    /** @var GherkinStep[] */
    public array $steps = [];
    /** @var array<string, string>[]|null */
    public ?array $examples = null;
    public bool $isOutline = false;
    public function __construct(public readonly string $name) {}
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

        foreach ($lines as $i => $rawLine) {
            $lineNo = $i + 1;
            $line = trim($rawLine);

            // Skip blanks and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

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

            if (preg_match('/^(Given|When|Then|And|But)\s+(.+)$/i', $line, $m)) {
                $kw = strtolower($m[1]);
                if ($kw === 'and' || $kw === 'but') {
                    $kw = $lastKeyword;
                } else {
                    $lastKeyword = $kw;
                }
                if ($scenario === null) {
                    throw new \RuntimeException("Step before any Scenario at line $lineNo: $line");
                }
                $scenario->steps[] = new GherkinStep($kw, trim($m[2]), $lineNo);
                continue;
            }

            throw new \RuntimeException("Unrecognized line $lineNo: $line");
        }

        if ($feature === null) {
            throw new \RuntimeException('No Feature: header found');
        }
        return $feature;
    }

    /** Parse a markdown-style table row "| a | b | c |" → ['a','b','c']. */
    private static function parseTableRow(string $line): array {
        $line = trim($line);
        $line = trim($line, '|');
        $cells = explode('|', $line);
        return array_map('trim', $cells);
    }
}
