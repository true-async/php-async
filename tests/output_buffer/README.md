# Output Buffer Isolation Tests

This directory contains tests for output buffer isolation between coroutines using `ob_start()` and related functions.

## Test Cases

### 001-ob_start_basic_isolation.phpt
Tests basic isolation of `ob_start()` buffers between main coroutine and spawned coroutines with context switching via `suspend()`.

### 002-multiple_coroutines_isolation.phpt
Tests that multiple coroutines can each have their own independent output buffers that don't interfere with each other.

### 003-nested_ob_start.phpt
Tests nested `ob_start()` calls within a single coroutine to ensure the buffer stack is properly maintained across context switches.

### 004-ob_flush_isolation.phpt
Tests `ob_flush()` and `ob_clean()` operations work correctly within each coroutine's isolated buffer context.

### 005-mixed_buffering.phpt
Tests scenarios where some coroutines use output buffering and others don't, ensuring proper isolation.

### 006-exception_handling.phpt
Tests that output buffer isolation works correctly even when exceptions are thrown within coroutines.

## Key Testing Scenarios

- **Context Switching**: All tests use `suspend()` to force context switches and verify buffer state is preserved
- **Buffer Isolation**: Each coroutine should have completely independent output buffers
- **State Preservation**: Buffer content should be maintained across context switches
- **Nested Buffers**: Buffer stacks should be properly isolated per coroutine
- **Exception Safety**: Buffer isolation should work even when exceptions occur

## Expected Behavior

When `ob_start()` is called within a coroutine, it should:
1. Create an isolated output buffer for that coroutine
2. Automatically register a context switch handler for the coroutine
3. Save/restore buffer state when the coroutine loses/regains control
4. Not interfere with output buffers in other coroutines or the main thread