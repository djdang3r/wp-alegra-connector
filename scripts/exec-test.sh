#!/usr/bin/env bash
# exec-test.sh — Run the execution test harness (real plugin code + mocked Alegra API).
#
# One command. Exits 0 when every assertion passes, non-zero otherwise.
# Falls back to php:8.3-cli in Docker when php is not on PATH.
#
# Usage:  bash scripts/exec-test.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if ! command -v php >/dev/null 2>&1; then
    if command -v docker >/dev/null 2>&1; then
        echo "info: php not found in PATH; re-running inside php:8.3-cli (docker)..." >&2
        exec docker run --rm -v "$REPO_ROOT":/app -w /app php:8.3-cli bash scripts/exec-test.sh "$@"
    fi
    echo "error: php not found in PATH (and docker is unavailable)" >&2
    exit 2
fi

cd "$REPO_ROOT"
export ALEGRA_PLUGIN_ROOT="$REPO_ROOT"

php "$REPO_ROOT/scripts/exec-test.php"
status=$?

if [[ $status -eq 0 ]]; then
    echo "EXEC-TEST OK"
else
    echo "EXEC-TEST FAILED (exit $status)" >&2
fi
exit $status
