#!/bin/sh

BASE_PATH="$(cd "$(dirname "$0")/tests" && pwd)"
RUN_TESTS_PATH="$(cd "$(dirname "$0")/../../" && pwd)/run-tests.php"
PHP_EXECUTABLE="$(cd "$(dirname "$0")/../../" && pwd)/sapi/cli/php"
export VALGRIND_OPTS="--leak-check=full --track-origins=yes"

if [ -z "$1" ]; then
    TEST_PATH="$BASE_PATH"
else
    TEST_PATH="$BASE_PATH/$1"
fi

"$PHP_EXECUTABLE" "$RUN_TESTS_PATH" --show-diff -m -p "$PHP_EXECUTABLE" "$TEST_PATH"
