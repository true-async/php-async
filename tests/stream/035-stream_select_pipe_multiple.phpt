--TEST--
stream_select with multiple pipe streams in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$coroutine = spawn(function() {
    $procs = [];
    $pipes_list = [];

    // Spawn 3 child processes that write immediately and exit
    for ($i = 0; $i < 3; $i++) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open(
            PHP_BINARY . ' -r "echo \"msg-' . $i . '\";"',
            $descriptors,
            $pipes
        );

        fclose($pipes[0]);
        $procs[] = $proc;
        $pipes_list[] = $pipes;
    }

    // Read all messages using repeated stream_select
    $messages = [];
    $remaining = [$pipes_list[0][1], $pipes_list[1][1], $pipes_list[2][1]];

    while (count($remaining) > 0) {
        $read = $remaining;
        $write = null;
        $except = null;

        $result = stream_select($read, $write, $except, 5);

        if ($result === false) {
            echo "Select error\n";
            break;
        }

        foreach ($read as $pipe) {
            $data = stream_get_contents($pipe);
            if ($data !== '') {
                $messages[] = $data;
            }
            // Remove from remaining
            $key = array_search($pipe, $remaining, true);
            if ($key !== false) {
                unset($remaining[$key]);
                $remaining = array_values($remaining);
            }
        }
    }

    for ($i = 0; $i < 3; $i++) {
        fclose($pipes_list[$i][1]);
        fclose($pipes_list[$i][2]);
        proc_close($procs[$i]);
    }

    sort($messages);
    foreach ($messages as $msg) {
        echo "Got: '$msg'\n";
    }

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";
echo "End\n";

?>
--EXPECT--
Start
Got: 'msg-0'
Got: 'msg-1'
Got: 'msg-2'
Result: done
End
