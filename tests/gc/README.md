# Garbage Collection Tests for Destructors with Async Operations

This directory contains comprehensive tests for garbage collection behavior when destructors contain asynchronous operations. These tests are inspired by fiber destructor tests in `Zend/tests/fibers/` and adapted for the TrueAsync API.

## Test Overview

### Core Async Operations in Destructors

- **001-gc_destructor_basic_suspend.phpt** - Basic `Async::suspend()` call in destructor
- **002-gc_destructor_spawn_coroutine.phpt** - Creating new coroutines with `Async::spawn()` in destructor  
- **003-gc_destructor_resume_other.phpt** - Resuming other suspended coroutines from destructor

### Error Handling and Edge Cases

- **004-gc_destructor_exception_with_suspend.phpt** - Exception handling with suspended destructors
- **010-gc_destructor_force_close_error.phpt** - Error behavior in terminated/cancelled coroutines

### Complex GC Scenarios

- **005-gc_destructor_cycles_with_suspend.phpt** - Circular references with suspended destructors
- **006-gc_destructor_multiple_gc_cycles.phpt** - Multiple GC cycles with suspended destructors
- **007-gc_destructor_complex_async_ops.phpt** - Complex async orchestration (spawn + suspend + resume)

### Advanced Scenarios

- **008-gc_destructor_object_resurrection.phpt** - Object "resurrection" through suspended destructors
- **009-gc_destructor_shutdown_sequence.phpt** - Async operations during PHP shutdown sequence

## Key Test Patterns

### 1. **Basic Suspension Pattern**
```php
public function __destruct() {
    Async::suspend(function($resolve) {
        // Async work here
        $resolve("result");
    });
}
```

### 2. **Coroutine Spawning Pattern**
```php
public function __destruct() {
    $coroutine = Async::spawn(function() {
        // New coroutine work
        return "result";
    });
    $result = Async::await($coroutine);
}
```

### 3. **Circular Reference Pattern**
```php
// Objects A and B reference each other
$objA->ref = $objB;
$objB->ref = $objA;
unset($objA, $objB);
gc_collect_cycles(); // Triggers destructors with cycles
```

### 4. **Exception Handling Pattern**
```php
public function __destruct() {
    try {
        Async::suspend(/* ... */);
    } catch (Exception $e) {
        // Handle async exceptions in destructor
    }
}
```

## What These Tests Verify

### ✅ **Coroutine GC Handler Integration**
- Proper registration of `async_coroutine_object_gc()` function
- Correct ZVAL tracking for all coroutine structures
- Execution stack traversal for suspended coroutines

### ✅ **GC Cycle Detection with Async**
- Detection of cycles involving coroutines with async destructors
- Proper cleanup of circular references when destructors suspend
- Multi-cycle GC behavior with long-running suspended destructors

### ✅ **Destructor Suspension Handling**
- `zend_gc_collect_cycles_coroutine()` integration
- Concurrent iterator behavior with suspended destructors
- State preservation across GC cycles

### ✅ **Error Recovery**
- Exception propagation from suspended destructors
- Force-close error handling in terminated coroutines
- Graceful degradation during shutdown sequences

### ✅ **Memory Safety**
- No memory leaks with suspended destructors
- Proper cleanup of interceptor state
- Context and scope cleanup integration

## Technical Implementation Details

These tests exercise the following core GC mechanisms:

1. **`async_coroutine_object_gc()`** - The GC handler that tracks all ZVAL references in coroutine structures
2. **`zend_gc_collect_cycles_coroutine()`** - Coroutine-aware garbage collection
3. **Interceptor GC integration** - Cleanup of coroutine switch handlers
4. **Context GC integration** - Cleanup of async context data

## Running the Tests

```bash
# Run all GC tests
php run-tests.php ext/async/tests/gc/

# Run specific test
php run-tests.php ext/async/tests/gc/001-gc_destructor_basic_suspend.phpt

# Run with verbose output
php run-tests.php -v ext/async/tests/gc/
```

## Test Dependencies

These tests require:
- PHP with async extension loaded
- Proper GC handler registration (`coroutine_handlers.get_gc = async_coroutine_object_gc`)
- Working coroutine interceptor system
- Functional `zend_gc_collect_cycles_coroutine()` implementation

## Related Documentation

- **TrueAsync API**: `docs/source/true_async_api/`
- **Core GC Tests**: `Zend/tests/gc/`
- **Fiber GC Tests**: `Zend/tests/fibers/destructors_*.phpt`
- **Async Extension Tests**: `ext/async/tests/coroutine/`