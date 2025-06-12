# Async cURL Tests

This directory contains tests for the async cURL functionality in the TrueAsync API.

## Test Overview

### Basic Functionality Tests
- `001-curl_exec_basic.phpt` - Basic async GET request
- `004-post_request.phpt` - POST request handling
- `009-curl_with_coroutines.phpt` - cURL exec with coroutine switching

### Multi-Handle Tests
- `003-multi_handle_async.phpt` - Multi-handle async operations
- `010-multi_select_async.phpt` - Multi select with async operations

### Concurrency Tests
- `002-concurrent_requests.phpt` - Multiple concurrent cURL requests
- `008-mixed_sync_async.phpt` - Mixed sync/async usage

### Error and Edge Case Tests
- `005-error_handling.phpt` - Connection errors, HTTP errors, 404s
- `006-timeout_handling.phpt` - Timeout handling and recovery
- `007-large_response.phpt` - Large response handling

## Test Server

All tests use a common synchronous HTTP server located in `../common/simple_http_server.php`. This server:

- Runs in a separate process to avoid interference with async tests
- Provides various endpoints for testing different scenarios:
  - `/` - Simple "Hello World" response
  - `/json` - JSON response
  - `/slow` - Slow response (500ms delay)
  - `/very-slow` - Very slow response (2s delay) 
  - `/error` - HTTP 500 error
  - `/large` - Large response (10KB)
  - `/post` - Accepts POST requests
  - `/headers` - Returns request headers
  - `/echo` - Echoes back the request

## Coverage Areas

### API Functions Tested
- `curl_exec()` - Single request execution
- `curl_multi_exec()` - Multi-handle execution
- `curl_multi_select()` - Async socket polling
- `curl_multi_add_handle()` / `curl_multi_remove_handle()` - Handle management
- `curl_getinfo()` - Request information
- `curl_error()` / `curl_errno()` - Error handling

### Async Integration
- Coroutine suspension/resumption during cURL operations
- Proper integration with TrueAsync event loop
- Socket-based I/O event handling
- Timer-based timeout support
- Concurrent request handling

### Error Scenarios
- Connection failures
- HTTP error responses
- Timeout conditions
- Large data transfers
- Mixed sync/async usage patterns

## Running Tests

```bash
# Run all cURL tests
php run-tests.php ext/async/tests/curl/

# Run specific test
php run-tests.php ext/async/tests/curl/curl_003_basic_get.phpt
```

## Implementation Notes

The async cURL implementation uses:
- Global CURL multi-handle for single requests (`curl_async_perform`)
- Per-handle multi management for multi-handle operations (`curl_async_select`)
- Socket callbacks for I/O event management
- Timer callbacks for timeout handling
- Integration with the libuv reactor for event processing