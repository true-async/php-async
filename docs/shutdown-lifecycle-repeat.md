# Shutdown Lifecycle with `--repeat 2` and Fatal Error

## Overview

When PHP CLI runs with `--repeat 2`, `do_cli()` executes the script twice
in the same process via `goto do_repeat`. Each iteration does
`php_request_startup()` → script execution → `php_request_shutdown()`.

When a Fatal Error (e.g. memory exhaustion) occurs during async execution,
the bailout propagation interacts with the scheduler/reactor lifecycle and
can lead to a SEGV in `executor_globals_dtor` during module shutdown.

## ROUND 1

```
main() → do_cli()
  ├── do_repeat: (php_cli.c:871)
  ├── php_request_startup() (php_cli.c:917)
  │     └── PHP_RINIT(async) → ASYNC_G(reactor_started) = false
  │
  ├── [zend_try] (php_cli.c:1005)
  │     └── php_execute_script() (main.c:2640)
  │           ├── [zend_try] (main.c:2672)
  │           │     ├── zend_execute_script() → Async\spawn()
  │           │     │     ├── async_scheduler_launch() → ZEND_ASYNC_ACTIVATE
  │           │     │     └── libuv_reactor_startup() → reactor_started = true
  │           │     │           └── STDOUT/STDERR/STDIN get async_io attached
  │           │     │
  │           │     ├── Fatal Error → zend_bailout() ──longjmp──┐
  │           │     │                                            │
  │           │     └── ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false) [skipped]
  │           │                                                  │
  │           ├── [zend_catch] (main.c:2672) ◄───────────────────┘
  │           │     └── ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(true)
  │           │           = async_scheduler_main_coroutine_suspend(true)
  │           │               ├── start_graceful_shutdown()
  │           │               ├── switch_to_scheduler_with_bailout()
  │           │               ├── ZEND_ASYNC_DEACTIVATE (scheduler.c:1193)
  │           │               └── zend_bailout() (scheduler.c:1216) ─longjmp─┐
  │           │                                                               │
  │           └── [zend_end_try] (main.c:2676)                               │
  │                                                                           │
  ├── [zend_end_try] (php_cli.c:1149) ◄──────────────────────────────────────┘
  │
  ├── out: (php_cli.c:1151)
  ├── php_request_shutdown() (php_cli.c:1156)
  │     ├── php_call_shutdown_functions() → "Shutdown function called"
  │     ├── zend_call_destructors()
  │     ├── [zend_try] ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false)
  │     ├── ZEND_ASYNC_REACTOR_DETACH_IO() → nulls async_io on streams
  │     ├── ZEND_ASYNC_DEACTIVATE → state = OFF
  │     ├── ... flush output, deactivate modules (RSHUTDOWN) ...
  │     └── zend_deactivate()
  │           ├── shutdown_executor()
  │           │     └── zend_shutdown_executor_values(fast_shutdown=1)
  │           │           └── zend_close_rsrc_list() → sets type = -1
  │           ├── ENGINE_SHUTDOWN() → REACTOR_SHUTDOWN()
  │           │     ├── uv_loop_close()
  │           │     ├── reactor_started = false
  │           │     └── zend_hash_destroy(active_io_handles)
  │           └── zend_destroy_rsrc_list()
  │
  ├── request_started = 0
  ├── --num_repeats → still > 0
  └── goto do_repeat ──────────────────────────────────────────────┐
                                                                    │
```

## ROUND 2

```
  ├── do_repeat: (php_cli.c:871) ◄──────────────────────────────────┘
  ├── php_request_startup() (php_cli.c:917)
  │     └── PHP_RINIT(async) → reactor_started = false
  │
  ├── [zend_try] (php_cli.c:1005)
  │     └── php_execute_script()
  │           ├── [zend_try] (main.c:2672)
  │           │     ├── Async\spawn() → reactor_startup() → reactor_started = true
  │           │     │     └── STDOUT/STDERR/STDIN get NEW async_io attached
  │           │     ├── Fatal Error → zend_bailout() ──longjmp──┐
  │           │     │                                            │
  │           ├── [zend_catch] (main.c:2672) ◄───────────────────┘
  │           │     └── ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(true)
  │           │           = async_scheduler_main_coroutine_suspend(true)
  │           │               ├── ZEND_ASYNC_DEACTIVATE (scheduler.c:1193)
  │           │               └── zend_bailout() (scheduler.c:1216) ─longjmp─┐
  │           │                                                               │
  ├── [zend_end_try] (php_cli.c:1149) ◄──────────────────────────────────────┘
  │
  ├── out: (php_cli.c:1151)
  ├── php_request_shutdown() (php_cli.c:1156)
  │     ├── php_call_shutdown_functions() → ???
  │     ├── [zend_try] ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false)
  │     │     └── state == OFF (set by scheduler.c:1193) → what happens?
  │     ├── ZEND_ASYNC_REACTOR_DETACH_IO()
  │     │     └── reactor_started == true, but state == OFF → ???
  │     ├── ZEND_ASYNC_DEACTIVATE → state = OFF (already OFF)
  │     ├── zend_deactivate()
  │     │     ├── shutdown_executor() → zend_close_rsrc_list() → type=-1 ???
  │     │     ├── ENGINE_SHUTDOWN() → REACTOR_SHUTDOWN()
  │     │     └── zend_destroy_rsrc_list()
  │     └── ...
  │
  ├── --num_repeats → 0, return
  │
```

