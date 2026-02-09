--TEST--
tmpfile() write and read in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$coroutine = spawn(function() {
    $fp = tmpfile();
    if ($fp === false) {
        echo "tmpfile failed\n";
        return "fail";
    }

    fwrite($fp, "temporary data");
    rewind($fp);
    $data = fread($fp, 1024);
    echo "Data: '$data'\n";

    // Write more, rewind, read all
    fwrite($fp, " + extra");
    rewind($fp);
    $all = stream_get_contents($fp);
    echo "All: '$all'\n";

    fclose($fp);
    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";
echo "End\n";

?>
--EXPECT--
Start
Data: 'temporary data'
All: 'temporary data + extra'
Result: done
End
