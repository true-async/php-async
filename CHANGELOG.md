# Changelog

All notable changes to the Async extension for PHP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - TBD

### Added
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

### Changed
- **LibUV requirement increased to ≥ 1.44.0** - Requires libuv version 1.44.0 or later to ensure proper UV_RUN_ONCE behavior and prevent busy loop issues that could cause high CPU usage


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