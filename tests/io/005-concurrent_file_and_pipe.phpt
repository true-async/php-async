--TEST--
Concurrent file and pipe IO operations
--FILE--
<?php

require_once __DIR__ . '/../stream/stream_helper.php';

use function Async\spawn;
use function Async\await_all;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$sockets = create_socket_pair();
list($sock1, $sock2) = $sockets;

$output = [];

$file_worker = spawn(function() use ($tmpfile, &$output) {
    $fp = fopen($tmpfile, 'w');
    fwrite($fp, "file data from coroutine");
    fclose($fp);

    $fp = fopen($tmpfile, 'r');
    $data = fread($fp, 1024);
    fclose($fp);

    $output[] = "File: '$data'";
    return "file done";
});

$pipe_worker = spawn(function() use ($sock1, $sock2, &$output) {
    fwrite($sock1, "pipe data from coroutine");
    $data = fread($sock2, 1024);

    $output[] = "Pipe: '$data'";

    fclose($sock1);
    fclose($sock2);
    return "pipe done";
});

[$results, $exceptions] = await_all([$file_worker, $pipe_worker]);

sort($output);
foreach ($output as $line) {
    echo $line . "\n";
}

echo "Results: " . $results[0] . ", " . $results[1] . "\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
File: 'file data from coroutine'
Pipe: 'pipe data from coroutine'
Results: file done, pipe done
End
