# Changelog

All notable changes to the Async extension for PHP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.0] - 2025-12-31

### Added
- **Fiber Support**: Full integration of PHP Fibers with TrueAsync coroutine system
  - `Fiber::suspend()` and `Fiber::resume()` work in async scheduler context
  - `Fiber::getCoroutine()` method to access fiber's coroutine
  - Fiber status methods (isStarted, isSuspended, isRunning, isTerminated)
  - Support for nested fibers and fiber-coroutine interactions
  - Comprehensive test coverage for all fiber scenarios
- **TrueAsync API**: Added `ZEND_ASYNC_SCHEDULER_LAUNCH()` macro for scheduler initialization
- **TrueAsync API**: Updated to version 0.8.0 with fiber support

### Fixed
- **Critical GC Bug**: Fixed garbage collection crash during coroutine cancellation when exception occurs in main coroutine while GC is running
- Fixed double free in `zend_fiber_object_destroy()`
- Fixed `stream_select()` for `timeout == NULL` case in async context
- Fixed fiber memory leaks and improved GC logic

### Changed
- **Deadlock Detection**: Replaced warnings with structured exception handling
  - Deadlock detection now throws `Async\DeadlockError` exception instead of multiple warnings
  - **Breaking Change**: Applications relying on deadlock warnings
  will need to be updated to catch `Async\DeadlockError` exceptions
