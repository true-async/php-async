# #118 — Tracing-JIT: stale FP-relative spill across inlined return

**Status:** root cause identified, fix landed (single-line change in
`zend_jit_leave_func`). Repro test passes 30/30 iterations. Validated: see
"Fix" section at the bottom.
**Crash:** `SIGSEGV` in `ZEND_FAST_CONCAT_SPEC_CONST_CV_HANDLER` (or any handler that
dereferences a CV after the bug corrupts it). Only under `opcache.jit=tracing`.

This document **supersedes** the earlier `118-tracing-jit-return-frame-corruption.md`,
whose root-cause hypothesis (recorded-vs-actual caller mismatch in a `START_RETURN`
trace) was wrong. Do not consult that older document — the trace type, the absence
of any guard, and the concrete IR all turned out to be different.

## TL;DR

A tracing-JIT trace that fully inlines a callee (`Context::inc`) emits, in its
return epilogue, a register-spill `STORE` whose target address is computed as
`FP + 0x70`. The IR scheduler places this store **after** the `RSTORE(ZREG_FP, prev)`
that swaps `FP` to the caller's frame. By the time the store executes, `FP` (=`%r14`)
already points at the caller's frame, so `0x70(%r14)` is the caller's CV2, not the
callee's T2. In the failing repro the caller is the `planAction` closure whose CV2
is `$pool` (a `zend_string*`). The store overwrites `$pool.value` with the integer
`1`, leaves the type tag at `IS_STRING`, and the next `FAST_CONCAT` dereferences
`0x1` as a string → SEGV.

The bug is local to the JIT code generator — there is no race, no thread interaction,
no cross-trace linkage problem. Single trace, single thread, wrong instruction order.

## Trace identity

Established via instrumentation in `zend_jit_compile_root_trace` /
`zend_jit_compile_side_trace` / `zend_jit_leave_func` / `zend_jit_trace_helper`.

```
COMPILE-ROOT trace=73 start_flag=0x2 (= ZEND_JIT_TRACE_START_ENTER, NOT START_RETURN)
  scope=Async\Chaos\Context func=inc
  file=ext/async/fuzzy-tests/_harness/Context.php line=184
  start_op=0 opcode=63 (ZEND_RECV_INIT)

RECORD-START start_flag=0x2 scope=Async\Chaos\Context func=inc line=184
  prev_scope=Async\Chaos\StandardSteps
  prev_func={closure:{closure:Async\Chaos\StandardSteps::register():756}:758}

LEAVE_FUNC trace=73 func=Async\Chaos\Context::inc
  trace_op=8 (= ZEND_JIT_TRACE_END)
  cf=0x... prev=(nil) ukn_ret=1 nested=0 return_used=-1 num_args=-1 may_throw=1

LEAVE_FUNC FALLBACK_EXC_PATH trace=73 trace_op=8 (no IP guard, no continuation)
```

So:
- This is a `START_ENTER` root trace whose root op_array is `Context::inc` itself.
- Recording started at `inc`'s entry opline (offset 0, `RECV_INIT`).
- The trace records the entire body of `inc` and stops on `ZEND_RETURN` with
  `ZEND_JIT_TRACE_STOP_RETURN`. There is no `TRACE_BACK` record continuing into the
  caller — the trace simply hands control back to the interpreter at the caller's
  next opline.
- The caller present at recording time was the `planAction` closure
  (`{closure:…StandardSteps::register():756}:758}`) — but this is not material:
  the trace is reused from any caller of `inc()`.
- Inside `zend_jit_leave_func` the path taken is the **FALLBACK_EXC_PATH** — the
  `if (trace->op == ZEND_JIT_TRACE_BACK …)` block on `zend_jit_ir.c:11201` is never
  entered (because `trace_op == END`, not `BACK`). Only the `EG(exception)` check
  is emitted; **no IP guard, no caller-identity check at all.**

## TSSA for trace 73

```
TRACE 73 TSSA  Async\Chaos\Context::inc()  Context.php:184
0000 #2.CV0($counter) [rc1, rcn, string] = RECV 1
0001 RECV_INIT 2 int(1) #1.CV1($by) → #3.CV1($by) [long]
0002 #4.T3 [array] = FETCH_OBJ_IS THIS string("counters")
0003 #5.T2 [any]   = FETCH_DIM_IS T3 CV0($counter)
0004 #6.T3 [bool|long|...] = COALESCE T2 0006
0005 #7.T3 [long 0..0]     = QM_ASSIGN int(0)
0006 #8.T2 [long]          = ADD T3 CV1($by)
0007 #9.V3 [any]           = FETCH_OBJ_W (dim write) THIS string("counters")
0008                         ASSIGN_DIM V3 CV0($counter)
0009                         ;OP_DATA T2
0010                         RETURN null
```

