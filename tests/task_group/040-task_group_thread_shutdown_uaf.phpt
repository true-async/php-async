--TEST--
TaskGroup: owned scope UAF on worker-thread shutdown (regression)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--DESCRIPTION--
Regression for UAF in task_group_dtor_object: when a worker thread exits,
php_request_shutdown -> zend_call_destructors runs TaskGroup's dtor, which
used to dereference an already-freed owned child scope because the scope's
+1 ref taken by TaskGroup was consumed by the parent scope's cascade dispose.

The fix makes a scope without a Zend object (scope_object == NULL) refuse
disposal until its owner explicitly sets ZEND_ASYNC_SCOPE_F_CLOSED. TaskGroup
now sets CLOSED in its dtor before releasing.
--FILE--
<?php

use Async\ThreadChannel;
use Async\TaskGroup;
use function Async\spawn_thread;
use function Async\spawn;
use function Async\await_all;

$jobs = new ThreadChannel(capacity: 16);

$threads = [];
for ($i = 0; $i < 4; $i++) {
    $threads[] = spawn_thread(
        function() use ($jobs) {
            $group = new TaskGroup(concurrency: 4);
            try {
                while (true) {
                    $payload = $jobs->recv();
                    $group->spawn(function() use ($payload) {
                        // no-op job
                        return $payload;
                    });
                }
            } catch (\Async\ThreadChannelException) {
                $group->seal();
                $group->awaitCompletion();
            }
        }
    );
}

spawn(function() use ($jobs) {
    for ($id = 1; $id <= 50; $id++) {
        $jobs->send($id);
    }
    $jobs->close();
});

await_all($threads);
echo "ok\n";
?>
--EXPECT--
ok
