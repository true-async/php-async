--TEST--
spawn_thread() - each thread has isolated globals
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

$GLOBALS['shared_test'] = "parent_value";

spawn(function() {
    $thread = spawn_thread(function() {
        // Global from parent should NOT be visible in child thread
        if (isset($GLOBALS['shared_test'])) {
            echo "ERROR: parent global leaked to child\n";
        } else {
            echo "globals isolated\n";
        }

        // Set a global in child — should not affect parent
        $GLOBALS['child_only'] = true;
    });

    await($thread);

    if (isset($GLOBALS['child_only'])) {
        echo "ERROR: child global leaked to parent\n";
    } else {
        echo "parent globals unaffected\n";
    }

    echo "parent value: " . $GLOBALS['shared_test'] . "\n";
});
?>
--EXPECT--
globals isolated
parent globals unaffected
parent value: parent_value
