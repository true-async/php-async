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
use Async\Future;
use Async\FutureState;
use Async\Scope;
use Async\TaskGroup;
use Async\ThreadChannel;
use Async\ThreadPool;
use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

final class Context {
    public Rng $rng;
    public ValueResolver $resolver;

    /** @var array<string, array{capacity:int,noProducerTimeout:int,noConsumerTimeout:int,hardTimeouts:bool}> */
    public array $channelDefs = [];

    /** @var array<string, Channel> populated by run() */
    public array $channels = [];

    /** @var array<string, \Async\Coroutine> populated by run() before any body executes */
    public array $coroutineHandles = [];

    /** @var array<string, \Closure[]> coroutine_name => list of action closures */
    public array $plannedActions = [];

    /** @var array<string, string|null> coroutine_name => scope_name|null (where to spawn) */
    public array $coroutineScopes = [];

    /** @var string[] scope names declared in Given */
    public array $scopeDefs = [];

    /** @var array<string, Scope> populated by run() */
    public array $scopes = [];

    /** @var array<string, bool> scope name => has exception handler installed */
    public array $scopeExceptionHandler = [];

    /** @var array<string, bool> scope name => has finally callback installed */
    public array $scopeFinally = [];

    /** @var array<string, string> scope name => parent scope name (Scope::inherit) */
    public array $scopeParent = [];

    /** @var string[] future names declared in Given */
    public array $futureDefs = [];

    /** @var array<string, FutureState> populated by run() */
    public array $futureStates = [];

    /** @var array<string, Future> populated by run() */
    public array $futures = [];

    /** @var array<string, array{concurrency:?int,queueLimit:?int}> */
    public array $taskGroupDefs = [];

    /** @var array<string, TaskGroup> populated by run() */
    public array $taskGroups = [];

    /** @var array<string, int> name => capacity */
    public array $threadChannelDefs = [];

    /** @var array<string, ThreadChannel> populated by run() */
    public array $threadChannels = [];

    /** @var array<string, array{workers:int,queueSize:int}> */
    public array $threadPoolDefs = [];

    /** @var array<string, ThreadPool> populated by run() */
    public array $threadPools = [];

    /** @var array<string, Future[]> futures returned by ThreadPool::submit, keyed by pool name */
    public array $threadPoolFutures = [];

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
    public function defineChannel(
        string $name,
        int $capacity,
        int $noProducerTimeout = 0,
        int $noConsumerTimeout = 0,
        bool $hardTimeouts = false
    ): void {
        $this->channelDefs[$name] = [
            'capacity' => $capacity,
            'noProducerTimeout' => $noProducerTimeout,
            'noConsumerTimeout' => $noConsumerTimeout,
            'hardTimeouts' => $hardTimeouts,
        ];
    }

    /** Plan a coroutine; idempotent. Optionally bound to a scope. */
    public function defineCoroutine(string $name, ?string $scopeName = null): void {
        if (!isset($this->plannedActions[$name])) {
            $this->plannedActions[$name] = [];
        }
        if ($scopeName !== null) {
            $this->coroutineScopes[$name] = $scopeName;
        } elseif (!array_key_exists($name, $this->coroutineScopes)) {
            $this->coroutineScopes[$name] = null;
        }
    }

    public function defineScope(string $name): void {
        if (!in_array($name, $this->scopeDefs, true)) {
            $this->scopeDefs[] = $name;
        }
    }

    public function defineTaskGroup(string $name, ?int $concurrency = null, ?int $queueLimit = null): void {
        $this->taskGroupDefs[$name] = ['concurrency' => $concurrency, 'queueLimit' => $queueLimit];
    }

    public function defineThreadChannel(string $name, int $capacity): void {
        $this->threadChannelDefs[$name] = $capacity;
    }

    public function defineThreadPool(string $name, int $workers, int $queueSize = 0): void {
        $this->threadPoolDefs[$name] = ['workers' => $workers, 'queueSize' => $queueSize];
        $this->threadPoolFutures[$name] = [];
    }

    public function bumpMax(string $key, int $value): void {
        if ($value > ($this->counters[$key] ?? 0)) {
            $this->counters[$key] = $value;
        }
    }

