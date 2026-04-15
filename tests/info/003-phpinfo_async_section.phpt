--TEST--
Async: phpinfo() output contains the async module section
--FILE--
<?php

// Covers async.c:1536-1546 — PHP_MINFO_FUNCTION(async) which dumps the
// module name, version, support flag and reactor name to phpinfo().

ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();

var_dump(str_contains($info, 'TrueAsync') || str_contains($info, 'true_async'));
var_dump(str_contains($info, 'LibUv Reactor'));
var_dump(str_contains($info, 'Enabled'));

echo "ok\n";

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
ok
