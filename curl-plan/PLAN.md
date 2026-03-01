# cURL Async Integration — Implementation Plan

## Current State

### Implemented (async-aware)

| Component | Location | Pattern |
|-----------|----------|---------|
| `curl_exec()` | `curl_async.c:472` | `curl_async_perform()` — multi_socket + waker |
| `curl_multi_exec()` | `curl_async.c:860` | `curl_async_multi_perform()` |
| `curl_multi_select()` | `curl_async.c:877` | `curl_async_select()` — waker + timeout |
| Write `PHP_CURL_FILE` | `curl_async.c:1285` | PAUSE → async IO write → unpause |
| Write `PHP_CURL_USER` | `curl_async.c:1492` | PAUSE → spawn coroutine → unpause |
| CURLFile upload read | `curl_async.c:1018` | sync (curl<8.11.1) / async PAUSE (curl>=8.11.1) |

### Existing Tests (22 total)

- `001`–`010`: Basic exec, concurrency, multi, POST, errors, timeouts, large response, mixed, coroutines, multi_select
- `011`: CURLFile upload
- `012`–`013`: Write file (basic, large)
- `014`–`017`: Write user (basic, large, return value, async IO)
- `018`–`020`: Concurrent (file, user, mixed)
- `021`–`022`: JSON download (user, file)

---

## Phase 1: Error Handling in Existing Callbacks

### 1.1 `curl_async_write_file_complete` — exception parameter [DONE]

**File**: `curl_async.c:1245`

**Problem**: The `exception` parameter from the async event layer was ignored.
Only `req->exception` was checked. If the IO event itself fails (e.g., handle closed),
`exception != NULL` but the code would proceed to dereference `result` as a req.

**Fix applied**: Added `exception != NULL` check at the top, sets `pending_result = (size_t)-1`
and jumps to `finish:` label (which handles deferred/unpause).

### 1.2 `curl_async_file_read_complete` — error handling [DONE]

**File**: `curl_async.c:995`

**Problem**: The completion callback did NOT check for errors at all — blindly stored
the result as `state->req`. On error (`exception`, `req == NULL`, `req->transferred < 0`),
the next `curl_async_read_cb` call would either crash (NULL deref) or return 0 (EOF)
instead of `CURL_READFUNC_ABORT`.

**Fix applied**:
- [x] Added `bool error` field to `curl_async_read_state_t` in `curl_async.h`
- [x] In `curl_async_file_read_complete`: check `exception != NULL`, `result == NULL`,
      `req->exception != NULL`, `req->transferred < 0` → set `state->error = true`
- [x] In `curl_async_read_cb` (async path): check `state->error` → return `CURL_READFUNC_ABORT`
- [x] Wrapped `curl_async_io_callback_t` and `curl_async_file_read_complete` in
      `#if LIBCURL_VERSION_NUM >= 0x080B01` to avoid unused-function warning on old curl

**Test**: `024-upload_nonexistent_file.phpt` — verifies CURL_READFUNC_ABORT on open failure.

### 1.3 `curl_async_write_user_complete` — exception in callback [KNOWN BUG]

**File**: `curl_async.c:1377`

**Current behavior**: If the user callback throws an exception inside the
high-priority coroutine (`curl_async_write_user_entry`), the scheduler crashes
with assertion `fiber_context != NULL` in `fiber_switch_context_ex`.

**Root cause**: Exception propagation from internal coroutines is not handled
correctly in the scheduler. The exception object is lost — the user only sees
`CURLE_WRITE_ERROR` but not the original exception.

**Status**: Separate scheduler bug. Not fixable in curl_async.c alone.
Filed as known issue — needs scheduler fix first.

### 1.4 Async write to broken pipe/bad fd [KNOWN BUG]

**Discovered during testing**: Writing to a `stream_socket_pair` pipe where
the read end is closed causes SEGFAULT in async mode. The crash occurs before
the completion callback fires — likely in the async IO layer when it encounters
EPIPE and tries to propagate the error.

**Status**: Separate async IO layer bug. The `curl_async_write_file_complete`
error handling fix (1.1) is correct but doesn't help if the crash happens
before the callback is invoked.

---

## Phase 2: Async Header Callbacks

### 2.1 Header write `PHP_CURL_FILE` — async file write

**File**: `interface.c:869-870`

**Current code**:
```c
case PHP_CURL_FILE:
    return fwrite(data, size, nmemb, write_handler->fp);
```

**Problem**: Synchronous `fwrite()` blocks the event loop. Same problem we solved for
the body write callback.

