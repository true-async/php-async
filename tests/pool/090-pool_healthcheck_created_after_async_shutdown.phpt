--TEST--
Pool: a pool with a healthcheck created after the async shutdown works without one
--FILE--
<?php

use Async\Pool;

ob_start(function ($buffer, $phase) {
    if ($phase & PHP_OUTPUT_HANDLER_FINAL) {
        try {
            $pool = new Pool(factory: fn () => 1, healthcheck: fn ($r) => true, min: 1, max: 1, healthcheckInterval: 10);
            $buffer .= "pool created, count=" . $pool->count() . "\n";
        } catch (\Throwable $e) {
            $buffer .= get_class($e) . ": " . $e->getMessage() . "\n";
        }
    }

    return $buffer;
});

echo "main\n";
?>
--EXPECT--
main
pool created, count=1
