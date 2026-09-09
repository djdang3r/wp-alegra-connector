#!/usr/bin/env bash
# smoke-test.sh — Quick gate: PHP syntax + autoloader reachability.
#
# Default: runs against the current working tree.
# With $1=path-to-zip: extracts the zip to a temp dir and runs against that.
#
# Exits 0 on success, non-zero on any failure. Designed to be safe to run
# repeatedly and quickly (<3s).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if ! command -v php >/dev/null 2>&1; then
    echo "error: php not found in PATH" >&2
    exit 2
fi

if [[ -n "${1:-}" ]]; then
    # ---- ZIP smoke-test mode ----
    ZIP="$1"
    if [[ ! -f "$ZIP" ]]; then
        echo "error: zip not found: $ZIP" >&2
        exit 2
    fi

    STAGING="$(mktemp -d -t alegra-smoke-XXXXXX)"
    trap 'rm -rf "$STAGING"' EXIT

    echo "--- Extracting $ZIP ---"
    command -v unzip >/dev/null 2>&1 || { echo "error: unzip not found" >&2; exit 3; }
    unzip -q "$ZIP" -d "$STAGING"

    # The ZIP always has a top-level alegra-connector/ directory
    if [[ ! -d "$STAGING/alegra-connector" ]]; then
        echo "error: extracted ZIP has no alegra-connector/ top dir" >&2
        exit 4
    fi

    export ALEGRA_PLUGIN_ROOT="$STAGING/alegra-connector"

    # Also run a PHP -l pass over every PHP in the extracted ZIP
    echo "--- Syntax-checking every .php in the ZIP ---"
    syntax_errors=0
    while IFS= read -r -d '' phpfile; do
        if ! php -l "$phpfile" >/dev/null 2>&1; then
            echo "  SYNTAX ERROR: $phpfile"
            php -l "$phpfile"
            syntax_errors=$((syntax_errors + 1))
        fi
    done < <(find "$STAGING/alegra-connector" -type f -name '*.php' -print0)
    if [[ $syntax_errors -gt 0 ]]; then
        echo "FAIL: $syntax_errors PHP file(s) have syntax errors" >&2
        exit 5
    fi
    echo "  All PHP files syntax-clean."

    echo "--- Running autoloader smoke-test against extracted ZIP ---"
else
    # ---- Working-tree smoke-test mode (default) ----
    cd "$REPO_ROOT"
    export ALEGRA_PLUGIN_ROOT="$REPO_ROOT"
fi

php "$REPO_ROOT/scripts/smoke-load.php"
status=$?

if [[ $status -eq 0 ]]; then
    echo "SMOKE OK"
else
    echo "SMOKE FAILED (exit $status)" >&2
fi
exit $status