**Solution**: Add async dispatch exactly like `curl_write` does:
```c
case PHP_CURL_FILE:
    if (ch->async_event != NULL) {
        return curl_async_write_header_file(data, size, nmemb, ch);
    }
    return fwrite(data, size, nmemb, write_handler->fp);
```

**Consideration**: Headers are small (typically < 1KB each). The blocking is minimal.
But for consistency and correctness, we should handle this async.

**Complication**: The write state is per-`curl_async_event_t` and currently shared
between body writes. Headers arrive interleaved with body data. We need either:
- (a) Separate `header_write_state` on `curl_async_event_t`, OR
- (b) Reuse `curl_async_write_file` with the header's stream/fp (different from body)

Option (b) won't work because `curl_async_write_file` reads from `ch->handlers.write`
(the body handler). We need a variant that reads from `ch->handlers.write_header`.

**Changes needed**:
- [ ] Add `curl_async_write_state_t *header_write_state` to `curl_async_event_t`
- [ ] Add `curl_async_write_header_file()` in `curl_async.c` (similar to `curl_async_write_file` but uses `write_header` handler)
- [ ] Add `curl_async_write_header_file_complete()` completion callback
- [ ] Dispatch from `curl_write_header()` in `interface.c`
- [ ] Update `curl_async_event_stop()` to clean up header_write_state
- [ ] Add test: download with headers saved to file, verify headers written correctly
- [ ] Add test: concurrent downloads with header file

### 2.2 Header write `PHP_CURL_USER` — async user callback

**File**: `interface.c:871-889`

**Current code**: Synchronous `zend_call_known_fcc()`.

**Solution**: Same pattern as `curl_async_write_user` — spawn high-priority coroutine:
```c
case PHP_CURL_USER:
    if (ch->async_event != NULL) {
        return curl_async_write_header_user(data, size, nmemb, ch);
    }
    // ... existing sync code ...
```

**Changes needed**:
- [ ] Add `curl_async_write_header_user()` in `curl_async.c`
- [ ] Add `curl_async_write_header_user_entry()` coroutine entry point
- [ ] Add `curl_async_write_header_user_complete()` completion callback
- [ ] Dispatch from `curl_write_header()` in `interface.c`
- [ ] Add test: header callback in async context
- [ ] Add test: header callback with slow operation (verify non-blocking)

---

## Phase 3: Async Read Callback (CURLOPT_READFUNCTION)

### 3.1 Read `PHP_CURL_DIRECT` — async file read

**File**: `interface.c:808-811`

**Current code**: `fread(data, size, nmemb, read_handler->fp)` — synchronous.

**Problem**: Blocks event loop during PUT/POST with large file bodies.
Unlike CURLFile (which uses `curl_mime_data_cb`), this path is for
`CURLOPT_INFILE` + `CURLOPT_READFUNCTION` (or default read handler).

**Solution**: PAUSE/unpause pattern with async IO, same as CURLFile read.
But this path uses `FILE*` from `read_handler->fp`, not a filename.
Need to get the fd from the FILE* or the stream.

**Changes needed**:
- [ ] Add `curl_async_read_state_t *read_state` to `curl_async_event_t`
  (or add a separate read state struct)
- [ ] Add `curl_async_read_direct()` — async file read for CURLOPT_INFILE
- [ ] Get async IO handle from the stream (like write_file does)
- [ ] PAUSE/unpause pattern with `CURL_READFUNC_PAUSE`
- [ ] Dispatch from `curl_read()` in `interface.c`
- [ ] Add test: PUT request with CURLOPT_INFILE in async context
- [ ] Add test: large file PUT

### 3.2 Read `PHP_CURL_USER` — async user callback

**File**: `interface.c:813-844`

**Current code**: Synchronous `zend_call_known_fcc()` call.

**Problem**: User read callback might do I/O (e.g., read from database, another
network request). Blocks the event loop.

**Solution**: Spawn high-priority coroutine, same pattern as write_user:
```c
case PHP_CURL_USER:
    if (ch->async_event != NULL) {
        return curl_async_read_user(data, size, nmemb, ch);
    }
    // ... existing sync code ...
```

**Complication**: Read callback returns data in `data` buffer (out parameter).
The coroutine must copy its result back to curl's buffer. Need to pass the
buffer pointer to the coroutine.

**Changes needed**:
- [ ] Add `curl_async_read_user()` in `curl_async.c`
- [ ] Add `curl_async_read_user_entry()` coroutine entry
- [ ] Add `curl_async_read_user_complete()` completion callback
- [ ] Handle buffer copy: coroutine returns string → copy to curl buffer on re-call
- [ ] Dispatch from `curl_read()` in `interface.c`
- [ ] Add test: user read callback in async context
- [ ] Add test: streaming POST with user read callback