    public function defineFuture(string $name): void {
        if (!in_array($name, $this->futureDefs, true)) {
            $this->futureDefs[] = $name;
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

        foreach ($this->channelDefs as $name => $spec) {
            $this->channels[$name] = new Channel(
                $spec['capacity'],
                $spec['noProducerTimeout'],
                $spec['noConsumerTimeout'],
                $spec['hardTimeouts']
            );
        }
        // Instantiate scopes in dependency order so a child can reference its
        // already-constructed parent. Fixpoint loop handles arbitrary depth.
        $remaining = $this->scopeDefs;
        while ($remaining) {
            $progress = false;
            foreach ($remaining as $i => $name) {
                $parent = $this->scopeParent[$name] ?? null;
                if ($parent !== null && !isset($this->scopes[$parent])) {
                    continue;
                }
                $scope = $parent !== null
                    ? Scope::inherit($this->scopes[$parent])
                    : new Scope();
                $this->scopes[$name] = $scope;
                if (!empty($this->scopeExceptionHandler[$name])) {
                    $self2 = $this;
                    $scope->setExceptionHandler(function($scope, $coroutine, \Throwable $e) use ($self2, $name) {
                        $self2->inc("scope_exception_handled_$name");
                    });
                }
                if (!empty($this->scopeFinally[$name])) {
                    $self2 = $this;
                    $scope->finally(function() use ($self2, $name) {
                        $self2->inc("scope_finally_$name");
                    });
                }
                unset($remaining[$i]);
                $progress = true;
            }
            if (!$progress) {
                throw new \RuntimeException('scope parent cycle or unknown parent in: ' . implode(',', $remaining));
            }
        }
        foreach ($this->threadChannelDefs as $name => $cap) {
            $this->threadChannels[$name] = new ThreadChannel($cap);
        }
        foreach ($this->threadPoolDefs as $name => $spec) {
            if ($spec['queueSize'] === 0) {
                $this->threadPools[$name] = new ThreadPool($spec['workers']);
            } else {
                $this->threadPools[$name] = new ThreadPool($spec['workers'], $spec['queueSize']);
            }
        }
        foreach ($this->taskGroupDefs as $name => $spec) {
            if ($spec['concurrency'] === null && $spec['queueLimit'] === null) {
                $this->taskGroups[$name] = new TaskGroup();
            } elseif ($spec['queueLimit'] === null) {
                $this->taskGroups[$name] = new TaskGroup($spec['concurrency']);
            } else {
                $this->taskGroups[$name] = new TaskGroup($spec['concurrency'], $spec['queueLimit']);
            }
        }
        foreach ($this->futureDefs as $name) {
            $state = new FutureState();
            $this->futureStates[$name] = $state;
            $this->futures[$name]      = new Future($state);
        }

        $self = $this;
        // First pass: spawn every coroutine, populate handles. Coroutine bodies
        // do NOT run yet (spawn just queues), so by the time the first body
        // begins all $coroutineHandles entries are visible to it.
        //
        // Scope-bound coroutines are tracked for cancel-target lookup but are
        // NOT placed in the master await_all list — the scope owns their
        // lifecycle. This lets the scope's exception handler fire on uncaught
        // child exceptions instead of having await_all silently absorb them.
        $awaitable = [];
        foreach ($this->plannedActions as $coroName => $actions) {
            $body = function() use ($actions, $self, $coroName) {
                foreach ($actions as $action) {
                    $action($self);
                }
            };
            $scopeName = $this->coroutineScopes[$coroName] ?? null;
            if ($scopeName !== null && isset($this->scopes[$scopeName])) {
                $h = $this->scopes[$scopeName]->spawn($body);
                $self->coroutineHandles[$coroName] = $h;
                // If the scope has an exception handler installed, child
                // exceptions must reach the scope handler — and an outer
                // awaiter would consume them first. Skip the external await
                // for those scopes only.
                if (empty($this->scopeExceptionHandler[$scopeName])) {
                    $awaitable[] = $h;
                }
            } else {
                $self->coroutineHandles[$coroName] = spawn($body);
                $awaitable[] = $self->coroutineHandles[$coroName];
            }
        }
        if ($awaitable) {
            await_all($awaitable);
        }

        // Dispose every scope in reverse-of-insertion order so that nested
        // child scopes are released before their parents. For scopes with
        // an exception handler the children were not in await_all, so we
        // must first wait for them via awaitCompletion (otherwise dispose()
        // would cancel children before their throw paths execute and the
        // handler would never see them).
        foreach (array_reverse($this->scopes, true) as $name => $scope) {
            if (!empty($this->scopeExceptionHandler[$name])
                && !$scope->isClosed() && !$scope->isCancelled()) {
                $cancelState  = new FutureState();
                $cancelFuture = new Future($cancelState);
                try {
                    $scope->awaitCompletion($cancelFuture);
                } catch (\Throwable $e) {
                    // Scope cancellation surfaces here; counters reflect it.
                }
                $cancelFuture->ignore();
            }
            if (!$scope->isClosed()) {
                try {
                    $scope->dispose();
                } catch (\Throwable $e) {
                    // dispose() may throw if scope already in flight; harmless.
                }
            }
        }
        // Let finally handlers and post-dispose cancellations drain. Without
        // these suspends the scheduler hasn't run the post-dispose microtasks
        // by the time Then-step assertions execute. Bound the loop so a stuck
        // coroutine surfaces as an orphan-coroutines failure rather than a
        // hang.
        if ($this->scopes) {
            // First a couple of suspends drain micro-tasks (post-dispose
            // finally callbacks fire here). Then short delays advance the
            // reactor so any timer-blocked child receives its cancellation.
            for ($i = 0; $i < 4 && count(\Async\get_coroutines()) > 1; $i++) {
                suspend();
            }
            for ($i = 0; $i < 8 && count(\Async\get_coroutines()) > 1; $i++) {
                \Async\delay(1);
            }
        }

        // Belt-and-braces: every channel ends closed so leftover senders/receivers wake up.
        foreach ($this->channels as $ch) {
            if (!$ch->isClosed()) {
                $ch->close();
            }
        }
        foreach ($this->threadChannels as $tch) {
            if (!$tch->isClosed()) {
                $tch->close();
            }
        }
        foreach ($this->threadPools as $pool) {
            if (!$pool->isClosed()) {
                $pool->close();
            }
        }

        // Suppress "Future was never used" warnings for futures that no
        // coroutine got around to awaiting (await_any only consumes one).
        foreach ($this->futures as $f) {
            $f->ignore();
        }
    }
}
