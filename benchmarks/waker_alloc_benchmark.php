<?php
/**
 * Benchmark: Waker inline storage optimization
 *
 * Measures the impact of inline trigger/callback allocation inside Waker.
 * The hot path: each await/await_all creates a Waker with triggers + callbacks.
 * With inline storage, the first 2 triggers and 2 callbacks are embedded in the
 * Waker struct itself, avoiding separate heap allocations.
 *
 * Test cases:
 *   1. await(spawn(...))           — 1 event, 1 callback per waker (best case)
 *   2. await_all([spawn(), spawn()]) — 2 events, 2 callbacks per waker (sweet spot)
 *   3. Channel send/recv            — frequent waker creation with 1 event each
 */

ini_set('memory_limit', '512M');

use function Async\spawn;
use function Async\await;
use function Async\await_all;
use Async\Channel;

const WARMUP_ITERATIONS = 1000;
const BENCH_ITERATIONS  = 50000;
const CHANNEL_MESSAGES  = 100000;

function formatNumber(float $value, int $decimals = 2): string {
    return number_format($value, $decimals);
}

// ── Benchmark 1: Single await ──────────────────────────────────────────────
// Each iteration: spawn coroutine → await → waker(1 trigger, 1 callback)
function bench_single_await(int $iterations): float {
    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        await(spawn(function () {}));
    }

    return (hrtime(true) - $start) / 1e9;
}

// ── Benchmark 2: await_all with 2 coroutines ────────────────────────────────
// Each iteration: 2 spawns → await_all → waker(2 triggers, 2 callbacks)
function bench_await_all_two(int $iterations): float {
    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        await_all([
            spawn(function () {}),
            spawn(function () {}),
        ]);
    }

    return (hrtime(true) - $start) / 1e9;
}

// ── Benchmark 3: Channel ping-pong ─────────────────────────────────────────
// Producer/consumer: each send/recv creates waker with 1 trigger + 1 callback
function bench_channel(int $messages): float {
    $channel = new Channel(1);

    $start = hrtime(true);

    $producer = spawn(function () use ($channel, $messages) {
        for ($i = 0; $i < $messages; $i++) {
            $channel->send($i);
        }
        $channel->close();
    });

    $consumer = spawn(function () use ($channel) {
        while (($value = $channel->recv()) !== null) {
            // consume
        }
    });

    await_all([$producer, $consumer]);

    return (hrtime(true) - $start) / 1e9;
}

// ── Run ────────────────────────────────────────────────────────────────────

echo "=== Waker Inline Storage Benchmark ===\n\n";
echo "Iterations: " . BENCH_ITERATIONS . "\n";
echo "Channel messages: " . CHANNEL_MESSAGES . "\n\n";

// Warmup
echo "Warming up...\n";
bench_single_await(WARMUP_ITERATIONS);
bench_await_all_two(WARMUP_ITERATIONS);
bench_channel(WARMUP_ITERATIONS);

$memory_before = memory_get_usage(true);

// ── Single await ───────────────────────────────────────────────────────────
echo "\n[1] Single await (1 trigger, 1 callback per waker)\n";
$time = bench_single_await(BENCH_ITERATIONS);
echo "    Time:      " . formatNumber($time, 4) . " s\n";
echo "    Ops/sec:   " . formatNumber(BENCH_ITERATIONS / $time, 0) . "\n";
echo "    Per op:    " . formatNumber($time / BENCH_ITERATIONS * 1e6) . " μs\n";

// ── await_all x2 ────────────────────────────────────────────────────────────
echo "\n[2] await_all x2 (2 triggers, 2 callbacks per waker)\n";
$time = bench_await_all_two(BENCH_ITERATIONS);
echo "    Time:      " . formatNumber($time, 4) . " s\n";
echo "    Ops/sec:   " . formatNumber(BENCH_ITERATIONS / $time, 0) . "\n";
echo "    Per op:    " . formatNumber($time / BENCH_ITERATIONS * 1e6) . " μs\n";

// ── Channel ────────────────────────────────────────────────────────────────
echo "\n[3] Channel send/recv (" . CHANNEL_MESSAGES . " messages)\n";
$time = bench_channel(CHANNEL_MESSAGES);
echo "    Time:      " . formatNumber($time, 4) . " s\n";
echo "    Msgs/sec:  " . formatNumber(CHANNEL_MESSAGES / $time, 0) . "\n";
echo "    Per msg:   " . formatNumber($time / CHANNEL_MESSAGES * 1e6) . " μs\n";

$memory_after = memory_get_usage(true);
$memory_peak  = memory_get_peak_usage(true);

echo "\nMemory:\n";
echo "    Used:  " . formatNumber(($memory_after - $memory_before) / 1024) . " KB\n";
echo "    Peak:  " . formatNumber($memory_peak / 1024 / 1024) . " MB\n";
echo "\nDone.\n";
