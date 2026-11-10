#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# Run PHPStan level 8 on bcc-trust locally, then put vendor/ back.
#
# CI runs `composer phpstan` and it is blocking. It is also the only check that
# sees a widened docblock type, a reference to a class that does not exist, or
# an `@param` lost when a method was extracted — `php -l` and the unit suite
# cannot. bcc-trust #368 shipped with three such errors because this was not
# run first.
#
# Why a script rather than two commands: the dev tools cannot be installed
# without dirtying ~675 tracked files under vendor/, and putting them back
# BREAKS PHPStan until it is reinstalled, with an error that names a cached
# nette container rather than the autoloader. The full reasoning and the
# measured evidence are in docs/running-phpstan-locally.md.
#
# Usage:
#   bash scripts/phpstan-local.sh                    # whole tree
#   bash scripts/phpstan-local.sh app/Domain/Core     # one path
#   BCC_KEEP_VENDOR=1 bash scripts/phpstan-local.sh   # leave vendor/ dirty
#                                                     # (faster to iterate)
# ──────────────────────────────────────────────────────────────────────────────

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

if [[ -t 1 ]]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; NC=''
fi

cd "$PLUGIN_DIR" || exit 2

# The plugin's own entry file is what makes this directory bcc-trust — the
# directory NAME does not, because a git worktree is named after the worktree.
# Same identification as scripts/arch-guardrails.sh (see #203).
if [[ ! -f "$PLUGIN_DIR/bcc-trust.php" || ! -f "$PLUGIN_DIR/phpstan.neon" ]]; then
    echo -e "${RED}FAIL${NC}: $PLUGIN_DIR does not look like bcc-trust (no bcc-trust.php / phpstan.neon)"
    exit 2
fi

# ── scanDirectories: ../bcc-core/src/ ────────────────────────────────────────
#
# True in the plugins layout, false in a worktree. Without it PHPStan reports
# every bcc-core symbol as unknown, which reads like a hundred real errors.
if [[ ! -d "$PLUGIN_DIR/../bcc-core/src" ]]; then
    echo -e "${YELLOW}NOTE${NC}: ../bcc-core/src is missing — phpstan.neon scans it."
    echo "      From a worktree, place a copy beside it, FROM origin/main:"
    echo "        git -C <plugins>/bcc-core archive origin/main src | tar -x -C \"$PLUGIN_DIR/..\""
    echo "      (the local bcc-core checkout can be many commits behind)"
    exit 2
fi

RESTORE=1
[[ "${BCC_KEEP_VENDOR:-0}" == "1" ]] && RESTORE=0

restore_vendor() {
    [[ $RESTORE -eq 1 ]] || return 0
    echo ""
    echo "→ restoring vendor/ (composer update rewrites tracked production deps)"
    git checkout -- vendor 2>/dev/null
    git clean -fdq vendor 2>/dev/null
    local dirty
    dirty="$(git status --porcelain vendor | wc -l | tr -d ' ')"
    if [[ "$dirty" != "0" ]]; then
        echo -e "${RED}WARN${NC}: vendor/ still shows $dirty change(s) — inspect before committing"
    else
        echo "  vendor/ clean"
    fi
    # ⚠ The restore puts back the --no-dev autoloader, so the cached PHPStan
    # container now references classes it cannot load. Clearing the cache here
    # means the NEXT run fails with the honest "HookCallbackRule not found"
    # rather than a stack trace pointing at Container_*.php.
    rm -rf "${TEMP:-${TMPDIR:-/tmp}}/phpstan" 2>/dev/null
}
trap restore_vendor EXIT

# ── Install the dev tools ────────────────────────────────────────────────────
#
# `install` cannot work: composer.lock is gitignored by design, so there is
# nothing to install from. `update` is what CI runs. The gmp flag is needed on
# a machine without the extension — simplito/elliptic-php declares it, and
# without the flag composer refuses to resolve at all. PHPStan does not need it.
# ⚠⚠ THE TEST IS "CAN THE AUTOLOADER SEE THE EXTENSION", NOT "IS THE BINARY
# THERE". This script's first version checked for vendor/bin/phpstan and got it
# wrong in the one state that matters: that path is GITIGNORED, so it survives
# `git checkout -- vendor`, while the autoloader beside it is reverted to the
# committed --no-dev build that maps ZERO dev classes. The binary is present,
# PHPStan starts, and dies with
#   Service 'rules.0': Class 'SzepeViktor\PHPStan\WordPress\HookCallbackRule' not found.
# So the predicate is the exact class that error names.
dev_ready() {
    [[ -f vendor/autoload.php ]] || return 1
    php -r 'require "vendor/autoload.php";
            exit(class_exists("SzepeViktor\\PHPStan\\WordPress\\HookCallbackRule") ? 0 : 1);' \
        >/dev/null 2>&1
}

if ! dev_ready; then
    echo "→ installing dev dependencies (this rewrites tracked vendor/ files)"
    if ! composer update --no-interaction --no-progress --prefer-dist \
            --ignore-platform-req=ext-gmp; then
        echo -e "${RED}FAIL${NC}: composer update failed"
        exit 2
    fi
    if ! dev_ready; then
        echo -e "${RED}FAIL${NC}: composer update finished but the WordPress extension is still"
        echo "       not loadable. See docs/running-phpstan-locally.md."
        exit 2
    fi
else
    echo "→ dev dependencies already loadable"
fi

# A stale container from a PREVIOUS restore throws instead of analysing.
rm -rf "${TEMP:-${TMPDIR:-/tmp}}/phpstan" 2>/dev/null

PHPSTAN="vendor/bin/phpstan"
[[ -f "$PHPSTAN" ]] || PHPSTAN="vendor/bin/phpstan.phar"

echo "→ phpstan analyse (level 8)"
echo ""

# `-1` rather than CI's 4G: at 1G the parallel worker dies and reports a
# *severe error* with an INCOMPLETE result, which does not look like a type
# error at all. Unlimited removes that failure mode from a local run.
php "$PHPSTAN" analyse --memory-limit=-1 --no-progress "$@"
STATUS=$?

echo ""
if [[ $STATUS -eq 0 ]]; then
    echo -e "${GREEN}PASS${NC}: PHPStan level 8 clean"
else
    echo -e "${RED}FAIL${NC}: PHPStan reported errors (exit $STATUS)"
fi

exit $STATUS
