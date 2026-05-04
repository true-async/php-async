--TEST--
spawn_thread() — STDIN/STDOUT/STDERR are defined and are stream resources in child thread
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

spawn(function() {
    $t = spawn_thread(static function (): array {
        return [
            'STDIN'  => defined('STDIN')  && is_resource(STDIN)  && get_resource_type(STDIN)  === 'stream',
            'STDOUT' => defined('STDOUT') && is_resource(STDOUT) && get_resource_type(STDOUT) === 'stream',
            'STDERR' => defined('STDERR') && is_resource(STDERR) && get_resource_type(STDERR) === 'stream',
        ];
    });
    var_dump(await($t));
});
?>
--EXPECT--
array(3) {
  ["STDIN"]=>
  bool(true)
  ["STDOUT"]=>
  bool(true)
  ["STDERR"]=>
  bool(true)
}
