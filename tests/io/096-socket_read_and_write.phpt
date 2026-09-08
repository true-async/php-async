--TEST--
Reading and writing one socket from two coroutines at the same time
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;

require __DIR__ . '/../stream/stream_helper.php';

echo "Start\n";

/* The peer answers only what it has received, so the reader is released by the
 * writer: a lock over the whole handle would hold the writer behind the reader
 * and neither would ever run. */
[$near, $far] = create_socket_pair();

$peer = spawn(function () use ($far) {
    $request = fread($far, 100);
    fwrite($far, "reply to $request");
});

$reader = spawn(fn() => fgets($near));

$writer = spawn(function () use ($near) {
    delay(20);
    return fwrite($near, "request\n");
});

$answer = await($reader);
$written = await($writer);
await($peer);

printf("written: %d\n", $written);
printf("answer: %s", $answer);

fclose($near);
fclose($far);
echo "End\n";

?>
--EXPECT--
Start
written: 8
answer: reply to request
End
