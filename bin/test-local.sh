#!/usr/bin/env bash
# Runs the PHPUnit suites against a local MySQL and a downloaded WordPress core.
# Requires: WP_PHPUNIT__TESTS_CONFIG pointing at a wp-tests-config.php (see README, Development).
set -euo pipefail
cd "$(dirname "$0")/.."
: "${WP_PHPUNIT__TESTS_CONFIG:?Set WP_PHPUNIT__TESTS_CONFIG to your wp-tests-config.php}"
exec vendor/bin/phpunit "$@"
