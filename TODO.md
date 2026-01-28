# Future Mapper Investigation

## Current State

### What Works
- ✓ Callback IS being called (prints "Mapped: 21")
- ✓ Callback IS added to source future (callbacks.length: 0 → 1)
- ✓ No segfaults
- ✓ Mapper structure properly initialized
- ✓ ZEND_ASYNC_EVENT_REFERENCE_PREFIX flag set correctly

### What Doesn't Work
- ✗ `await($mapped)` returns NULL instead of 42
- ✗ Callback result doesn't propagate to target future
- ✗ 5 memory leaks detected (32+56+152+152+152 bytes)

### Test Case
File: `/home/edmond/php-src/ext/async/tests/future/001-map-basic.phpt`
```php
$state = new FutureState();
$future = new Future($state);

$mapped = $future->map(function($value) {
    echo "Mapped: $value\n";  // ✓ This prints
    return $value * 2;         // ✗ This value (42) is lost
});

$state->complete(21);
return await($mapped);  // ✗ Returns NULL instead of 42
```

## Debugging Commands

### 1. Run Test Under GDB
```bash
cd /home/edmond/php-src/ext/async
gdb --args ../../sapi/cli/php tests/future/001-map-basic.phpt
```

### 2. Set Breakpoints
```gdb
# Break at mapper callback
b async_future_mapper_handle_success

# Break at complete operations
b zend_future_complete
b zend_future_reject

# Break at await
b zend_future_await

# Run
r
```

### 3. Investigate Callback Execution
When stopped at `async_future_mapper_handle_success`:
```gdb
# Print mapper structure
p *mapper
p mapper->mapper_type
p mapper->source_future
p mapper->target_future

# Step through callback invocation
n
# After fci_call:
p retval
p Z_TYPE(retval)
p Z_LVAL(retval)

# Check if zend_future_complete is called
# Step until zend_future_complete call
# Verify parameters:
p future
p *value
p Z_TYPE_P(value)
p Z_LVAL_P(value)
```

### 4. Trace Target Future State
```gdb
# At end of async_future_mapper_handle_success
p mapper->target_future->event
p mapper->target_future->event->status
p mapper->target_future->event->result
p Z_TYPE(mapper->target_future->event->result)
```

### 5. Check await() Return
When stopped at `zend_future_await`:
```gdb
# Check future being awaited
p future
p future->event
p future->event->status
p future->event->result

# Step through await logic
n
# Check return value
p *return_value
p Z_TYPE_P(return_value)
```

### 6. Memory Leak Investigation
```bash
cd /home/edmond/php-src/ext/async
valgrind --leak-check=full --show-leak-kinds=all ../../sapi/cli/php tests/future/001-map-basic.phpt 2>&1 | tee leak.log
```

Check leak.log for:
- 32 bytes: Likely fci/fci_cache storage
- 56 bytes: Possible async_future_mapper_t allocation
- 152 bytes: Possibly zval or future structure

## Hypotheses

### Hypothesis 1: Target Future Not Completed
**Problem**: `async_future_mapper_handle_success()` might not call `zend_future_complete()` on target_future

**Check**:
- Set breakpoint at `zend_future_complete` and verify it's called for target_future
- If NOT called: callback result is computed but never stored

**Fix**: Ensure `zend_future_complete(mapper->target_future, &retval)` is called

### Hypothesis 2: Wrong Future Awaited
**Problem**: User might be awaiting source_future instead of target_future

**Check**:
- In test, verify `$mapped` is the returned future from `->map()`
- Check that `await($mapped)` receives the correct future object

**Fix**: Should be correct (test looks right), but verify pointer identity

### Hypothesis 3: Return Value Not Copied
**Problem**: `retval` might be on stack and lost after callback returns

**Check**:
- In `async_future_mapper_handle_success()`, check if `retval` is copied with `ZVAL_COPY()`
- Verify retval lifetime

**Fix**: Use `ZVAL_COPY()` or `ZVAL_DUP()` when passing to `zend_future_complete()`

### Hypothesis 4: Replay Not Working
**Problem**: If source future completes before map() is called, replay might fail

**Check**:
- Test with `$state->complete(21)` BEFORE `$future->map()`
- See test `006-map-already-completed.phpt`

**Fix**: Ensure replay logic in `async_future_create_mapper()` works

### Hypothesis 5: Reference Counting Issue
**Problem**: Target future might be destroyed before await()

**Check**:
- Print refcount: `p mapper->target_future->std.gc.refcount`
- Verify GC_ADDREF is called when returning future from map()

**Fix**: Add `GC_ADDREF(&target->std)` before returning

## Code Locations to Check

### future.c Lines to Examine:

**Line ~720**: `async_future_mapper_handle_success()`
- Is `zend_future_complete(mapper->target_future, &retval)` called?
- Is `retval` properly initialized and copied?

**Line ~800**: `async_future_create_mapper()`
- Is target_future properly initialized?
- Is reference count correct?
- Is replay working?

**Line ~931**: `FUTURE_METHOD(map)`
- Is returned future the target_future?
- Is GC_ADDREF called?

## Next Steps

1. Run test under GDB with breakpoints
2. Trace execution from callback through to await()
3. Find where the value (42) is lost
4. Fix the issue
5. Re-run all 15 tests
6. Fix memory leaks using valgrind output
