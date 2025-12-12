#!/bin/sh

BASE_PATH="$(cd "$(dirname "$0")/tests" && pwd)"
RUN_TESTS_PATH="$(cd "$(dirname "$0")/../../" && pwd)/run-tests.php"
PHP_EXECUTABLE="$(cd "$(dirname "$0")/../../" && pwd)/sapi/cli/php"
export VALGRIND_OPTS="--leak-check=full --track-origins=yes"
export MYSQL_TEST_HOST="127.0.0.1"
export MYSQL_TEST_PORT="3306"
export MYSQL_TEST_USER="root"
export MYSQL_TEST_PASSWD="root"
export MYSQL_TEST_DB="php_test"

if [ -z "$1" ]; then
    TEST_PATH="$BASE_PATH"
else
    TEST_PATH="$BASE_PATH/$1"
fi

"$PHP_EXECUTABLE" "$RUN_TESTS_PATH" --show-diff -p "$PHP_EXECUTABLE" "$TEST_PATH"
