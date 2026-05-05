#!/usr/bin/env bash
# Coverage gate for local_fastpix.
#
# Runs the PHPUnit test suite under pcov, emits a clover-format coverage
# report at build/coverage.xml, then invokes tools/coverage_gate.php to
# enforce the per-class architecture targets:
#
#   gateway              95%
#   jwt_signing_service  95%
#   verifier             90%
#   projector            90%
#   all other classes    85%
#
# Exits 0 if every class meets its target. Exits 1 with a remediation
# report listing every shortfall otherwise.
#
# Usage from the plugin root:
#   bash tools/coverage.sh
#
# Or in CI: see .github/workflows/moodle-plugin-ci.yml.

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
BUILD_DIR="${PLUGIN_DIR}/build"
CLOVER_PATH="${BUILD_DIR}/coverage.xml"

mkdir -p "${BUILD_DIR}"

# In CI, moodle-plugin-ci sets up Moodle in the runner workspace and the
# plugin is symlinked under local/fastpix; the moodle wwwroot root is then
# the parent of $PLUGIN_DIR. In local dev (this repo), $PLUGIN_DIR is
# already inside a Moodle tree. Either way, vendor/bin/phpunit lives at
# the moodle root.
MOODLE_ROOT="$(cd "${PLUGIN_DIR}/../.." && pwd -P)"

if [[ ! -x "${MOODLE_ROOT}/vendor/bin/phpunit" ]]; then
    echo "coverage.sh: vendor/bin/phpunit not found at ${MOODLE_ROOT}" >&2
    echo "Run from a properly bootstrapped Moodle install with phpunit configured." >&2
    exit 2
fi

echo "coverage.sh: running phpunit with coverage..."
# pcov.enabled defaults to 0 on many builds (including the moodle-docker
# webserver image); pass it as a CLI ini override so we don't need to
# touch the php.ini in the runner.
(
    cd "${MOODLE_ROOT}"
    php -d pcov.enabled=1 vendor/bin/phpunit \
        --testsuite=local_fastpix_testsuite \
        --coverage-clover="${CLOVER_PATH}" 2>&1 \
        | tail -20
)

if [[ ! -s "${CLOVER_PATH}" ]]; then
    echo "coverage.sh: clover report not generated at ${CLOVER_PATH}" >&2
    exit 1
fi

echo "coverage.sh: enforcing per-class targets..."
php "${PLUGIN_DIR}/tools/coverage_gate.php" "${CLOVER_PATH}"
