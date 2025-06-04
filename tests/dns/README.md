# DNS Tests for Async Extension

This directory contains tests for the async DNS functionality that integrates standard PHP DNS functions with the async coroutine system.

## Test Coverage

### Core DNS Functions
- `gethostbyname()` - Resolve hostname to IP address
- `gethostbyaddr()` - Reverse DNS lookup (IP to hostname)  
- `gethostbynamel()` - Resolve hostname to list of IP addresses

### Test Categories

1. **001-003: Basic Functionality**
   - Basic resolution of common hostnames (localhost, 127.0.0.1)
   - IPv4 and IPv6 address handling
   - Invalid hostname/IP handling

2. **004: Concurrent Operations**
   - Multiple simultaneous DNS lookups
   - Coroutine coordination with `awaitAll()`
   - Performance with concurrent requests

3. **005: Error Handling**
   - Empty hostname handling
   - Long hostname protection (CVE-2015-0235)
   - Invalid IP address formats
   - Exception propagation

4. **006: Timeout Handling**
   - DNS operation timeouts
   - Async timeout integration
   - Fast vs slow lookup scenarios

5. **007: Sync vs Async Comparison**
   - Behavior compatibility between sync and async modes
   - Context switching verification
   - Result consistency

6. **008: IPv6 Support**
   - IPv6 address resolution
   - Different IPv6 formats
   - IPv6 availability detection

7. **009: Memory Stress Testing**
   - Large number of concurrent lookups
   - Memory usage monitoring
   - Resource cleanup verification

8. **010: Cancellation Handling**
   - DNS operation cancellation
   - Exception handling in async context
   - Nested coroutine scenarios

## Running Tests

From the PHP source root:

```bash
# Run all DNS tests
./sapi/cli/php run-tests.php ext/async/tests/dns/

# Run specific test
./sapi/cli/php run-tests.php ext/async/tests/dns/001-gethostbyname_basic.phpt

# Run with async extension loaded
./sapi/cli/php -d extension=async run-tests.php ext/async/tests/dns/
```

## Implementation Details

These tests verify that when the async extension is active (`ZEND_ASYNC_IS_ACTIVE`), the standard DNS functions automatically use the async implementations:

- `php_network_gethostbyaddr_async()` for `gethostbyaddr()`
- `php_network_gethostbyname_async()` for `gethostbyname()` (via `gethostbynamel()`)
- `php_network_getaddresses_async()` for internal address resolution

The async implementations use the new coroutine-based DNS API while maintaining full compatibility with the standard PHP DNS function interfaces.

## Platform Considerations

### Windows vs Unix Differences

**Windows (PHP_WIN32):**
- Uses Winsock2 DNS APIs
- Case-insensitive hostname resolution
- Different timeout behaviors (generally slower)
- NetBIOS name resolution support
- Some DNS functions may not be available (dns_get_record, etc.)
- Different maximum hostname length limits

**Unix-like Systems:**
- Uses system resolver (glibc, etc.)
- Case-sensitive hostname resolution
- /etc/hosts file integration
- Full DNS function support (dns_get_record, dns_check_record)
- IPv6 support more consistent
- Shorter default timeouts

### Test Organization

**Tests 001-010:** Cross-platform core functionality
**Test 011:** Platform detection and feature availability
**Test 012:** Windows-specific behaviors (--SKIPIF on non-Windows)
**Test 013:** Unix-specific behaviors (--SKIPIF on Windows)

### Skip Conditions

Tests automatically skip when:
- IPv6 not supported (`!defined('AF_INET6')`)
- DNS functions not available (`!function_exists('dns_get_record')`)
- Platform mismatch (Windows/Unix specific tests)
- Network features unavailable (IPv6 resolution not working)

## Expected Behavior

- **Outside async context**: Functions behave exactly as standard PHP
- **Inside coroutine**: Functions use async DNS resolution without blocking
- **Error handling**: Same error conditions and return values as standard functions
- **Performance**: Better concurrency when multiple DNS lookups are performed simultaneously
- **Platform compatibility**: Automatic adaptation to Windows/Unix differences