# Chaos-test findings

Observations surfaced by the `fuzzy-tests/` chaos suite while chasing
invariant violations. Each entry records what was seen, what it turned out
to be, and how the suite was adjusted.

## Safe-scope zombie coroutines (not a leak)

A `disposeAfterTimeout` chaos scenario tripped the "no orphan coroutines"
invariant: a child coroutine stayed alive after the scope was disposed.

Investigated — it is **expected behaviour**, not a leak. A *safe* scope (the
default; `Scope::inherit()` inherits `DISPOSE_SAFELY` from the root scope)
does not forcibly cancel an already-started child on `cancel()` / `dispose()`.
It marks the child a **zombie** (`coroutine.c`, `async_coroutine_cancel`,
`is_safely` branch), drops it from the active coroutine count, and lets it
finish on its own. `asNotSafely()` opts into forced cancellation.

The chaos scenario now builds the scope with `asNotSafely()` so it exercises
the real cancel/reap path and the invariant holds.

**Side effect worth noting:** a zombie parked in a long `delay()` keeps its
libuv timer armed — and therefore the event loop alive — until that timer
naturally expires. For `disposeAfterTimeout`, whose purpose is *bounded*
cleanup, this means the loop can still linger for the child's full sleep.
Tracked as a design question (kill zombies once only zombies remain?).
