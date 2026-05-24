Feature: I/O chaos — many coroutines writing one shared file handle

  Regression backstop for php_stdiop_write fixes (php-async #129, #133).
  Every coroutine writing the SAME file descriptor parks on its single
  shared async-IO event — a write completing for ANY coroutine wakes
  ALL of them. A spuriously-woken coroutine must re-suspend until its
  OWN request completed; before the fix it disposed the in-flight
  libuv request and writes were silently lost on macOS / Windows (and
  heap-corrupted on debug builds). `tests/io/083` pins a deterministic
  4-worker scenario; this feature adds the chaos cross-product
  (varying worker count, chunk size, iteration count, interleaved
  sleeps) so the bug surface is exercised under every ChaosNet
  interleaving.

  The cross-coroutine invariant is sum(io_fwrite_bytes_*) ==
  filesize(path): the kernel-observed file size must equal the sum of
  bytes every coroutine claims fwrite() accepted. The spurious-wakeup
  bug breaks exactly this — fwrite() returns a positive count for a
  request whose libuv write was disposed mid-flight, and the bytes
  never reach the file.

  Scenario: four writers, many small chunks
    # Mirrors the original tests/io/083 shape, but driven through the
    # chaos harness so ChaosNet picks the scheduling.
    Given a shared file "F"
      And a coroutine "W1"
      And a coroutine "W2"
      And a coroutine "W3"
      And a coroutine "W4"
     When coroutine "W1" writes 100 chunks of 48 bytes to shared file "F"
      And coroutine "W2" writes 100 chunks of 48 bytes to shared file "F"
      And coroutine "W3" writes 100 chunks of 48 bytes to shared file "F"
      And coroutine "W4" writes 100 chunks of 48 bytes to shared file "F"
     Then coroutine "W1" is completed
      And coroutine "W2" is completed
      And coroutine "W3" is completed
      And coroutine "W4" is completed
      And shared file "F" byte size equals counter "io_fwrite_bytes_W1" plus counter "io_fwrite_bytes_W2" plus counter "io_fwrite_bytes_W3" plus counter "io_fwrite_bytes_W4"
      And no orphan coroutines

  Scenario Outline: chunk size and iteration count varied
    # Small chunks → many fwrite() calls → many shared-event wakeups
    # → maximum chance to trip the spurious-wakeup race. Larger
    # chunks → fewer calls, longer in-flight windows → larger libuv
    # request lifetimes.
    Given a shared file "F"
      And a coroutine "W1"
      And a coroutine "W2"
      And a coroutine "W3"
     When coroutine "W1" writes <iters> chunks of <bytes> bytes to shared file "F"
      And coroutine "W2" writes <iters> chunks of <bytes> bytes to shared file "F"
      And coroutine "W3" writes <iters> chunks of <bytes> bytes to shared file "F"
     Then coroutine "W1" is completed
      And coroutine "W2" is completed
      And coroutine "W3" is completed
      And shared file "F" byte size equals counter "io_fwrite_bytes_W1" plus counter "io_fwrite_bytes_W2" plus counter "io_fwrite_bytes_W3"
      And no orphan coroutines

    Examples:
      | iters | bytes |
      | 200   | 16    |
      | 50    | 256   |
      | 20    | 4096  |

  Scenario: writers interleaved with sleeps to provoke spurious wakeups
    # The sleeps move writers' fwrite() calls into different reactor
    # ticks so each writer is more often the one parked when another
    # writer's libuv write completes — that completion wakes everyone
    # parked on the shared event. Under the pre-#129 bug a woken
    # writer would dispose its still-in-flight request here.
    Given a shared file "F"
      And a coroutine "W1"
      And a coroutine "W2"
      And a coroutine "W3"
      And a coroutine "W4"
     When coroutine "W1" writes 80 chunks of 32 bytes to shared file "F"
      And coroutine "W2" sleeps 2 ms
      And coroutine "W2" writes 80 chunks of 32 bytes to shared file "F"
      And coroutine "W3" sleeps 4 ms
      And coroutine "W3" writes 80 chunks of 32 bytes to shared file "F"
      And coroutine "W4" sleeps 1 ms
      And coroutine "W4" writes 80 chunks of 32 bytes to shared file "F"
     Then coroutine "W1" is completed
      And coroutine "W2" is completed
      And coroutine "W3" is completed
      And coroutine "W4" is completed
      And shared file "F" byte size equals counter "io_fwrite_bytes_W1" plus counter "io_fwrite_bytes_W2" plus counter "io_fwrite_bytes_W3" plus counter "io_fwrite_bytes_W4"
      And no orphan coroutines

  Scenario: explicit close after writers, then size assertion
    # A closer coroutine waits long enough for all writers to finish
    # before fclose(). Confirms the size invariant holds even when the
    # handle is closed inside a coroutine rather than at teardown.
    Given a shared file "F"
      And a coroutine "W1"
      And a coroutine "W2"
      And a coroutine "K"
     When coroutine "W1" writes 60 chunks of 64 bytes to shared file "F"
      And coroutine "W2" writes 60 chunks of 64 bytes to shared file "F"
      And coroutine "K" sleeps 100 ms
      And coroutine "K" closes shared file "F"
     Then coroutine "W1" is completed
      And coroutine "W2" is completed
      And coroutine "K" is completed
      And shared file "F" byte size equals counter "io_fwrite_bytes_W1" plus counter "io_fwrite_bytes_W2"
      And no orphan coroutines
