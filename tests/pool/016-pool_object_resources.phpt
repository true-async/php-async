--TEST--
Pool: object resources
--XFAIL--
Memory leak with object resources needs investigation
--FILE--
<?php

use Async\Pool;

class Connection {
    public int $id;
    public function __construct(int $id) {
        $this->id = $id;
        echo "Connection $id created\n";
    }
}

$pool = new Pool(
    factory: function() {
        static $c = 0;
        return new Connection(++$c);
    },
    destructor: function(Connection $conn) {
        echo "Connection {$conn->id} destroyed\n";
    },
    min: 2,
    max: 5
);

echo "Pool created\n";

$conn = $pool->tryAcquire();
echo "Acquired connection: {$conn->id}\n";
echo "Is Connection: " . ($conn instanceof Connection ? "yes" : "no") . "\n";

$pool->release($conn);
echo "Released\n";

$pool->close();
echo "Closed\n";

echo "Done\n";
?>
--EXPECTF--
Connection 1 created
Connection 2 created
Pool created
Acquired connection: 1
Is Connection: yes
Released
Connection %d destroyed
Connection %d destroyed
Closed
Done
