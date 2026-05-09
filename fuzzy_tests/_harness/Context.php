<?php
/**
 * Per-scenario runtime context.
 *
 * Holds:
 *   - a deterministic RNG (Rng) seeded from CHAOS_GEN_SEED
 *   - a ValueResolver that turns "1|5" / "random:N" into concrete ints
 *   - planned channels and their capacities (instantiated at run())
 *   - planned coroutines: name → array of action closures
 *   - per-name counters (sent_count, recv_count, etc.) usable in invariants
 *
 * Step handlers receive Context $ctx as the first parameter and read/write
 * planned state through it. The actual ext/async API is touched only inside
 * Context::run(): channels and coroutines are real then.
 */

namespace Async\Chaos;

use Async\Channel;
use function Async\spawn;
use function Async\await_all;

final class Context {
    public Rng $rng;
    public ValueResolver $resolver;

    /** @var array<string, int> name => capacity */
    public array $channelDefs = [];

    /** @var array<string, Channel> populated by run() */
    public array $channels = [];

    /** @var array<string, \Closure[]> coroutine_name => list of action closures */
    public array $plannedActions = [];

    /** @var array<string, int> arbitrary named counters */
    public array $counters = [];

    /** @var string[] free-form recorded events (for debugging only) */
    public array $events = [];

    public bool $hasRun = false;

    public function __construct(int $seed) {
        $this->rng = new Rng($seed);
        $this->resolver = new ValueResolver($this->rng);
    }

    /** Define a channel by name; idempotent (last wins). */
    public function defineChannel(string $name, int $capacity): void {
        $this->channelDefs[$name] = $capacity;
    }

    /** Plan a coroutine; idempotent. */
    public function defineCoroutine(string $name): void {
        if (!isset($this->plannedActions[$name])) {
            $this->plannedActions[$name] = [];
        }
    }

    /** Append an action to a planned coroutine (creates the coroutine if absent). */
    public function planAction(string $coroName, \Closure $action): void {
        $this->defineCoroutine($coroName);
        $this->plannedActions[$coroName][] = $action;
    }

    public function inc(string $counter, int $by = 1): void {
        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + $by;
    }

    public function counter(string $counter): int {
        return $this->counters[$counter] ?? 0;
    }

    /**
     * Realise the plan: instantiate channels, spawn one coroutine per planned
     * group, await all, close any leftover channels.
     */
    public function run(): void {
        if ($this->hasRun) return;
        $this->hasRun = true;

        foreach ($this->channelDefs as $name => $cap) {
            $this->channels[$name] = new Channel($cap);
        }

        $self = $this;
        $handles = [];
        foreach ($this->plannedActions as $coroName => $actions) {
            $handles[] = spawn(function() use ($actions, $self) {
                foreach ($actions as $action) {
                    $action($self);
                }
            });
        }
        if ($handles) {
            await_all($handles);
        }

        // Belt-and-braces: every channel ends closed so leftover senders/receivers wake up.
        foreach ($this->channels as $ch) {
            if (!$ch->isClosed()) {
                $ch->close();
            }
        }
    }
}
