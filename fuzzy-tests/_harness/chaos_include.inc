<?php
// Test include file for fuzzy-tests/include/ chaos features.
// Declares ONE function — every require_once after the first must be a
// no-op; under random scheduling N concurrent coroutines must agree on
// the single declaration without redeclare-error.

if (!function_exists('chaos_test_included_function')) {
    function chaos_test_included_function(): string {
        return 'included_ok';
    }
}
