# cURL Async Integration — Known Issues & Architecture

This document covers important technical details about the async cURL integration,
including known libcurl bugs and the workarounds applied.

---

## Overview

The async cURL integration uses libcurl's `multi_socket` API combined with
the PAUSE/unpause pattern for non-blocking I/O:

- **File uploads** (`CURLFile`): `curl_mime_data_cb()` with a read callback that
  returns `CURL_READFUNC_PAUSE` while async file I/O is in progress.
- **File downloads** (`CURLOPT_FILE`): write callback returns `CURL_WRITEFUNC_PAUSE`
  while async file write is in progress.
- **User callbacks** (`CURLOPT_WRITEFUNCTION`): write callback pauses, spawns a
  high-priority coroutine to run the PHP callback, then unpauses.

After async I/O completes, the transfer is unpaused via `curl_easy_pause(CURLPAUSE_CONT)`
and driven forward with `curl_multi_socket_action(CURL_SOCKET_TIMEOUT)`.

---

## Minimum libcurl Version

**Recommended: libcurl >= 8.11.1** for fully async file upload support.

On older versions, file uploads (`CURLFile`) fall back to synchronous `read()`
inside the read callback. This is safe for local files but blocks the event loop
briefly during each read. Downloads and user write callbacks work correctly on
all versions.

---

## The PAUSE/unpause Bug (libcurl < 8.11.1)

### Symptom

Intermittent timeout on file uploads (~20% failure rate):
```
Operation timed out after 5000 milliseconds with 0 bytes received
```

### Root Cause

Multiple bugs in libcurl's PAUSE/unpause mechanism prevent the transfer from
being driven after `curl_easy_pause(CURLPAUSE_CONT)`:

1. **`timer_lastcall` / `last_expire_ts` optimization** — `Curl_update_timer()`
   skips the timer callback when the new expire timestamp matches the cached one.
   Fixed in [curl#15627](https://github.com/curl/curl/pull/15627) (8.11.1),
   but the fix was removed during intermediate refactors (present in 8.5.0,
   absent in 8.6–8.10, re-added in 8.11.1).

2. **`tempcount` guard on `cselect_bits`** — In `curl_easy_pause()`,
   `data->conn->cselect_bits` is only set when `data->state.tempcount == 0`.
   If any response data arrived while the transfer was paused, `tempcount > 0`
   and `cselect_bits` is never set. Without `cselect_bits`, the transfer is
   not processed even when timeouts fire correctly. Fixed in 8.11.1+ where
   `cselect_bits` was replaced with `data->state.select_bits` (always set,
   no `tempcount` guard).

3. **`CURLINFO_ACTIVESOCKET` unreliable during transfer** — Returns
   `CURL_SOCKET_BAD` (-1) in the `multi_socket` API because `lastconnect_id`
   is not set until the transfer completes. Cannot be used to drive the socket
   directly.

### Workarounds Tested (all insufficient for < 8.11.1)

| Approach | Result |
|---|---|
| `curl_multi_socket_action(CURL_SOCKET_TIMEOUT)` after unpause | ~80% pass |
| Manual `curl_timer_cb(multi, 0, NULL)` to force 0ms timer | ~94% pass |
| `CURLINFO_ACTIVESOCKET` + `CURL_CSELECT_IN\|OUT` | ~92% pass (socket sometimes BAD) |
| Track socket via `curl_socket_cb` + direct socket action | ~82–92% pass (socket removed from sockhash during pause) |
| `curl_multi_perform()` | ~74% pass (must not mix with multi_socket API) |

### Solution Applied

For libcurl < 8.11.1, the file upload read callback (`curl_async_read_cb`)
uses **synchronous `read()`** instead of the async PAUSE/unpause pattern.
This completely avoids the bug — no PAUSE means no broken unpause.

```c
#if LIBCURL_VERSION_NUM < 0x080B01
    // Synchronous read — safe for local files
    const ssize_t n = read(fd, buffer, requested);
#else
    // Async PAUSE/unpause pattern
    return CURL_READFUNC_PAUSE;
#endif
```

Compile-time check via `LIBCURL_VERSION_NUM`. Zero runtime overhead.
With libcurl >= 8.11.1, the async path is used and works 100% reliably.

---

## References

- [curl#15627](https://github.com/curl/curl/pull/15627) — Fix for `CURLMOPT_TIMERFUNCTION` not being called (merged Nov 2024, curl 8.11.1)
- [curl#5299](https://github.com/curl/curl/issues/5299) — `CURLINFO_ACTIVESOCKET` reliability issues
- libcurl `multi_socket` API: https://curl.se/libcurl/c/libcurl-multi.html
- libcurl pause/unpause: https://curl.se/libcurl/c/curl_easy_pause.html

---

## Files

- `ext/curl/curl_async.c` — Async cURL implementation (read/write callbacks, unpause logic)
- `ext/curl/curl_async.h` — Struct definitions and public API
- `ext/curl/interface.c` — `build_mime_structure_from_hash()` registers the async read callback
- `ext/curl/curl_private.h` — `mime_data_cb_arg_t` struct with async state
