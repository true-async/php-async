--TEST--
Fiber failed construction should not leak main scope
--FILE--
<?php
try {
    new Fiber();
} catch (Throwable) {}
echo "done\n";
?>
--EXPECT--
done
