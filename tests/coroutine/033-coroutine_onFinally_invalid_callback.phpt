--TEST--
Coroutine onFinally with invalid callback parameters
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\current_coroutine;

echo "start\n";

$invalid_finally_coroutine = spawn(function() {
    echo "invalid finally coroutine started\n";
    
    $coroutine = \Async\current_coroutine();
    
    // Test invalid callback types
    try {
        $coroutine->onFinally("not_a_callable");
        echo "should not accept string as callback\n";
    } catch (\TypeError $e) {
        echo "caught TypeError for string: " . $e->getMessage() . "\n";
    } catch (Throwable $e) {
        echo "unexpected for string: " . get_class($e) . "\n";
    }
    
    try {
        $coroutine->onFinally(123);
        echo "should not accept integer as callback\n";
    } catch (\TypeError $e) {
        echo "caught TypeError for integer: " . $e->getMessage() . "\n";
    } catch (Throwable $e) {
        echo "unexpected for integer: " . get_class($e) . "\n";
    }
    
    try {
        $coroutine->onFinally(null);
        echo "should not accept null as callback\n";
    } catch (\TypeError $e) {
        echo "caught TypeError for null: " . $e->getMessage() . "\n";
    } catch (Throwable $e) {
        echo "unexpected for null: " . get_class($e) . "\n";
    }
    
    return "invalid_finally_result";
});

suspend();

$result = $invalid_finally_coroutine->getResult();
echo "invalid finally result: $result\n";

echo "end\n";

?>
--EXPECTF--
start
invalid finally coroutine started
caught TypeError for string: %s
caught TypeError for integer: %s
caught TypeError for null: %s
invalid finally result: invalid_finally_result
end