`inc.last_var = 2`, so CV0=`$counter` @ `0x50`, CV1=`$by` @ `0x60`. T-slots start
at `0x70`. The bytecode allocates T2 to slot **`0x70`** in `inc`'s frame (verified
by IR `c_42 = 0x70` and the IR addressing pattern below).

## IR FINAL evidence — the bug, line-by-line

Final BB of trace 73 (`zend_jit_ir.c` calls `zend_jit_leave_func` at the RETURN, then
the trace ends with `zend_jit_trace_return` → tailcall):

```
;; FP swap (zend_jit_leave_func line 11169)
l_343 = RSTORE(d_342, 14);                     ; ZREG_FP = caller_FP    ← AFTER THIS, FP = caller
l_344 = RLOAD(l_343, 14);                      ; d_344 = caller_FP

;; IP setup (lines 11192-11193)
d_345 = LOAD(d_344);                           ; *(caller_FP + 0) = caller->opline
l_346 = RSTORE(d_345, 15);                     ; ZREG_IP = caller_opline
d_347 = RLOAD(l_346, 15);
d_348 = ADD(d_347 {%r15}, 0x20);               ; IP += sizeof(zend_op)
l_349 = RSTORE(d_348, 15);

;; EG(exception) check (FALLBACK_EXC_PATH, line 11249)
d_350 = ADD(d_338 {%rax}, 0x38f0);
d_351 = LOAD(l_349, d_350);
l_352 = GUARD_NOT(l_351, d_351, leave_throw_stub);

;; *** THE BUG ***
d_353 = ADD(d_344 {%r14}, c_42=0x70);          ; d_353 = caller_FP + 0x70   (NOT inc_FP!)
l_354 = STORE(l_352, d_353, d_165 {%rbx});     ; *(caller_FP + 0x70) = $by  ← OVERWRITES caller's CV2

;; Tailcall to caller's next opline
d_355 = RLOAD(l_354, 15);
d_356 = LOAD(l_355, d_355 {%r15});
l_357 = TAILCALL(d_356);                       ; jmp *(caller->opline + 1)
```

Where `d_165` is the value loaded much earlier in the trace by:

```
d_163 = RLOAD(l_162, 14);                      ; FP = inc_FP at this point
d_164 = ADD(d_163 {%r14}, c_18=0x60);          ; &$by  (CV1 in inc)
d_165 {%rbx} = LOAD(l_163, d_164);             ; d_165 = $by  (default 1)
```

So `d_165` was loaded from `inc.frame[CV1=0x60]` while `FP` was still `inc`. It
stayed in `%rbx` for the entire trace. At the very end, IR scheduled an extra
spill of `%rbx` into `FP + 0x70` — but `FP` was already swapped, so the spill
landed in `caller.frame[CV2=0x70]` = `$pool`.

## Why this is a scheduling bug, not a layout bug

`%r14` is bound to `ZREG_FP`. In IR, `RLOAD(ZREG_FP)` returns "the most recent value
written to ZREG_FP in execution order." When some PHP-JIT code path emits a
`STORE(ADD(RLOAD(ZREG_FP), 0x70), %rbx)` *after* `zend_jit_leave_func` has already
emitted `RSTORE(ZREG_FP, caller_FP)`, the new `RLOAD` is fused with the post-swap
value — and the store sees the **caller's** FP.

The spill that ends up at `l_354` is structurally a "materialize live SSA value
into its home memory slot before exit" operation. The home slot for `T2` is
correctly computed as `FP + 0x70`. The semantic intent is "spill into `inc`'s T2".
The realized effect, due to scheduling, is "spill into caller's CV at offset 0x70".

The PHP-level dependency that should have been encoded is: **this store must
precede `RSTORE(ZREG_FP, prev)`**. No such ordering edge exists in the IR; both
operations interact only through the implicit `ZREG_FP` virtual register, and that
virtual register's "value at this point" is determined by graph scheduling, not by
emit order.

## What the correct asm should be

Right (logically equivalent, no corruption):

```
mov %rbx, 0x70(%r14)        ; spill into inc.T2  (FP still = inc)
mov 0x30(%r14), %r14        ; FP = caller_FP
mov (%r14), %r15            ; caller->opline
add $0x20, %r15
cmpq $0, 0x38f0(%rax)
jne <leave_throw>
jmp *(%r15)
```

