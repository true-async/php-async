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

## Running Tests

To run these tests:

```bash
# Run all scope tests
make test TESTS="ext/async/tests/scope/"

# Run specific test
make test TESTS="ext/async/tests/scope/001-scope_construct_basic.phpt"
```

## Notes

- These tests cover the basic API functionality
- Some advanced features like exception handling and timeouts need further implementation
- Tests should be run in a properly configured async environment