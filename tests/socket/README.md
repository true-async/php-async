# Socket Tests for TrueAsync API

This directory contains tests specifically for socket functions that have been updated to use the TrueAsync API for DNS resolution.

## Background

The following socket functions were updated to use asynchronous DNS resolution when running in an async context:

- `socket_connect()` - for IPv6 hostname resolution via `php_set_inet6_addr()`
- `socket_bind()` - for IPv6 hostname resolution via `php_set_inet6_addr()`  
- `socket_sendto()` - for IPv6 hostname resolution via `php_set_inet6_addr()`

## Test Files

### 001-socket_connect_ipv6_async.phpt
Tests `socket_connect()` with IPv6 hostname resolution in async context.
- Creates an IPv6 socket
- Attempts to connect to "::1" (localhost IPv6)
- Verifies that hostname resolution works asynchronously

### 002-socket_bind_ipv6_async.phpt  
Tests `socket_bind()` with IPv6 hostname resolution in async context.
- Creates an IPv6 socket
- Attempts to bind to "::1" (localhost IPv6)
- Verifies that hostname resolution and binding work

### 003-socket_sendto_ipv6_async.phpt
Tests `socket_sendto()` with IPv6 hostname resolution in async context.
- Creates an IPv6 UDP socket
- Sends data to "::1" (localhost IPv6)
- Verifies that hostname resolution works for UDP sendto

### 004-socket_ipv6_hostname_async.phpt
Tests socket functions with real hostnames (like google.com) in async context.
- Tests hostname resolution with actual domain names
- Measures timing to ensure async behavior
- Tests both TCP and UDP scenarios

### 005-socket_performance_comparison.phpt
Compares performance between sync and async hostname resolution.
- Runs both sync and async versions
- Measures timing differences
- Verifies both complete successfully

## Implementation Details

The async hostname resolution is implemented in `ext/sockets/sockaddr_conv.c` in the `php_set_inet6_addr()` function:

```c
#ifdef PHP_ASYNC_API
    bool is_async = ZEND_ASYNC_IS_ACTIVE;

    if (is_async) {
        if (php_network_getaddrinfo_async(ZSTR_VAL(string), NULL, &hints, &addrinfo) != 0) {
            // Handle error
        }
    } else if (getaddrinfo(ZSTR_VAL(string), NULL, &hints, &addrinfo) != 0) {
        // Handle error
    }
#else
    if (getaddrinfo(ZSTR_VAL(string), NULL, &hints, &addrinfo) != 0) {
        // Handle error
    }
#endif
```

This ensures that:
1. When running in async context (`ZEND_ASYNC_IS_ACTIVE`), hostname resolution uses the async API
2. When running in sync context, it falls back to regular `getaddrinfo()`
3. When PHP_ASYNC_API is not defined, it uses regular `getaddrinfo()`

## Running the Tests

```bash
# Run all socket tests
php run-tests.php ext/async/tests/socket/

# Run specific test
php run-tests.php ext/async/tests/socket/001-socket_connect_ipv6_async.phpt
```

## Requirements

- PHP compiled with `--enable-sockets`
- TrueAsync extension loaded
- IPv6 support in the system
- Network connectivity for hostname resolution tests