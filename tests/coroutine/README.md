# Coroutine Tests

This directory contains tests for the Coroutine class methods implementation.

## Test Coverage

### Basic Functionality
- `001-coroutine_getId_basic.phpt` - Tests getId() method
- `002-coroutine_getResult_basic.phpt` - Tests getResult() method
- `003-coroutine_getException_basic.phpt` - Tests getException() with normal and exception cases
- `004-coroutine_getException_running.phpt` - Tests getException() throws RuntimeException for running coroutines

### Status Methods
- `005-coroutine_status_methods.phpt` - Tests isStarted(), isFinished(), isCancelled(), isSuspended()
- `013-coroutine_running_detection.phpt` - Tests isRunning() method

### Cancellation
- `006-coroutine_cancel_basic.phpt` - Tests cancel() method with CancellationException

### Location Information
- `007-coroutine_spawn_location.phpt` - Tests getSpawnFileAndLine() and getSpawnLocation()
- `008-coroutine_suspend_location.phpt` - Tests getSuspendFileAndLine() and getSuspendLocation()

### TODO Implementation Methods
- `009-coroutine_getTrace.phpt` - Tests getTrace() (returns empty array)
- `010-coroutine_getAwaitingInfo.phpt` - Tests getAwaitingInfo()
- `011-coroutine_asHiPriority.phpt` - Tests asHiPriority() (returns same coroutine)
- `012-coroutine_getContext.phpt` - Tests getContext() (returns null)

## Notes

Some methods have TODO implementations and return placeholder values:
- `getTrace()` - Returns empty array (needs fiber stack trace implementation)
- `asHiPriority()` - Returns same coroutine (needs scheduler priority implementation)
- `getContext()` - Returns null (needs Context API implementation)
- `onFinally()` - Not implemented (needs callback registration)

These tests verify the basic API contract and will need updates when the full implementations are added.