# Scope Tests

This directory contains tests for the Async\Scope functionality.

## Test Coverage

- **001-scope_construct_basic.phpt**: Basic Scope construction and interface implementation
- **002-scope_inherit_basic.phpt**: Scope inheritance functionality
- **003-scope_provide_scope.phpt**: ScopeProvider interface implementation
- **004-scope_status_methods.phpt**: isFinished() and isClosed() methods
- **005-scope_as_not_safely.phpt**: asNotSafely() method
- **006-scope_spawn_basic.phpt**: Basic coroutine spawning in scope
- **007-scope_spawn_with_args.phpt**: Coroutine spawning with arguments
- **008-scope_child_scopes.phpt**: Child scopes management
- **009-scope_dispose_basic.phpt**: Scope disposal methods
- **010-scope_exception_handlers.phpt**: Exception handling setup
- **011-scope_on_finally.phpt**: Finally callback setup
- **012-scope_dispose_after_timeout.phpt**: Timeout-based disposal

### Finally Handlers
- **013-scope_finally_execution.phpt**: Finally handlers execution testing
- **014-scope_finally_completed.phpt**: Finally handlers on completed scopes  
- **015-scope_finally_multiple.phpt**: Multiple finally handlers
- **016-scope_finally_parameter.phpt**: Finally handlers with parameters
- **017-scope_finally_error.phpt**: Error handling in finally handlers
- **018-scope_finally_composite_exception.phpt**: CompositeException in finally handlers

### Garbage Collection
- **019-scope_gc_basic.phpt**: Basic GC handler functionality
- **020-scope_gc_with_finally.phpt**: GC with finally handlers
- **021-scope_gc_with_context.phpt**: GC with context data

## Running Tests

To run these tests:

```bash
# Run all scope tests
make test TESTS="ext/async/tests/scope/"

# Run specific test
make test TESTS="ext/async/tests/scope/001-scope_construct_basic.phpt"
```

## Notes

- These tests cover the complete Scope API functionality
- Includes comprehensive finally handler support with CompositeException handling
- Features full garbage collection support to prevent memory leaks in scoped async operations
- Tests should be run in a properly configured async environment
- Exception handling and timeout functionality is fully implemented and tested