Wrong (what JIT actually emits today):

```
mov 0x30(%r14), %r14        ; FP = caller_FP    ← swap moved up
mov (%r14), %r15
add $0x20, %r15
cmpq $0, 0x38f0(%rax)
jne <leave_throw>
mov %rbx, 0x70(%r14)        ; ← write goes to caller.frame[0x70] = $pool
jmp *(%r15)
```

## Where the offending STORE comes from

Pinned. It is **not** an explicit `ir_STORE` from any C function in PHP-JIT.
It is emitted automatically by `jit_SNAPSHOT` (`zend_jit_ir.c:639`), which is
registered as `ctx->snapshot_create` and is invoked from inside *every*
`ir_GUARD` / `ir_GUARD_NOT` (see `ir.c:_ir_GUARD_NOT` line 3187:
`if (ctx->snapshot_create) ctx->snapshot_create(ctx, addr);`).

`jit_SNAPSHOT` walks `JIT_G(current_frame)->stack` and for each entry where
`STACK_REF` is set and the value is not flagged `ZREG_STORE` it includes the SSA
ref in an `ir_SNAPSHOT` node. That snapshot is the deopt descriptor: when the
guard fails, the IR backend materializes each snapshot ref into its memory home
slot (`FP + EX_NUM_TO_VAR(i)`) so the interpreter can read it.

The materialization itself is generated by IR's register allocator from the
SNAPSHOT data. **The address it uses for the store is `RLOAD(ZREG_FP) +
offset`.** That `RLOAD(ZREG_FP)` reads the *current* value of the FP virtual
register at the moment the materialization is scheduled — which is *after*
`zend_jit_leave_func` has issued `RSTORE(ZREG_FP, prev_execute_data)`.

In trace #73 the relevant guard is the `ir_GUARD_NOT(EG.exception)` emitted
in the FALLBACK_EXC_PATH (line 11288). Although `jit_stub_leave_throw` is in
`jit_SNAPSHOT`'s skip-list (line 658), what we observed wasn't a snapshot for
*that* guard specifically — instead, the live SSA value `$by` (=`d_165`) was
held in `%rbx` across the entire trace, kept tracked in `STACK_REF[T2]`, and
the regalloc emitted a "consolidate to home" store at the only convergence
point past the FP swap, before the trailing tailcall. The store has the form
`STORE(RLOAD(ZREG_FP) + 0x70, %rbx)` — and `RLOAD(ZREG_FP)` returns the
caller's FP because RSTORE happened first.

## Fix

Single change in `zend_jit_leave_func` (`ext/opcache/jit/zend_jit_ir.c`,
just after `ir_STORE(jit_EG(vm_stack_top), jit_FP(jit));`, immediately before
`jit_STORE_FP(jit, ir_LOAD_A(jit_EX(prev_execute_data)));`):

```c
/* clear callee STACK_REF before FP swap; otherwise jit_SNAPSHOT()
 * (auto-invoked by every subsequent ir_GUARD*) materializes live regs
 * into FP-relative home slots and the materialization is scheduled
 * after RSTORE(ZREG_FP), so RLOAD(ZREG_FP) sees the caller frame. */
if (JIT_G(trigger) == ZEND_JIT_ON_HOT_TRACE && JIT_G(current_frame)) {
    zend_jit_trace_stack *_stack = JIT_G(current_frame)->stack;
    uint32_t _stack_size = op_array->last_var + op_array->T;
    for (uint32_t _i = 0; _i < _stack_size; _i++) {
        CLEAR_STACK_REF(_stack, _i);
    }
}
```

### Why it is safe

After `zend_jit_leave_func`, the callee frame is being torn down. No code in
the trace continuation, the side-exit pads, or the interpreter will read T-
slots or CVs of the returned function — those slots cease to exist. So
materializing them into memory was already an empty insurance — the only effect
was the corruption.

The clear is gated by `trigger == ZEND_JIT_ON_HOT_TRACE && current_frame != NULL`,
i.e. only the tracing-JIT path. Function-JIT and the trace recorder are
unaffected.

### What is **not** changed by this fix

- No change in TRACE_BACK semantics: if the trace continues into the caller,
  the caller's `STACK_REF` is set up *separately* from a different `frame->prev`
  on the post-BACK side, so clearing the callee's `STACK_REF` does not
  interfere.
- No change in IR scheduling, no IR API additions, no IR/regalloc
  modification — purely a PHP-JIT-side bookkeeping correction.
