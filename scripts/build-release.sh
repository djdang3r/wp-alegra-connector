#!/usr/bin/env bash
# build-release.sh — Produce a deterministic release ZIP for Alegra Connector.
#
# Usage:   ./scripts/build-release.sh <version>   e.g.  ./scripts/build-release.sh 2.1.8
#
# Requirements: git, zip, sha256sum (or shasum -a 256 on macOS).
# Runs on: Ubuntu / Debian / Git Bash on Windows / WSL / macOS.
#
# Strategy:
#   1. Verify working tree is clean (refuse to build from dirty state).
#   2. Run smoke-test FIRST — if autoloader is broken, abort before ZIP.
#   3. git ls-files the tracked tree, filter out anything matching .distignore.
#   4. Copy each surviving file into a staging dir under alegraconnector/ prefix.
#   5. Zip the staging dir, compute SHA256, print summary.
#
# Why not `git archive` directly? Because .distignore is consulted by
# `git archive --worktree-attributes` only via .gitattributes export-ignore,
# not .distignore. This script uses .distignore explicitly so the build is
# reproducible from the file alone, not from .gitattributes state.

set -euo pipefail

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
    echo "usage: $0 <version>" >&2
    echo "  e.g. $0 2.1.8" >&2
    exit 2
fi

# Validate version looks like semver (X.Y.Z)
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?$ ]]; then
    echo "error: version '$VERSION' is not semver-like (X.Y.Z or X.Y.Z-suffix)" >&2
    exit 2
fi

# Resolve script dir + repo root (POSIX-portable, no GNU realpath)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

# Preflight: required binaries
for bin in git zip; do
    if ! command -v "$bin" >/dev/null 2>&1; then
        echo "error: required binary '$bin' not found in PATH" >&2
        exit 3
    fi
done
# SHA256: prefer sha256sum, fall back to shasum -a 256
if command -v sha256sum >/dev/null 2>&1; then
    SHA256_CMD=(sha256sum)
elif command -v shasum >/dev/null 2>&1; then
    SHA256_CMD=(shasum -a 256)
else
    echo "error: neither sha256sum nor shasum available" >&2
    exit 3
fi

# Preflight: working tree clean (so the ZIP matches a known commit).
# Escape hatch: set ALLOW_DIRTY=1 in CI / build environments where the
# working tree intentionally carries unrelated modifications.
if [[ -z "${ALLOW_DIRTY:-}" ]]; then
    if [[ -n "$(git status --porcelain 2>/dev/null)" ]]; then
        echo "error: working tree is not clean. Commit or stash before building." >&2
        echo "  hint: git status" >&2
        echo "  escape: ALLOW_DIRTY=1 $0 $VERSION" >&2
        exit 4
    fi
fi

# Preflight: HEAD commit matches the version we think we are building
HEAD_DESC="$(git describe --tags --always 2>/dev/null || git rev-parse --short HEAD)"
echo "Building from: $HEAD_DESC (version $VERSION)"

# Preflight: run smoke-test FIRST — if autoloader is broken, refuse to ship.
# Escape hatch: set SKIP_SMOKE=1 if the build environment has no PHP (e.g.,
# bare build containers); production CI must always have PHP available.
if [[ -x "$SCRIPT_DIR/smoke-test.sh" ]]; then
    if [[ -z "${SKIP_SMOKE:-}" ]]; then
        echo "--- Running smoke-test (pre-release gate) ---"
        "$SCRIPT_DIR/smoke-test.sh"
        echo "--- Smoke-test OK ---"
    else
        echo "warning: SKIP_SMOKE=1 set — bypassing pre-release smoke-test" >&2
    fi
else
    echo "warning: scripts/smoke-test.sh missing or not executable; skipping pre-release gate" >&2
fi

# Output goes to releases/ (tracked in git, alongside prior version ZIPs).
# This matches the repo's existing convention — see commit history "Add
# release zips for v1.0.1 through v2.1.7" — and is what the user expects.
DIST_DIR="$REPO_ROOT/releases"
STAGING_DIR="$(mktemp -d -t alegra-release-XXXXXX)"
trap 'rm -rf "$STAGING_DIR"' EXIT
mkdir -p "$DIST_DIR"