## MODULE SHUTDOWN (crash path)

```
main() continues after do_cli() returns:
  ├── php_module_shutdown() (php_cli.c:1373)
  │     └── zend_shutdown() (zend.c:1209)
  │           └── ts_free_id(executor_globals_id)
  │                 └── executor_globals_dtor()
  │                       └── zend_hash_destroy(zend_constants)
  │                             └── free_zend_constant(STDOUT)
  │                                   └── zval_ptr_dtor_nogc
  │                                         └── zend_hash_index_del(regular_list)
  │                                               └── list_entry_destructor
  │                                                     type=2 (NOT -1!) → dtor runs
  │                                                     → zend_resource_dtor
  │                                                       → stream_resource_regular_dtor
  │                                                         → php_stdiop_close
  │                                                           → libuv_io_close
  │                                                             → ASYNC_G(reactor_started)
  │                                                               ↑ TSRM slot already freed
  │                                                               address = 0x4c8 (NULL + offset)
  │                                                               💥 SEGV
```

## Key Observations

1. **`ZEND_ASYNC_DEACTIVATE` in scheduler.c:1193** sets `state = OFF` BEFORE
   `php_request_shutdown` runs. This may cause `REACTOR_DETACH_IO` or other
   shutdown steps to be skipped (they check `ZEND_ASYNC_IS_OFF`).

2. **`zend_bailout()` in scheduler.c:1216** propagates the bailout from
   `php_execute_script`'s `zend_catch` up to `do_cli()`'s `zend_end_try`.
   This is expected — but the state left behind may be inconsistent.

3. **Resources have `type=2`** in `executor_globals_dtor`, meaning
   `zend_close_rsrc_list()` never ran for them. This means `shutdown_executor()`
   was either skipped or did not complete during Round 2's `php_request_shutdown`.

4. **The crash only happens with `--repeat >= 2`** because it requires a second
   request cycle where the reactor/scheduler state from Round 1's cleanup
   interacts with Round 2's lifecycle.

5. **`--repeat` is passed by `run-tests.php`** to the PHP CLI binary. The CLI
   handles it via `goto do_repeat` loop in `do_cli()` (php_cli.c:1168→871).

## Relevant Files

| File | Key code |
|------|----------|
| `sapi/cli/php_cli.c:871` | `do_repeat:` label — start of repeat loop |
| `sapi/cli/php_cli.c:917` | `php_request_startup()` |
| `sapi/cli/php_cli.c:1149` | `zend_end_try()` — catches bailout from script |
| `sapi/cli/php_cli.c:1156` | `php_request_shutdown()` |
| `sapi/cli/php_cli.c:1168` | `goto do_repeat` — repeat decision |
| `main/main.c:1992` | `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false)` in request shutdown |
| `main/main.c:1997` | `ZEND_ASYNC_REACTOR_DETACH_IO()` in request shutdown |
| `main/main.c:2674` | `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(true)` in bailout catch |
| `ext/async/scheduler.c:1193` | `ZEND_ASYNC_DEACTIVATE` — sets state=OFF early |
| `ext/async/scheduler.c:1216` | `zend_bailout()` — re-throws bailout |
| `ext/async/libuv_reactor.c:298` | `libuv_reactor_detach_io()` |
| `ext/async/libuv_reactor.c:326` | `libuv_reactor_shutdown()` |
| `ext/async/libuv_reactor.c:3895` | `libuv_io_close()` — crash site |
| `Zend/zend.c:864` | `executor_globals_dtor()` — triggers crash |
| `Zend/zend.c:1356` | `shutdown_executor()` in `zend_deactivate()` |
| `Zend/zend.c:1359` | `ENGINE_SHUTDOWN()` in `zend_deactivate()` |
