# Channel deadlock protection

> Status: implemented in 0.7.0

`Async\Channel` ships with three independent layers that turn channel
deadlocks from silent hangs into observable, recoverable errors. All three
are on by default. The cause of any close is exposed as a typed enum on
`ChannelException::$reason`.

## TL;DR

```php
use Async\Channel;
use Async\ChannelException;
use Async\ChannelCloseReason;

$ch = new Channel();             // capacity 0; default 5s timeouts; soft mode

try {
    $ch->recv();                 // blocks
} catch (ChannelException $e) {
    match ($e->reason) {
        ChannelCloseReason::EXPLICIT       => /* close() was called */,
        ChannelCloseReason::DISPOSED       => /* PHP destructor fired */,
        ChannelCloseReason::NO_PRODUCERS   => /* no senders within timeout */,
        ChannelCloseReason::NO_CONSUMERS   => /* no receivers within timeout */,
        ChannelCloseReason::DEADLOCK       => /* global deadlock resolver */,
        ChannelCloseReason::SCOPE_DISPOSED => /* owner scope died */,
    };
}
```

## Layer 1 — per-channel timer

Constructor parameters:

```php
new Channel(
    int  $capacity          = 0,     // 0 = unbuffered
    int  $noProducerTimeout = 5000,  // ms; 0 disables; reason NO_PRODUCERS
    int  $noConsumerTimeout = 5000,  // ms; 0 disables; reason NO_CONSUMERS
    bool $hardTimeouts      = false, // see below
);
```

A timer is armed lazily, only when a coroutine actually blocks on the
channel:

- A blocked **receiver** (empty buffer, no senders queued) arms the
  `noProducerTimeout` timer. If it expires before a producer arrives the
  channel closes with reason `NO_PRODUCERS`.
- A blocked **sender** (full buffer, no receivers queued) arms the
  `noConsumerTimeout` timer. Reason `NO_CONSUMERS` on expiry.

The timer is disarmed and **reset from zero** as soon as the relevant
queue drains, so successful traffic does not accumulate timeout debt.

### Soft vs hard

`hardTimeouts: false` (default) marks the timer as a *hidden* event —
it does not increase the loop's active-event count and does not on its
own keep the script running. This makes the timer a fallback rather
than a contract: if everything else in the script is also waiting,
Layer 2 fires *before* the timer does.

`hardTimeouts: true` keeps the timer fully visible to the loop, so the
channel is guaranteed to wait the full configured interval before
closing. Use this when the timeout is part of the application's contract
(e.g. a fixed SLA), not a diagnostic.

## Layer 2 — global deadlock resolver

When a channel arms a *soft* timer it also registers itself in a
per-request set of "channels in potential-deadlock state". The scheduler
checks this set on every reactor tick:

- If `active_event_count == 0` (loop has nothing to wait on but hidden
  timers) **and** the set is non-empty, the scheduler skips
  `uv_run(UV_RUN_ONCE)` and proceeds straight to deadlock resolution.
- `async_channel_resolve_deadlocks()` snapshots the set and bulk-closes
  every registered channel with reason `DEADLOCK`, waking blocked
  coroutines.

The end result: a soft channel deadlock surfaces as a `ChannelException`
**immediately**, not after the configured 5 s. The per-channel timer is
still there as a backstop in case the loop is kept alive by some
unrelated event and the resolver cannot fire.

If the deadlock involves channels that have all timeouts disabled
(`0`), the resolver has nothing to do and the existing
generic-`DeadlockError` path takes over — coroutines are cancelled, the
script terminates with a `Async\DeadlockError`. Channels with `0`
timeouts opt out of Layer 2 entirely.

## Layer 3 — owner-scope binding

Every channel automatically subscribes to the close-event of the scope
it was created in (the current coroutine's scope, or the main scope at
top level). When that scope is disposed or cancelled — for any reason —
the channel closes with reason `SCOPE_DISPOSED`.

This eliminates a whole class of leaked-channel bugs:

```php
$scope = new Async\Scope();
$scope->spawn(function () {
    $ch = new Async\Channel();
    $ch->recv();          // blocks
});
$scope->dispose();        // ← channel inside the coroutine wakes
                          //    with SCOPE_DISPOSED
```

The binding is **observation, not ownership** — the channel does not
hold a refcount on the scope and does not pin it. Either side may die
first; the lifecycle is symmetric.

A channel bound to a scope continues to live (in PHP) as long as PHP
references to it exist; calls on it after `SCOPE_DISPOSED` simply throw
`ChannelException` with that reason. Buffered values written before the
scope died are still drainable through `recv()` until the buffer empties.

## Reason precedence

A channel closes exactly once. The first cause to fire wins; subsequent
causes are no-ops. So if you `close()` a channel and then its owner scope
disposes, `$reason` stays `EXPLICIT`. If the timer fires first, the
later scope dispose does not overwrite `NO_PRODUCERS`.

## Choosing settings

| Use case | Recommended settings |
|---|---|
| Application code, default | leave defaults — 5 s soft on both directions |
| Long-lived bus, brokered queue | `0, 0` (disable both); rely on scope binding + explicit `close()` |
| Hard SLA (e.g. handshake) | `noProducerTimeout: $sla_ms, hardTimeouts: true` |
| Fire-and-forget log channel | `noConsumerTimeout: 0`, leave producer timeout at default |

## Diagnostic

Every `ChannelException` carries `$reason: ChannelCloseReason` and a
human-readable `$message`. A `match($e->reason)` over the enum covers
every cause exhaustively at PHP level.

If the global deadlock resolver does *not* run (because the channel
opted out with `0` timeouts), the scheduler still produces an
`Async\DeadlockError` with a coroutine wait-graph dump on stderr — the
existing diagnostic path remains intact.

## Implementation pointers

- `ext/async/channel.h` — `channel_close_reason_t` enum,
  `channel_scope_callback_t` (extended callback that carries the scope
  back-pointer instead of putting it in the channel struct).
- `ext/async/channel.c` —
  `channel_arm/disarm/refresh_deadlock_timer` for Layer 1,
  `async_channel_resolve_deadlocks()` for Layer 2,
  `channel_bind_to_owner_scope()` for Layer 3.
- `ext/async/scheduler.c` — calls `async_channel_resolve_deadlocks()`
  at the top of `resolve_deadlocks()`; injects `no_wait` short-circuit
  at the two `ZEND_ASYNC_REACTOR_EXECUTE` call sites.
- Tests: `ext/async/tests/channel/041–065`.