- **Breaking Change: PHP Coding Standards Compliance** - Function names updated to follow official PHP naming conventions:
  - `spawnWith()` → `spawn_with()`
  - `awaitAnyOrFail()` → `await_any_or_fail()`
  - `awaitFirstSuccess()` → `await_first_success()`
  - `awaitAllOrFail()` → `await_all_or_fail()`
  - `awaitAll()` → `await_all()`
  - `awaitAnyOfOrFail()` → `await_any_of_or_fail()`
  - `awaitAnyOf()` → `await_any_of()`
  - `currentContext()` → `current_context()`
  - `coroutineContext()` → `coroutine_context()`
  - `currentCoroutine()` → `current_coroutine()`
  - `rootContext()` → `root_context()`
  - `getCoroutines()` → `get_coroutines()`
  - `gracefulShutdown()` → `graceful_shutdown()`
  - **Rationale**: Compliance with [PHP Coding Standards](https://github.com/php/policies/blob/main/coding-standards-and-naming.rst) - functions must use lowercase with underscores

## [0.4.0] - 2025-09-30

### Added
- **UDP socket stream support for TrueAsync**
- **SSL support for socket stream**
- **Poll Proxy**: New `zend_async_poll_proxy_t` structure for optimized file descriptor management
    - Efficient caching of event handlers to reduce EventLoop creation overhead
    - Poll proxy event aggregation and improved lifecycle management

### Fixed
- **Fixing `ref_count` logic for the `zend_async_event_callback_t` structure**:
    - The add/dispose methods correctly increment the counter
    - Memory leaks fixed
- Fixed await iterator logic for `awaitXXX` functions
- Fixed process waiting logic for UNIX-like systems

### Changed
- **Memory Optimization**: Enhanced memory allocation for async structures
    - Optimized waker trigger structures with improved memory layout
    - Enhanced memory management for poll proxy events
    - Better resource cleanup and lifecycle management
- **Event Loop Performance**: Major scheduler optimizations
    - **Automatic Event Cleanup**: Added automatic waker event cleanup when coroutines resume (see `ZEND_ASYNC_WAKER_CLEAN_EVENTS`)
    - Separate queue implementation for resumed coroutines to improve stability
    - Reduced unnecessary LibUV calls in scheduler tick processing
- **Socket Performance**:
    - Event handler caching for sockets to avoid constant EventLoop recreation
    - Optimized `network_async_accept_incoming` to try `accept()` before waiting
    - Enhanced stream_select functionality with event-driven architecture
    - Improved blocking operation handling with boolean return values
- **TrueAsync API Performance**: Optimized execution paths by replacing expensive `EG(exception)` checks with direct `bool` return values across all async functions
- Upgrade `LibUV` to version `1.45` due to a timer bug that causes the application to hang

## [0.3.0] - 2025-07-16

### Added
- Docker support with multi-stage build (Ubuntu 24.04, libuv 1.49, curl 8.10)
- PDO MySQL and MySQLi async support
- **TrueAsync API Extensions**: Enhanced async API with new object creation and coroutine grouping capabilities
    - Added `ZEND_ASYNC_NEW_GROUP()` API for creating CoroutineGroup objects for managing multiple coroutines
    - Added `ZEND_ASYNC_NEW_FUTURE_OBJ()` and `ZEND_ASYNC_NEW_CHANNEL_OBJ()` APIs for creating Zend objects from async primitives
    - Extended `zend_async_task_t` structure with `run` method for thread pool task execution
    - Enhanced `zend_async_scheduler_register()` function with new API function pointers
- **Multiple Callbacks Per Event Support**: Complete redesign of waker trigger system to support multiple callbacks on a single event
    - Modified `zend_async_waker_trigger_s` structure to use flexible array member with dynamic capacity
    - Added `waker_trigger_create()` and `waker_trigger_add_callback()` helper functions for efficient memory management
    - Implemented single-block memory allocation for better performance (trigger + callback array in one allocation)
    - Default capacity starts at 1 and doubles as needed (1 → 2 → 4 → 8...)
    - Fixed `coroutine_event_callback_dispose()` to remove only specific callbacks instead of entire events
    - **Breaking Change**: Events now persist until all associated callbacks are removed
- **Bailout Tests**: Added 15 tests covering memory exhaustion and stack overflow scenarios in async operations
- **Garbage Collection Support**: Implemented comprehensive GC handlers for async objects
    - Added `async_coroutine_object_gc()` function to track all ZVALs in coroutine structures
    - Added `async_scope_object_gc()` function to track ZVALs in scope structures  
    - Proper GC tracking for context HashTables (values and keys)
    - GC support for finally handlers, exception handlers, and function call parameters
    - GC tracking for waker events, internal context, and nested async structures
    - Prevents memory leaks in complex async applications with circular references
- **Key Order Preservation**: Added `preserveKeyOrder` parameter to async await functions
    - Added `preserve_key_order` parameter to `async_await_futures()` API function
    - Added `preserve_key_order` field to `async_await_context_t` structure
    - Enhanced `awaitAll()`, `awaitAllWithErrors()`, `awaitAnyOf()`, and `awaitAnyOfWithErrors()` functions with `preserveKeyOrder` parameter (defaults to `true`)
    - Allows controlling whether the original key order is maintained in result arrays

### Fixed
- Memory management improvements for long-running async applications
- Proper cleanup of coroutine and scope objects during garbage collection cycles
- **Async Iterator API**:
    - Fixed iterator state management to prevent memory leaks
- Fixed the `spawnWith()` function for interaction with the `ScopeProvider` and `SpawnStrategy` interface
- **Build System Fixes**:
    - Fixed macOS compilation error with missing field initializer in `uv_stdio_container_t` structure (`libuv_reactor.c:1956`)
    - Fixed Windows build script PowerShell syntax error (missing `shell: cmd` directive)
    - Fixed race condition issues in 10 async test files for deterministic test execution on all platforms

### Changed
- **Breaking Change: Function Renaming** - Major API reorganization for better consistency:
    - `awaitAllFailFirst()` → `awaitAllOrFail()`
    - `awaitAllWithErrors()` → `awaitAll()` 
    - `awaitAnyOfFailFirst()` → `awaitAnyOfOrFail()`
    - `awaitAnyOfWithErrors()` → `awaitAnyOf()`
- **Breaking Change: `awaitAll()` Return Format** - New `awaitAll()` (formerly `awaitAllWithErrors()`) now returns `[results, exceptions]` tuple:
    - First element `[0]` contains array of successful results
    - Second element `[1]` contains array of exceptions from failed coroutines
    - **Migration**: Update from `$results = awaitAll($coroutines)` to `[$results, $exceptions] = awaitAll($coroutines)`
- **LibUV requirement increased to ≥ 1.44.0** - Requires libuv version 1.44.0 or later to ensure proper UV_RUN_ONCE behavior and prevent busy loop issues that could cause high CPU usage
- **Async Iterator API**:
    - Proper handling of `REWIND`/`NEXT` states in a concurrent environment. 
      The iterator code now stops iteration in 
      coroutines if the iterator is in the process of changing its position.
    - Added functionality for proper handling of exceptions from `Zend iterators` (`\Iterator` and `generators`).
      An exception that occurs in the iterator can now be handled by the iterator's owner.


## [0.2.0] - 2025-07-01

### Added
- **Async-aware destructor handling (PHP Core)**: Implemented `async_shutdown_destructors()` function to properly 
  handle destructors that may suspend execution in async context
- **CompositeException**: New exception class for handling multiple exceptions that occur in finally handlers
    - Automatically collects multiple exceptions from `onFinally` handlers in both Scope and Coroutine
    - Provides `addException()` method to add exceptions to the composite
    - Provides `getExceptions()` method to retrieve all collected exceptions
    - Ensures all finally handlers are executed even when exceptions occur
- Complete implementation of `onFinally()` method for `Async\Scope` class
- Cross-thread trigger event API
- Priority support to async iterator system
- Coroutine priority support to TrueAsync API
- **Iterator API integration**: Added `zend_async_iterator_t` structure to TrueAsync API with `run()` and `run_in_coroutine()` methods
- `disposeAfterTimeout()` method for Scope
- `awaitAfterCancellation()` method for Scope
- Complete Scope API implementation
- `Async\protect()` function
- Signal handlers support (UNIX)
- Coroutine class with full lifecycle management
- `onFinally()` logic for Coroutine class

### Changed
- Enhanced ZEND_ASYNC_NEW_SCOPE API to create Scope without Zend object for internal use
- Refactored catch_or_cancel logic according to RFC scope behavior
- Refactored async_scheduler_coroutine_suspend to support non-zero exception context
- Optimized iterator module
- **Iterator structure refactoring**: Made `async_iterator_t` compatible with `zend_async_iterator_t` API by adding function pointer methods
- Improved exception handling and cancellation logic
- Enhanced Context API behavior for Scope

### Fixed
- Multiple fixes for Scope dispose operations
- Fixed scope_try_to_dispose logic
- Spawn tests fixes
- Build issues for Scope
- Context logic with NULL scope
- Iterator bugs and coroutine issues
- Stream tests and DNS tests
- ZEND_ASYNC_IS_OFF issues
- Race condition in process waiting (libuv)
- Memory cleanup for reactor shutdown

## [0.1.0] - 2025-06-25

### Added
- Future logic for coroutine class - coroutines can now behave like real Future objects
- Support for `ob_start` with coroutines
- Global main coroutine switch handlers API for context isolation
- Socket Listening API
- Support for `proc_open` in async context
- CURL async support and comprehensive tests
- Sleep functions (`usleep`, `sleep`) with async support
- Exec functions with async support
- Async DNS resolution support including IPv6
- Comprehensive DNS test suite (13 test files)
- Nanosecond support for async timer events
- Stream socket tests and functionality
- PHP_POLL2 implementation and tests
- `cancel_on_exit` option for `async_await_futures`
- Context API with HashTable optimization
- Coroutine Internal context support

### Changed
- Refactored sockets extension to use new TrueAsync API
- Refactored timeout object implementation with proper memory separation
- Refactored Internal Context API
- Refactored Zend DNS API
- Moved async extension memory initialization to RINIT
- Changed allocator to erealloc2
- Improved circular buffer behavior during relocation and resizing

### Fixed
- Multiple memory leaks
- DNS API bugs and errors
- Stream tests fixes
- CURL function fixes
- Socket extension test fixes
- Exception propagation bugs
- Poll2 logic fixes
- Double free issues in awaitAll
- Coroutine cancellation and completion logic
- Scheduler graceful shutdown logic

## [0.0.1] - Initial Release

### Added
- Initial TrueAsync extension architecture
- Basic coroutine support
- Event loop integration with libuv
- Core async/await functionality
- Basic suspend/resume operations
- Initial test framework
- Context switching mechanisms
- Basic scheduler implementation