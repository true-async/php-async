# PDO MySQL Async Tests

This directory contains tests for PDO MySQL functionality with the True Async extension.

## Test Coverage

### Basic Functionality
- **001-pdo_connection_basic.phpt**: Basic async connection and simple query
- **002-pdo_prepare_execute_async.phpt**: Prepared statements with async execution
- **003-pdo_multiple_coroutines.phpt**: Multiple coroutines with separate connections
- **004-pdo_transaction_async.phpt**: Transaction handling (BEGIN, COMMIT, ROLLBACK)
- **005-pdo_concurrent_queries.phpt**: Concurrent query execution with different connections
- **006-pdo_connection_isolation.phpt**: Connection isolation between coroutines

### Advanced Features
- **007-pdo_error_handling_async.phpt**: Error handling in async context
- **008-pdo_fetch_modes_async.phpt**: Different fetch modes (ASSOC, NUM, OBJ, etc.)
- **009-pdo_cancellation.phpt**: Query cancellation and timeout handling
- **010-pdo_resource_cleanup.phpt**: Resource cleanup and connection management

## Architecture

PDO MySQL uses the MySQLND driver underneath, which integrates with True Async through the xpsocket.c module for non-blocking I/O operations.

```
PDO MySQL API
    ↓
MySQLND Driver (ext/mysqlnd)
    ↓
True Async (xpsocket.c)
    ↓
MySQL Server
```

## Key Testing Principles

### Connection Isolation
- **Critical Rule**: One connection per coroutine
- Connections cannot be safely shared between coroutines
- Each coroutine must create its own PDO instance

### Async Patterns
```php
use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    $pdo = new PDO($dsn, $user, $pass);
    // async operations
    return $result;
});

$result = await($coroutine);
```

### Concurrency Testing
```php
$coroutines = [
    spawn(function() { /* DB operations */ }),
    spawn(function() { /* DB operations */ }),
    spawn(function() { /* DB operations */ })
];

$results = awaitAllOrFail($coroutines);
```

## Environment Variables

Tests use standard MySQL connection environment variables:
- `PDO_MYSQL_TEST_DSN` (default: `mysql:host=localhost;dbname=test`)
- `PDO_MYSQL_TEST_USER` (default: `root`)
- `PDO_MYSQL_TEST_PASS` (default: empty)

## Running Tests

```bash
# Run all PDO MySQL async tests
php run-tests.php ext/async/tests/pdo_mysql/

# Run specific test
php run-tests.php ext/async/tests/pdo_mysql/001-pdo_connection_basic.phpt
```

## Test Categories

### Connection Management
- Basic connection establishment
- Connection isolation verification
- Resource cleanup validation

### Query Operations
- Simple queries and prepared statements
- Parameter binding and data retrieval
- Multiple fetch modes

### Transaction Handling
- BEGIN/COMMIT/ROLLBACK in async context
- Transaction isolation between coroutines
- Error handling during transactions

### Error Scenarios
- SQL syntax errors
- Connection failures
- Constraint violations
- Timeout handling

### Cancellation & Cleanup
- Manual coroutine cancellation
- Timeout-based cancellation
- Automatic resource cleanup
- Memory leak prevention

## Implementation Notes

1. **No Connection Pooling**: Tests focus on basic functionality, not pooling
2. **MySQLND Integration**: Tests verify async behavior through MySQLND driver
3. **Standard PDO API**: Tests use standard PDO methods in async context
4. **Resource Management**: Tests verify proper cleanup of connections and statements
5. **Error Propagation**: Tests ensure errors are properly caught in async context

These tests ensure that PDO MySQL works correctly with True Async while maintaining data integrity and resource management.