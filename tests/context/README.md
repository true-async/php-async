# Context Tests

This directory contains tests for the Context API functionality.

## Test Coverage

### 001-context_basic.phpt
- Basic Context creation and operations
- String key storage and retrieval
- Object key storage and retrieval
- has/hasLocal methods
- unset method
- Replace parameter in set method

### 002-context_inheritance.phpt
- Context inheritance through coroutines
- Parent-child context relationship
- Local vs inherited values
- Nested coroutine context propagation

### 003-coroutine_getContext.phpt
- Coroutine::getContext() method
- Context retrieval from coroutines
- Null context handling

### 004-context_error_handling.phpt
- Invalid key type error handling
- Error recovery after exceptions
- Type validation for all methods

### 005-context_object_keys.phpt
- Object keys functionality
- Different object types as keys
- Object identity vs equality
- Object key unset operations

## Expected Behavior

Context should provide:
- Thread-local storage for coroutines
- Key-value storage with string and object keys
- Parent-child inheritance through coroutine hierarchy
- Type safety and error handling
- Object lifecycle management for object keys