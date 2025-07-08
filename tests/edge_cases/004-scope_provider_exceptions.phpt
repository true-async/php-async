--TEST--
ScopeProvider - exception handling with different exception types
--FILE--
<?php

use function Async\spawnWith;

echo "start\n";

// ScopeProvider with different exception types
class ThrowingScopeProvider implements \Async\ScopeProvider
{
    private $exceptionType;
    
    public function __construct(string $type)
    {
        $this->exceptionType = $type;
    }
    
    public function provideScope(): ?\Async\Scope
    {
        switch($this->exceptionType) {
            case 'runtime':
                throw new \RuntimeException("Runtime error in provider");
            case 'cancellation':
                throw new \Async\CancellationException("Cancelled in provider");
            case 'invalid_argument':
                throw new \InvalidArgumentException("Invalid argument in provider");
            case 'logic':
                throw new \LogicException("Logic error in provider");
            default:
                throw new \Exception("Generic error in provider");
        }
    }
}

// Test different exception types
$exceptionTypes = ['runtime', 'cancellation', 'invalid_argument', 'logic', 'generic'];

foreach ($exceptionTypes as $type) {
    try {
        $coroutine = spawnWith(new ThrowingScopeProvider($type), function() {
            return "test";
        });
        echo "ERROR: Should have thrown exception for {$type}\n";
    } catch (\Async\CancellationException $e) {
        echo "Caught CancellationException: " . $e->getMessage() . "\n";
    } catch (\RuntimeException $e) {
        echo "Caught RuntimeException: " . $e->getMessage() . "\n";
    } catch (\InvalidArgumentException $e) {
        echo "Caught InvalidArgumentException: " . $e->getMessage() . "\n";
    } catch (\LogicException $e) {
        echo "Caught LogicException: " . $e->getMessage() . "\n";
    } catch (\Exception $e) {
        echo "Caught Exception: " . $e->getMessage() . "\n";
    } catch (Throwable $e) {
        echo "Caught Throwable: " . get_class($e) . ": " . $e->getMessage() . "\n";
    }
}

echo "end\n";

?>
--EXPECT--
start
Caught RuntimeException: Runtime error in provider
Caught CancellationException: Cancelled in provider
Caught InvalidArgumentException: Invalid argument in provider
Caught LogicException: Logic error in provider
Caught Exception: Generic error in provider
end