ZIP_NAME="alegra-connector-v${VERSION}.zip"
SHA_NAME="${ZIP_NAME}.sha256"
ZIP_PATH="$DIST_DIR/$ZIP_NAME"
SHA_PATH="$DIST_DIR/$SHA_NAME"

# Reuse .distignore: convert it into a grep -v filter against git ls-files.
# Each non-comment, non-blank line is treated as a regex matched against
# paths starting with the pattern (or matching anywhere if no leading slash).
DISTIGNORE="$REPO_ROOT/.distignore"
if [[ ! -f "$DISTIGNORE" ]]; then
    echo "error: .distignore not found at $DISTIGNORE" >&2
    exit 5
fi

# Build a list of exclude patterns (skip blank lines and # comments).
# Strip leading and trailing slashes so prefix matching works in bash:
# `[[ "scripts/build-release.sh" == scripts/* ]]` matches while
# `[[ "scripts/build-release.sh" == scripts//* ]]` (trailing-slash literal) does not.
EXCLUDES=()
while IFS= read -r line; do
    [[ -z "$line" || "$line" =~ ^# ]] && continue
    # Trim leading and trailing whitespace
    line="${line#"${line%%[![:space:]]*}"}"
    line="${line%"${line##*[![:space:]]}"}"
    [[ -z "$line" ]] && continue
    # Normalize: strip leading slash, strip trailing slash (keep patterns like *.log intact)
    if [[ "$line" == /* ]]; then line="${line#/}"; fi
    if [[ "$line" == */ ]]; then line="${line%/}"; fi
    EXCLUDES+=("$line")
done < "$DISTIGNORE"

# Helper: does any .distignore pattern match this path?
is_excluded() {
    local path="$1"
    for pat in "${EXCLUDES[@]}"; do
        # Exact match
        if [[ "$path" == "$pat" ]]; then
            return 0
        fi
        # Path under a directory pattern: docs/DOCUMENTACION.md matches "docs"
        if [[ "$path" == "$pat"/* ]]; then
            return 0
        fi
        # File matches a glob pattern: foo.log matches "*.log"
        # bash's == does glob matching against the RHS
        if [[ "$path" == $pat ]]; then
            return 0
        fi
    done
    return 1
}

# Stage every tracked file that isn't excluded
STAGED=0
while IFS= read -r src; do
    if is_excluded "$src"; then
        continue
    fi
    dst="$STAGING_DIR/alegra-connector/$src"
    mkdir -p "$(dirname "$dst")"
    cp -p "$src" "$dst"
    STAGED=$((STAGED + 1))
done < <(git ls-files)

echo "Staged $STAGED files."

# Critical: explicitly verify the regression class is still covered
if [[ ! -f "$STAGING_DIR/alegra-connector/logger/Logger/Logger.php" ]]; then
    echo "FATAL: staged ZIP is missing logger/Logger/Logger.php — refusing to ship." >&2
    echo "This is the regression that caused 2.1.7's activation fatal." >&2
    exit 6
fi

# Build the ZIP
(cd "$STAGING_DIR" && zip -r "$ZIP_PATH" alegra-connector >/dev/null)
echo "Wrote $ZIP_PATH"

# SHA256 sidecar
"${SHA256_CMD[@]}" "$ZIP_PATH" | awk -v zip_name="$(basename "$ZIP_NAME")" '{print $1 "  " zip_name}' > "$SHA_PATH"
echo "Wrote $SHA_PATH"

# Summary
ZIP_SIZE=$(wc -c < "$ZIP_PATH" | tr -d ' ')
SHA_VALUE=$(awk '{print $1}' "$SHA_PATH")
echo
echo "============================================"
echo "Release: $VERSION"
echo "Commit:  $HEAD_DESC"
echo "Files:   $STAGED (staged from git ls-files minus .distignore)"
echo "ZIP:     $ZIP_PATH  ($ZIP_SIZE bytes)"
echo "SHA256:  $SHA_VALUE"
echo "============================================"
echo
echo "Next steps:"
echo "  1. Extract and smoke-test:    bash scripts/smoke-test-zip.sh $ZIP_PATH"
echo "  2. Upload to test server and activate"
echo "  3. Monitor wp-content/debug.log for 5 minutes for new fatals"
