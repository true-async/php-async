# Socket Extension Async Tests

This directory contains tests for async socket operations with the sockets extension.

## Test Files

- `001-socket_read_async.phpt` - Tests socket_read() in async context
- `002-socket_recv_send_async.phpt` - Tests socket_recv() and socket_send() in async context  
- `003-socket_sendto_recvfrom_async.phpt` - Tests UDP socket_sendto() and socket_recvfrom() in async context
- `004-socket_connect_async.phpt` - Tests socket_connect() with multiple concurrent connections
- `005-socket_accept_multiple.phpt` - Tests socket_accept() with multiple concurrent clients
- `006-socket_hostname_resolution.phpt` - Tests hostname resolution in socket_connect()

## Coverage

These tests cover the following socket functions that have been refactored to use the new Async API:

- `socket_read()` - Uses `php_read_async()` internally
- `socket_recv()` - Uses `recv_async()` internally
- `socket_send()` - Uses `send_async()` internally  
- `socket_sendto()` - Uses `sendto_async()` internally
- `socket_recvfrom()` - Uses `recvfrom_async()` internally
- `socket_connect()` - Uses `connect_async()` internally
- `socket_accept()` - Uses async socket operations

## Async API Integration

All tests verify that:

1. Socket operations work correctly in async coroutine contexts
2. Multiple coroutines can perform socket operations concurrently
3. Non-blocking socket behavior is properly handled
4. The new `network_async_wait_socket()` API functions correctly
5. Exception handling and error states work as expected

## Running Tests

Run these tests using PHP's test runner:

```bash
php run-tests.php ext/async/tests/socket_ext/
```