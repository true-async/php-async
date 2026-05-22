# Frozen chaos cases

Deterministic `.phpt` replays of chaos failures caught by the fuzz matrix,
produced by [`../_harness/freeze.php`](../_harness/freeze.php).

Each frozen file pins both fuzz seeds — `CHAOS_GEN_SEED` and
`TRUE_ASYNC_SCHED` — in an `--ENV--` block, so the exact failing
`(program × value-fuzz × schedule)` point reproduces on every run with no
environment setup.

Unlike [`../_generated/`](../) (regenerated, gitignored), this directory is
**committed** and runs in CI as a permanent regression suite. See
[`../README.md`](../README.md#freezing-a-failure) for the workflow.
