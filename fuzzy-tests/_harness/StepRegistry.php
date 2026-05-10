<?php
/**
 * Step registry: regex → callable.
 *
 * Each step definition associates a PCRE pattern with a closure that takes
 * (Context $ctx, ...captured-args). The registry walks definitions in order
 * and dispatches the first match.
 *
 * Captured arguments pass through ValueResolver, which understands:
 *   - "1|5"        → uniform random int in [1,5] (deterministic per RNG seed)
 *   - "0..9"       → same syntax (alternative)
 *   - "random:N"   → random int in [0, N)
 *   - plain int / string → unchanged
 */

namespace Async\Chaos;

final class ValueResolver {
    public function __construct(private readonly Rng $rng) {}

    public function resolve(string $raw): int|string {
        $raw = trim($raw);

        // 1|5 or 1..5 — inclusive range
        if (preg_match('/^(-?\d+)(?:\||\.\.)(-?\d+)$/', $raw, $m)) {
            $lo = (int)$m[1];
            $hi = (int)$m[2];
            if ($hi < $lo) { [$lo, $hi] = [$hi, $lo]; }
            return $this->rng->between($lo, $hi);
        }
        // random:N
        if (preg_match('/^random:(\d+)$/', $raw, $m)) {
            return $this->rng->between(0, (int)$m[1] - 1);
        }
        // pure integer
        if (preg_match('/^-?\d+$/', $raw)) {
            return (int)$raw;
        }
        // string (drop surrounding quotes if any)
        if ((str_starts_with($raw, '"') && str_ends_with($raw, '"')) ||
            (str_starts_with($raw, "'") && str_ends_with($raw, "'"))) {
            return substr($raw, 1, -1);
        }
        return $raw;
    }
}

final class Rng {
    private int $state;
    public function __construct(int $seed) {
        $this->state = $seed === 0 ? 0xDEADBEEF : $seed;
    }
    public function next(): int {
        $x = $this->state & 0xFFFFFFFF;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5)  & 0xFFFFFFFF;
        $this->state = $x;
        return $x;
    }
    public function between(int $lo, int $hi): int {
        if ($hi <= $lo) return $lo;
        return $lo + ($this->next() % ($hi - $lo + 1));
    }
}

final class StepDefinition {
    /** @var string[] platform requirement tags ('unix-sockets', 'posix', etc.) */
    public array $requires = [];

    public function __construct(
        public readonly string  $regex,
        public readonly \Closure $handler,
    ) {}
}

final class StepRegistry {
    /** @var StepDefinition[] */
    private array $defs = [];

    public function on(string $regex, \Closure $handler): self {
        $this->defs[] = new StepDefinition($regex, $handler);
        return $this;
    }

    /**
     * Tag the most recently registered step with a platform requirement.
     * Used by the .phpt generator to emit per-scenario --SKIPIF-- blocks.
     * Known tags: 'unix-sockets', 'tcp', 'pipe', 'fork', 'tty'.
     */
    public function requires(string ...$tags): self {
        if (empty($this->defs)) {
            throw new \RuntimeException("requires() must follow on()");
        }
        $last = $this->defs[count($this->defs) - 1];
        foreach ($tags as $t) {
            if (!in_array($t, $last->requires, true)) {
                $last->requires[] = $t;
            }
        }
        return $this;
    }

    /** Find the first matching step definition; returns [def, captures] or null. */
    public function match(string $stepText): ?array {
        foreach ($this->defs as $def) {
            if (preg_match($def->regex, $stepText, $m)) {
                array_shift($m); // drop full-match
                return [$def, $m];
            }
        }
        return null;
    }
}