- Defense-in-depth IP guard for `END+STOP_RETURN+prev=NULL+UNKNOWN_RETURN` is
  *not* added by this fix; it is still recommended as a separate commit, but
  was not required to eliminate the SEGV here.

### Validation

Repro before fix: SIGSEGV after 2-3 iterations of `--repeat 30`.
Repro after fix: 30/30 iterations PASS, exit 0.

Command:

```sh
taskset -c 0 timeout 60 bld/sapi/cli/php --repeat 30 \
  -dopcache.enable=1 -dopcache.enable_cli=1 -dopcache.protect_memory=1 \
  -dopcache.jit=tracing -dopcache.jit_buffer_size=64M \
  -dopcache.jit_hot_{loop,func,return,side_exit}=1 \
  -f ext/async/fuzzy-tests/_generated/thread_pool/cancel__00_submit_then_cancel_every_future_settles_cleanly.php
```

## Reproduction

The bug reproduces locally without docker/rr if a single CPU is used:

```sh
cd /home/edmond/php-src
taskset -c 0 timeout 60 bld/sapi/cli/php --repeat 30 \
  -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.protect_memory=1 \
  -d opcache.jit=tracing -d opcache.jit_buffer_size=64M \
  -d opcache.jit_hot_loop=1 -d opcache.jit_hot_func=1 \
  -d opcache.jit_hot_return=1 -d opcache.jit_hot_side_exit=1 \
  -d zend.assertions=1 -d date.timezone=UTC \
  -f ext/async/fuzzy-tests/_generated/thread_pool/cancel__00_submit_then_cancel_every_future_settles_cleanly.php
```

Crashes with SIGSEGV (exit 139) within the first few `--repeat` iterations.

For full IR/ASM/TSSA dump add `-d opcache.jit_debug=0x20E5401` and capture stderr.
The repro and analysis helpers are in:

- `118-run.sh` — wraps the command above (modes: plain, taskset, rr).
- `118-analyze.sh` — post-mortem grep over the captured stderr.

Both live in the repository root.

## Build flags

Reproduction requires a debug ZTS build:
- `--enable-debug --enable-zts`
- opcache enabled (statically linked is fine)
- no ASAN — ASAN's instrumentation perturbs IR scheduling enough to mask the bug.
- single CPU (docker `--cpus=1` or `taskset -c 0`); reproducer relies on lack of
  parallel work to keep the trace JIT path deterministic. Without the constraint,
  the trace recorder may stop earlier and never emit the offending pattern.

## Instrumentation in tree (uncommitted)

`fprintf(#118 …)` calls in:
- `ext/opcache/jit/zend_jit_trace.c` — `COMPILE-ROOT`, `COMPILE-SIDE`
- `ext/opcache/jit/zend_jit_ir.c`     — `LEAVE_FUNC` + path tags (`SKIP_GUARD_PATH`,
  `EMIT_IP_GUARD`, `FALLBACK_EXC_PATH`, `NO_GUARD_PATH`)
- `ext/opcache/jit/zend_jit_vm_helpers.c` — `RECORD-START`

To remove all of this once the fix lands: `git grep -n "#118"
ext/opcache/jit/`.

## Fix strategy (next)

Three options, in order of preference:

**(1) Targeted** — find the C function that emits the post-swap `STORE` and either
(a) emit it before `jit_STORE_FP`, or (b) capture pre-swap `FP` into a local SSA
ref and use that ref in the store. Minimal diff, obviously correct, easy to
upstream. Requires one more round of pinpoint instrumentation.

**(2) Structural barrier in `zend_jit_leave_func`** — immediately before
`jit_STORE_FP(jit, ir_LOAD_A(jit_EX(prev_execute_data)))`, force materialization
of every live SSA value with a FP-relative home slot into memory using the
current FP. Catches any future variation of the same shape. More invasive,
needs a regalloc-aware spill helper similar to the `zend_jit_store_ref` /
`zend_jit_store_type` block at `zend_jit_trace.c:7253-7273` (currently only run
for `STOP_LINK`).

**(3) Caller-identity guard for `START_ENTER` returns** — extend the IP-guard
emit condition in `zend_jit_leave_func` to also fire for
`trace_op == ZEND_JIT_TRACE_END && stop == STOP_RETURN && current_frame->prev == NULL
&& UNKNOWN_RETURN`. Defense-in-depth: even if the spill bug regresses, the
trace will side-exit when the actual caller's opline differs from the recorded one,
and the bad store will never execute. Does not address the root cause but is
cheap to add as a second line of defense.

Plan: do (1) first; add (3) as a separate defense-in-depth commit; only fall
back to (2) if (1) cannot be cleanly localized.
