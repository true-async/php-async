--TEST--
A filter removing itself from its own callback inside a coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

/* The buffer lock is recursive for its owner, so the removal issued from the
 * callback gets past it; only the flag of the chain being applied refuses it. */
class SelfRemover extends php_user_filter
{
    public static $resource = null;

    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        if (self::$resource !== null) {
            $resource = self::$resource;
            self::$resource = null;
            var_dump(stream_filter_remove($resource));
        }

        return PSFS_PASS_ON;
    }
}

stream_filter_register('self-remover', 'SelfRemover');

await(spawn(function () {
    echo "write side:\n";
    $handle = fopen('php://memory', 'r+');
    SelfRemover::$resource = stream_filter_append($handle, 'self-remover', STREAM_FILTER_WRITE);
    fwrite($handle, 'hello');
    fwrite($handle, ' world');
    rewind($handle);
    echo stream_get_contents($handle), "\n";

    echo "read side:\n";
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, 'abcdefghij');
    rewind($handle);
    SelfRemover::$resource = stream_filter_append($handle, 'self-remover', STREAM_FILTER_READ);
    echo fread($handle, 4), '|', fread($handle, 6), "\n";

    echo "removal after the refusal:\n";
    $handle = fopen('php://memory', 'r+');
    $filter = stream_filter_append($handle, 'self-remover', STREAM_FILTER_WRITE);
    SelfRemover::$resource = $filter;
    fwrite($handle, 'abc');
    var_dump(stream_filter_remove($filter));
}));

?>
--EXPECTF--
write side:

Warning: stream_filter_remove(): Cannot remove filter while it is being applied in %s on line %d
bool(false)
hello world
read side:

Warning: stream_filter_remove(): Cannot remove filter while it is being applied in %s on line %d
bool(false)
abcd|efghij
removal after the refusal:

Warning: stream_filter_remove(): Cannot remove filter while it is being applied in %s on line %d
bool(false)
bool(true)
