--TEST--
root_context() - is the main Scope's context and is reachable through find()
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\root_context;
use function Async\current_context;

// root_context() used to live in a global of its own, outside the Scope tree, so find() -- which walks
// scope->parent_scope -- could never reach it. It is the main Scope's context.
root_context()->set('cfg', 'X');

echo "root is current at top level: ", var_export(root_context() === current_context(), true), "\n";
echo "plain coroutine:   ", var_export(await(spawn(fn() => current_context()->find('cfg'))), true), "\n";

$inherited = Async\Scope::inherit();
echo "Scope::inherit():  ", var_export(await($inherited->spawn(fn() => current_context()->find('cfg'))), true), "\n";

// A plain `new Scope()` is a detached root on purpose, so it does not inherit the context either --
// but root_context() still reaches it directly.
$detached = new Async\Scope();
echo "new Scope():       ", var_export(await($detached->spawn(fn() => current_context()->find('cfg'))), true), "\n";
echo "new Scope() direct: ", var_export(await($detached->spawn(fn() => root_context()->find('cfg'))), true), "\n";
?>
--EXPECT--
root is current at top level: true
plain coroutine:   'X'
Scope::inherit():  'X'
new Scope():       NULL
new Scope() direct: 'X'
