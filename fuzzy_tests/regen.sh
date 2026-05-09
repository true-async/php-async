#!/usr/bin/env bash
# Regenerate fuzzy_tests/_generated/*.phpt from every .feature file.
set -euo pipefail
cd "$(dirname "$0")"
PHP_BIN="${PHP_BIN:-../../sapi/cli/php}"
[ -x "$PHP_BIN" ] || PHP_BIN=$(command -v php)
"$PHP_BIN" _harness/generate.php "$@"
"$PHP_BIN" _harness/coverage.php
