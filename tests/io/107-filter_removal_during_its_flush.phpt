--TEST--
A removal issued from inside another removal's flush, in a coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

/* stream_filter_remove() holds the side's lock and marks the chain busy for its
 * flush; a second removal reached from that flush, through the filter itself or
 * through the wrapper's write, takes the lock recursively and is refused. */
class SelfRemover extends php_user_filter
{
    public static $resource = null;

    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        if ($closing && self::$resource !== null) {
            $resource = self::$resource;
            self::$resource = null;
            var_dump(stream_filter_remove($resource));
        }

        return PSFS_PASS_ON;
    }
}

class Hold extends php_user_filter
{
    private string $held = '';

    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->held .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        if ($closing && $this->held !== '') {
            stream_bucket_append($out, stream_bucket_new($this->stream, $this->held));
            $this->held = '';
            return PSFS_PASS_ON;
        }

        return PSFS_FEED_ME;
    }
}

class RemovingWrapper
{
    public $context;
    public static $resource = null;

    public function stream_open($path, $mode, $options, &$opened_path): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        if (self::$resource !== null) {
            $resource = self::$resource;
            self::$resource = null;
            var_dump(stream_filter_remove($resource));
        }

        return strlen($data);
    }
}

stream_filter_register('self-remover', 'SelfRemover');
stream_filter_register('hold', 'Hold');
stream_wrapper_register('rmw', 'RemovingWrapper');

await(spawn(function () {
    echo "closing flush:\n";
    $handle = fopen('php://memory', 'r+');
    $filter = stream_filter_append($handle, 'self-remover', STREAM_FILTER_WRITE);
    SelfRemover::$resource = $filter;
    var_dump(stream_filter_remove($filter));

    echo "wrapper write:\n";
    $handle = fopen('rmw://x', 'w');
    $filter = stream_filter_append($handle, 'hold', STREAM_FILTER_WRITE);
    fwrite($handle, 'abc');
    RemovingWrapper::$resource = $filter;
    var_dump(stream_filter_remove($filter));
}));

?>
--EXPECTF--
closing flush:

Warning: stream_filter_remove(): Cannot remove filter while it is being applied in %s on line %d
bool(false)
bool(true)
wrapper write:

Warning: stream_filter_remove(): Cannot remove filter while it is being applied in %s on line %d
bool(false)
bool(true)
