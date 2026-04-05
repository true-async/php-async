--TEST--
DNS: concurrent resolves with cancellation — no UAF
--FILE--
<?php

use Async\AsyncCancellation;
use function Async\spawn;
use function Async\await;

/*
 * Multiple concurrent DNS resolves. Some get cancelled mid-flight.
 * Verifies that libuv DNS handles are freed correctly even when
 * dispose is called before the callback fires.
 */

$coros = [];

// 5 normal resolves
for ($i = 0; $i < 5; $i++) {
    $coros[] = spawn(function() use ($i) {
        $result = gethostbyname("localhost");
        return "resolve-$i: $result";
    });
}

// 5 resolves that will be cancelled
$cancelled = [];
for ($i = 0; $i < 5; $i++) {
    $cancelled[] = spawn(function() use ($i) {
        try {
            // Resolve a slow/external host — will be cancelled
            $result = gethostbyname("example.com");
            return "cancel-$i: resolved";
        } catch (AsyncCancellation $e) {
            return "cancel-$i: cancelled";
        }
    });
}

// Cancel them immediately
foreach ($cancelled as $c) {
    $c->cancel(new AsyncCancellation("test"));
}

// Await all
foreach ($coros as $c) {
    $result = await($c);
    echo "$result\n";
}

foreach ($cancelled as $c) {
    try {
        $result = await($c);
        echo "$result\n";
    } catch (AsyncCancellation $e) {
        echo "cancelled on await\n";
    }
}

echo "No crash\n";
?>
--EXPECTF--
resolve-0: %s
resolve-1: %s
resolve-2: %s
resolve-3: %s
resolve-4: %s
cancel%s
cancel%s
cancel%s
cancel%s
cancel%s
No crash
