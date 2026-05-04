--TEST--
ThreadPool: STDIN/STDOUT/STDERR are defined and are stream resources in pool workers
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php
use Async\ThreadPool;
use function Async\spawn;
use function Async\await_all_or_fail;

spawn(function() {
    $pool = new ThreadPool(2);
    $futures = [];
    for ($i = 0; $i < 2; $i++) {
        $futures[] = $pool->submit(static fn (): array => [
            'STDIN'  => defined('STDIN')  && is_resource(STDIN)  && get_resource_type(STDIN)  === 'stream',
            'STDOUT' => defined('STDOUT') && is_resource(STDOUT) && get_resource_type(STDOUT) === 'stream',
            'STDERR' => defined('STDERR') && is_resource(STDERR) && get_resource_type(STDERR) === 'stream',
        ]);
    }
    foreach (await_all_or_fail($futures) as $r) {
        var_dump($r);
    }
    $pool->close();
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
array(3) {
  ["STDIN"]=>
  bool(true)
  ["STDOUT"]=>
  bool(true)
  ["STDERR"]=>
  bool(true)
}
