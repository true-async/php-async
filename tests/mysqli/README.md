# MySQLi Async Tests

This directory contains tests for MySQLi (MySQL Improved) functionality with the True Async extension.

## Test Coverage

### Basic Functionality
- **001-mysqli_connect_async.phpt**: Basic async connection and simple query
- **002-mysqli_query_async.phpt**: Query execution with INSERT/SELECT operations
- **003-mysqli_concurrent_connections.phpt**: Multiple coroutines with separate connections
- **004-mysqli_prepared_async.phpt**: Prepared statements with parameter binding
- **005-mysqli_multi_query_async.phpt**: Multi-query execution in async context
- **006-mysqli_transaction_async.phpt**: Transaction handling with autocommit control

### Advanced Features
- **007-mysqli_error_scenarios.phpt**: Error handling for various failure cases
- **008-mysqli_result_async.phpt**: Result handling and different fetch methods
- **009-mysqli_cancellation.phpt**: Query cancellation and timeout mechanisms
- **010-mysqli_cleanup_async.phpt**: Resource cleanup and connection management

## Architecture

MySQLi is the MySQL Native extension that uses MySQLND driver, integrating with True Async:

```
MySQLi API (ext/mysqli)
    ↓
MySQLND Driver (ext/mysqlnd)
    ↓
True Async (xpsocket.c)
    ↓
MySQL Server
```

## Key Testing Principles

### Connection Isolation
- **Critical Rule**: One MySQLi connection per coroutine
- Connections cannot be safely shared between coroutines
- Each coroutine must create its own mysqli instance

### Async Patterns
```php
use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    $mysqli = new mysqli($host, $user, $passwd, $db, $port);
    // async operations
    $mysqli->close();
    return $result;
});

$result = await($coroutine);
```

### Concurrent Operations
```php
$coroutines = [
    spawn(function() { 
        $mysqli = new mysqli(/* connection params */);
        // async database work
        $mysqli->close();
    }),
    // more coroutines...
];

$results = awaitAllOrFail($coroutines);
```

## Environment Variables

Tests use standard MySQL connection environment variables:
- `MYSQL_TEST_HOST` (default: `127.0.0.1`)
- `MYSQL_TEST_PORT` (default: `3306`)
- `MYSQL_TEST_USER` (default: `root`)
- `MYSQL_TEST_PASSWD` (default: empty)
- `MYSQL_TEST_DB` (default: `test`)

## Running Tests

```bash
# Run all MySQLi async tests
php run-tests.php ext/async/tests/mysqli/

# Run specific test
php run-tests.php ext/async/tests/mysqli/001-mysqli_connect_async.phpt
```

## Test Categories

### Connection Management
- Basic connection establishment with `new mysqli()`
- Connection isolation verification between coroutines
- Automatic and explicit connection cleanup

### Query Operations
- Simple queries with `mysqli::query()`
- Prepared statements with `mysqli::prepare()`
- Multi-query execution with `mysqli::multi_query()`
- Parameter binding and execution

### Result Handling
- Different fetch methods: `fetch_assoc()`, `fetch_array()`, `fetch_object()`
- Result metadata access: `num_rows`, `field_count`, `fetch_fields()`
- Data seeking and result navigation

### Transaction Management
- `autocommit()` control
- `begin_transaction()`, `commit()`, `rollback()`
- Transaction isolation between coroutines

### Error Scenarios
- SQL syntax errors
- Table not found errors
- Constraint violation errors
- Connection failure handling
- Prepared statement errors

### Cancellation & Resource Management
- Manual coroutine cancellation during long queries
- Timeout-based query cancellation
- Prepared statement cancellation
- Automatic resource cleanup verification
- Memory leak prevention

## MySQLi-Specific Features

### Multi-Query Support
MySQLi supports executing multiple queries in one call:
```php
$mysqli->multi_query("DROP TABLE IF EXISTS test; CREATE TABLE test (id INT);");
do {
    if ($result = $mysqli->store_result()) {
        // process result
        $result->free();
    }
} while ($mysqli->next_result());
```

### Advanced Result Handling
MySQLi provides rich result manipulation:
- `data_seek()` for random result access
- `fetch_all()` for retrieving all rows at once
- Field metadata with type information
- Both buffered and unbuffered queries

### Prepared Statement Benefits
- Type-safe parameter binding
- Protection against SQL injection
- Better performance for repeated queries
- Support for all MySQL data types

## Implementation Notes

1. **MySQLND Integration**: Tests verify async behavior through MySQLND driver
2. **Native MySQL Features**: Tests use MySQLi-specific functionality (multi-query, etc.)
3. **Resource Management**: Tests ensure proper cleanup of connections, statements, and results
4. **Error Handling**: Tests verify that mysqli errors are properly handled in async context
5. **Performance**: Tests include scenarios with concurrent operations and cancellation

These tests ensure that MySQLi works correctly with True Async while maintaining all the advanced features that make MySQLi the preferred choice for MySQL-specific applications.