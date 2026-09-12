# #286 — Windows: a cancelled console read writes into the freed buffer

**Status:** root cause established from libuv's source and reproduced under ASAN;
fix landed in `libuv_io_alloc_cb` / `io_pipe_read_cb` (a tty handle owns the read
buffer). No automated test — a CI runner has no console. This document is the
procedure that turns the defect red, and the only way to check the fix again.

## The defect

`uv_read_stop()` is not an ownership barrier for a tty in line mode.
`uv__tty_queue_read_line()` (`src/win/tty.c`) calls the allocator on the loop
thread and hands the address to `uv_tty_line_read_thread`, started with
`QueueUserWorkItem`; nothing joins that thread. Cancellation is not a stop but an
injected keystroke: `uv__cancel_read_console()` writes a `VK_RETURN` record into
the console input, so `ReadConsoleW` returns **successfully**, with one or two
characters, and the worker writes them through `uv_utf16_to_wtf8` into the buffer
the allocator gave it. libuv then drops the result, because the request is marked
`UV_HANDLE_CANCELLATION_PENDING` — `read_cb` is never called for a cancelled line
read, which is why a rendezvous on the read completion cannot work.

While the reactor handed libuv `req->base.buf`, that address was the PHP stream's
`readbuf`, freed by `php_stream_free()` as soon as the cancelled coroutine let the
stream go.

Not exposed, and why:

- **Pipe** — `uv__pipe_read_data()` (`src/win/pipe.c`) calls the allocator on the
  loop thread once the zero-byte `ReadFile` has signalled, and reads into it in
  the same callback.
- **TCP** — `uv__tcp_queue_read()` always arms a zero-byte read in 1.49 and 1.51;
  `uv__process_tcp_read_req()` allocates inside the loop-thread `WSARecv` loop.
- **Tty in raw mode** — `uv__tty_queue_read_raw()` registers a wait and allocates
  in the loop thread; no worker sees the address.

## Why no ASAN run had ever caught it

`E:\php\deps\lib\libuv.lib` is a prebuilt static library and is **not** compiled
with `/fsanitize=address`. ASAN reports a bad access only from instrumented code,
and the write happens inside libuv, in a hand-written loop rather than an
intercepted `memcpy`. Every "clean under Windows ASAN" claim about a write from a
libuv worker thread — this defect included — was therefore vacuous. Rebuild libuv
with the sanitizer before believing such a run.

## Reproducing it

1. Get a libuv source tree matching what the build links (the version in
   `deps/include/libuv/uv/version.h`; the paths below are 1.51, whose `tty.c`,
   `pipe.c` and `tcp.c` are the same as 1.49 on these routes).

2. Patch `src/win/tty.c`, in `uv_tty_line_read_thread`, between the `ReadConsoleW`
   call and the `uv_utf16_to_wtf8` that follows it:

   ```c
   Sleep(200);   /* widen the race: let the free happen first */
   ```

   The delay is the whole trick. Without it the worker wins by about a
   millisecond and the write lands in memory that is still allocated, so the run
   is clean whatever the code does. Load on the machine does not help, because it
   slows both sides of the race equally.

3. Build it with the sanitizer and a matching runtime, and install it over the
   prebuilt one (keep a copy of the original library and headers):

   ```
   cmake -B build -G Ninja -DCMAKE_BUILD_TYPE=Release -DBUILD_TESTING=OFF \
         -DLIBUV_BUILD_SHARED=OFF -DCMAKE_MSVC_RUNTIME_LIBRARY=MultiThreadedDLL \
         -DCMAKE_C_FLAGS="/MD /Zi /fsanitize=address"
   cmake --build build
   copy build\libuv.lib E:\php\deps\lib\libuv.lib
   ```

4. Build php-src with `--enable-sanitizer --enable-zts --enable-async` and run
   the script below **in a real console window** — a test runner's stdin is a
   pipe, and `fopen('CONIN$')` answers `false` even from a console, so the script
   has to be started so that it owns a console of its own (`Start-Process`, or
   `start` from `cmd`). Redirect stdout to a file if the output is in the way;
   stdin stays the console.

   ```php
   <?php
   $h = fopen('php://stdin', 'r');
   $reader = async\spawn(static fn() => fread($h, 4096));
   async\spawn(static function () use ($reader, $h) {
       async\delay(50);
       $reader->cancel();
       fclose($h);
       $junk = [];
       for ($k = 0; $k < 300; $k++) { $junk[] = str_repeat('x', 8192); }
   });
   try { async\await($reader); } catch (Throwable $e) { }
   ```

   Run it with `USE_ZEND_ALLOC=0`, so that the stream buffer comes from the
   system allocator and ASAN can see the free.

**One round per process.** `uv__read_console_status` is a single global for the
whole process, not per handle, so two console workers alive at once make a later
cancellation see `COMPLETED` and skip the injection; with `Sleep(200)` in place
the second round then blocks forever. That is an artefact of the probe meeting a
libuv limitation, not of the reactor.

## What red and green look like

Without the fix:

```
==17108==ERROR: AddressSanitizer: heap-use-after-free on address 0x1264f0cab500
WRITE of size 1 at 0x1264f0cab500 thread T-1
    #0 uv_utf16_to_wtf8 src\idna.c:504
    #1 uv_tty_line_read_thread src\win\tty.c:576
freed by thread T0 here:
    #3 php_stream_free main\streams\streams.c:473
    #4 zif_fclose ext\standard\file.c:779
```

With it, the same run prints nothing: the worker writes into `io->tty_read_buf`,
which the handle keeps until libuv's close callback, and the completion has
already copied out of it whatever the request asked for.
