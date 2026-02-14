# Coroutine Tests

This directory contains tests for the Coroutine class methods implementation.

## Test Coverage

### Basic Functionality
- `001-coroutine_getId_basic.phpt` - Tests getId() method
- `002-coroutine_getResult_basic.phpt` - Tests getResult() method
- `003-coroutine_getException_basic.phpt` - Tests getException() with normal and exception cases
- `004-coroutine_getException_running.phpt` - Tests getException() throws RuntimeException for running coroutines

### Status Methods
- `005-coroutine_status_methods.phpt` - Tests isStarted(), isCompleted(), isCancelled(), isSuspended()
- `013-coroutine_running_detection.phpt` - Tests isRunning() method

### Cancellation
- `006-coroutine_cancel_basic.phpt` - Tests cancel() method with AsyncCancellation

### Location Information
- `007-coroutine_spawn_location.phpt` - Tests getSpawnFileAndLine() and getSpawnLocation()
- `008-coroutine_suspend_location.phpt` - Tests getSuspendFileAndLine() and getSuspendLocation()

### Debug and Context Methods
- `009-coroutine_getTrace.phpt` - Tests getTrace() (returns empty array)
- `010-coroutine_getAwaitingInfo.phpt` - Tests getAwaitingInfo()
- `011-coroutine_asHiPriority.phpt` - Tests asHiPriority() (returns same coroutine)
- `012-coroutine_getContext.phpt` - Tests getContext() method

### Finally Handlers
- `014-coroutine_onFinally_basic.phpt` - Tests onFinally() basic functionality
- `015-coroutine_onFinally_finished.phpt` - Tests onFinally() on finished coroutines
- `016-coroutine_onFinally_multiple.phpt` - Tests multiple finally handlers
- `017-coroutine_onFinally_single_exception.phpt` - Tests finally handlers with exceptions
- `018-coroutine_onFinally_multiple_exceptions.phpt` - Tests CompositeException handling

### Garbage Collection
- `019-coroutine_gc_basic.phpt` - Tests basic GC handler functionality
- `020-coroutine_gc_with_finally.phpt` - Tests GC with finally handlers
- `021-coroutine_gc_with_context.phpt` - Tests GC with context data
- `022-coroutine_gc_suspended.phpt` - Tests GC for suspended coroutines
- `023-coroutine_gc_with_exception.phpt` - Tests GC with exception objects
- `024-coroutine_gc_multiple_zvals.phpt` - Tests GC with multiple ZVALs
- `025-coroutine_gc_waker_scope.phpt` - Tests GC with waker and scope structures

## Notes

Some methods have TODO implementations and return placeholder values:
- `getTrace()` - Returns empty array (needs fiber stack trace implementation)
- `asHiPriority()` - Returns same coroutine (needs scheduler priority implementation)

The coroutine implementation now includes:
- Complete `onFinally()` support with CompositeException handling
- Full garbage collection support to prevent memory leaks
- Context API integration for coroutine-local storage