---

## Phase 4: Async Informational Callbacks

These callbacks don't do I/O themselves, but user code inside them might.
They call `zend_call_known_fcc()` which can execute arbitrary PHP code.

### 4.1 Progress/Xferinfo callback

**File**: `interface.c:620-657` / `interface.c:661-698`

**Problem**: Called frequently during transfers. If user callback does any I/O
(logging to file, updating database), it blocks.

**Solution**: Spawn high-priority coroutine.

**Complication**: Progress callback returns int (0 = continue, non-0 = abort).
Need to wait for coroutine result before returning to curl.
But we can't PAUSE from a progress callback — curl only supports PAUSE from
read/write callbacks.

**Alternative**: Since we can't use PAUSE here, and progress callbacks are
supposed to be fast, we might accept sync execution. OR we can run the PHP
callback in a coroutine but block until it completes (micro-suspension).

**Decision needed**: Is it worth the complexity? Progress callbacks are called
from within `curl_multi_socket_action` context. Spawning a coroutine and
suspending would require re-entering the event loop.

**Changes needed**:
- [ ] Investigate if micro-suspension is possible from within socket_action context
- [ ] If yes: add `curl_async_progress()` with coroutine spawn + sync wait
- [ ] If no: document limitation, progress callbacks remain sync
- [ ] Add test: progress callback in async context (verify it works, even if sync)

### 4.2 Debug callback

**File**: `interface.c:903-943`

**Same analysis as progress**: Can't use PAUSE pattern. Debug callbacks are
informational only (return value ignored by curl). They're lightweight.

**Decision**: Keep synchronous. Low priority.

- [ ] Add test: debug callback in async context (verify no crash)

### 4.3 Fnmatch/Prereq/SSH hostkey callbacks

Very rarely used. Keep synchronous.

- [ ] Add basic test if easily testable

---

## Phase 5: Additional PAUSE/Unpause Considerations

### 5.1 `PHP_CURL_STDOUT` write mode — PHPWRITE in event loop

**File**: `interface.c:543-544`

**Current code**: `PHPWRITE(data, length)` — writes to PHP output buffer.

**Problem**: If stdout is a non-blocking pipe (common in async context),
`PHPWRITE` might need to buffer or could fail.

**Analysis**: `PHPWRITE` goes through PHP's output buffering layer which is
memory-based. It won't block. Not a problem.

**Decision**: No change needed.

### 5.2 `PHP_CURL_RETURN` write mode — smart_str append

**File**: `interface.c:551-554`

Memory operation only. No I/O. No change needed.

---

## Implementation Order

### Priority 1 — Error handling (low risk, high value)
1. Fix `curl_async_file_read_complete` error handling (1.2)
2. Fix `curl_async_write_file_complete` exception parameter (1.1)
3. Add error tests for all existing async paths

### Priority 2 — Header callbacks (medium risk, medium value)
4. `curl_async_write_header_file` (2.1)
5. `curl_async_write_header_user` (2.2)
6. Header tests

### Priority 3 — Read callbacks (medium risk, high value)
7. `curl_async_read_direct` (3.1)
8. `curl_async_read_user` (3.2)
9. Read tests

### Priority 4 — Informational callbacks (low priority)
10. Progress/xferinfo investigation (4.1)
11. Debug test (4.2)

---

## Test Plan Summary

### Error handling tests (Phase 1)
- [ ] `023-write_file_error.phpt` — write to invalid/closed fd
- [ ] `024-read_file_error.phpt` — upload file deleted mid-transfer
- [ ] `025-write_user_exception.phpt` — user callback throws exception

### Header callback tests (Phase 2)
- [ ] `026-header_file_basic.phpt` — headers saved to file (async)
- [ ] `027-header_user_basic.phpt` — header callback (async)
- [ ] `028-header_file_concurrent.phpt` — concurrent downloads with header file
- [ ] `029-header_user_concurrent.phpt` — concurrent header callbacks

### Read callback tests (Phase 3)
- [ ] `030-read_direct_basic.phpt` — PUT with CURLOPT_INFILE (async)
- [ ] `031-read_user_basic.phpt` — user read callback (async)
- [ ] `032-read_user_streaming.phpt` — streaming POST with read callback
- [ ] `033-read_direct_large.phpt` — large file PUT

### Informational callback tests (Phase 4)
- [ ] `034-progress_basic.phpt` — progress callback in async context
- [ ] `035-debug_basic.phpt` — debug callback in async context
