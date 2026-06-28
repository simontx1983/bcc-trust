#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# BCC Architecture Guardrails
#
# Enforces the rules documented in CLAUDE.md across all BCC plugins.
# Run before committing.  Exit code 0 = clean, 1 = violations found.
#
# Usage:
#   bash scripts/arch-guardrails.sh              # scan all plugins
#   bash scripts/arch-guardrails.sh bcc-disputes  # scan one plugin
#   bash scripts/arch-guardrails.sh --json        # machine-readable output
# ──────────────────────────────────────────────────────────────────────────────

set -uo pipefail
# Note: -e removed because several checks intentionally use commands that
# can return non-zero (e.g. grep with no matches), which is expected. Each
# check function guards its own exit paths with `|| true` where needed.

# ── Resolve plugin root ──────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGINS_DIR="$(cd "$SCRIPT_DIR/../../" && pwd)"

JSON_MODE=false
TARGET=""
WITH_CONTRACT=false

for arg in "$@"; do
    case "$arg" in
        --json)          JSON_MODE=true ;;
        --with-contract) WITH_CONTRACT=true ;;
        *)               TARGET="$arg" ;;
    esac
done

# All BCC plugins (order doesn't matter)
ALL_PLUGINS=(
    bcc-core
    bcc-trust
    bcc-search
    blue-collar-crypto-peepso-integration
)

# ── Known-debt allowlist for Rule 1 ($wpdb outside Repositories) ─────────────
#
# These files pre-date the directory-scan fix that made Rule 1 actually run
# (before, it scanned app/Services/Controllers/Admin — dirs that don't exist
# post-M1, so the rule was a silent no-op). Each is a real §1 violation that
# is SAFE today (no injected input) but must be paid down by moving its $wpdb
# into a Repository. Tracked in docs/TODO.md.
#
# This list is a RATCHET: it freezes the existing debt so the guard goes green
# and enforces every NEW file, while the legacy sites are remediated one at a
# time. Remove an entry the moment its file's $wpdb moves into a Repository —
# never ADD to this list for new code.
WPDB_DEBT_ALLOWLIST=(
)

if [[ -n "$TARGET" && "$TARGET" != "--json" ]]; then
    PLUGINS=("$TARGET")
else
    PLUGINS=("${ALL_PLUGINS[@]}")
fi

# ── Counters ─────────────────────────────────────────────────────────────────

TOTAL_VIOLATIONS=0
TOTAL_WARNINGS=0
declare -a JSON_ENTRIES=()

# ── Helpers ──────────────────────────────────────────────────────────────────

RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
BOLD='\033[1m'
NC='\033[0m'

violation() {
    local plugin="$1" rule="$2" file="$3" line="${4:-}" detail="${5:-}"
    TOTAL_VIOLATIONS=$((TOTAL_VIOLATIONS + 1))
    if $JSON_MODE; then
        JSON_ENTRIES+=("{\"severity\":\"ERROR\",\"plugin\":\"$plugin\",\"rule\":\"$rule\",\"file\":\"$file\",\"line\":\"$line\",\"detail\":\"$detail\"}")
    else
        echo -e "  ${RED}VIOLATION${NC} [$rule] $file${line:+:$line} ${detail}"
    fi
}

warning() {
    local plugin="$1" rule="$2" file="$3" line="${4:-}" detail="${5:-}"
    TOTAL_WARNINGS=$((TOTAL_WARNINGS + 1))
    if $JSON_MODE; then
        JSON_ENTRIES+=("{\"severity\":\"WARN\",\"plugin\":\"$plugin\",\"rule\":\"$rule\",\"file\":\"$file\",\"line\":\"$line\",\"detail\":\"$detail\"}")
    else
        echo -e "  ${YELLOW}WARNING${NC}   [$rule] $file${line:+:$line} ${detail}"
    fi
}

# ── Rule 1: $wpdb only in Repositories ───────────────────────────────────────
#
# Allowed locations:
#   app/Repositories/        (the whole point)
#   app/Database/            (TableRegistry — table name helper)
#   app/Security/TransactionManager.php  (BEGIN/COMMIT/ROLLBACK)
#   includes/database/       (schema files, dbDelta)
#   uninstall.php            (cleanup on plugin delete)
#   src/                     (bcc-core internals: Throttle, ChallengeRepository)
#
# Forbidden locations:
#   app/Services/            (must delegate to repositories)
#   app/Controllers/         (must delegate to services)
#   app/Admin/               (must delegate to repositories)
#   templates/               (must receive data, never query)
#   blocks/                  (must receive data, never query)
#   includes/renderers/      (must receive data, never query)

check_wpdb_leaks() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    [[ -d "$plugin_dir" ]] || return

    # Directories where $wpdb is FORBIDDEN.
    #
    # Post-M1 merge, all bcc-trust code lives under app/Domain/** (Services,
    # REST, Controllers, Support, Application, Workers, Factories, Fetchers,
    # …). $wpdb is allowed ONLY in Repositories/ and Infrastructure/, which are
    # exempted in the case statement below. So we scan the whole of app/Domain
    # and let the exemptions carve out the allowed homes — this catches every
    # forbidden layer instead of a hand-maintained subdir list that silently
    # rots when a new domain folder appears.
    #
    # The legacy flat dirs (app/Services etc.) are kept so the rule still works
    # for any plugin that hasn't moved to the Domain layout (bcc-core/src is
    # an allowed exception and is NOT listed).
    local -a forbidden_dirs=()
    for d in app/Domain app/Services app/Controllers app/Admin app/Integration templates blocks includes/renderers includes/partials; do
        [[ -d "$plugin_dir/$d" ]] && forbidden_dirs+=("$plugin_dir/$d")
    done

    # Also scan plugin root (main bootstrap file). uninstall.php is an
    # allowed exception (see case statement below).
    local -a root_files=()
    while IFS= read -r f; do
        root_files+=("$f")
    done < <(find "$plugin_dir" -maxdepth 1 -name '*.php' 2>/dev/null)

    [[ ${#forbidden_dirs[@]} -eq 0 && ${#root_files[@]} -eq 0 ]] && return

    # grep for 'global $wpdb' or '$wpdb->' in forbidden directories + root files
    while IFS=: read -r file line content; do
        # Skip comments (lines starting with optional whitespace + // or *)
        if echo "$content" | grep -qE '^\s*(//|\*|#)'; then
            continue
        fi

        # Normalize path for display
        local rel="${file#$PLUGINS_DIR/}"

        # Known exceptions — the allowed homes for $wpdb (CLAUDE.md §1).
        case "$rel" in
            */Repositories/*)                  continue ;;  # the allowed home for $wpdb
            */Infrastructure/*)                continue ;;  # allowed per §1
            */Security/TransactionManager.php) continue ;;  # BEGIN/COMMIT/ROLLBACK
            */Database/TableRegistry.php)      continue ;;  # table name helper
            */uninstall.php)                   continue ;;  # cleanup on plugin delete
            */phpstan-bootstrap.php)           continue ;;  # PHPStan stubs
        esac

        # Known-debt allowlist (tracked, pre-existing §1 violations slated for
        # extraction into Repositories — see WPDB_DEBT_ALLOWLIST near the top of
        # this script and docs/TODO.md). Remove a file from the allowlist when
        # its $wpdb is moved into a Repository; the guard then enforces it.
        local waived=false
        for debt in "${WPDB_DEBT_ALLOWLIST[@]}"; do
            if [[ "$rel" == "$debt" ]]; then
                waived=true
                break
            fi
        done
        $waived && continue

        # Surgical per-hit waiver for one-off cases: an
        #   arch-guardrails:allow-wpdb — <reason>
        # marker on the same line or the line directly above. New code must
        # delegate to a Repository instead of relying on this.
        if echo "$content" | grep -q 'arch-guardrails:allow-wpdb'; then
            continue
        fi
        local prev_line
        prev_line=$(sed -n "$((line > 1 ? line - 1 : 1))p" "$file" 2>/dev/null || true)
        if echo "$prev_line" | grep -q 'arch-guardrails:allow-wpdb'; then
            continue
        fi

        violation "$plugin" "WPDB_OUTSIDE_REPO" "$rel" "$line" "— direct \$wpdb in forbidden layer"
    done < <(grep -rn 'global \$wpdb\|\$wpdb->' "${forbidden_dirs[@]}" "${root_files[@]}" --include='*.php' 2>/dev/null || true)
}

# ── Rule 2: No SELECT * ─────────────────────────────────────────────────────

check_select_star() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    [[ -d "$plugin_dir/app/Repositories" ]] || return

    while IFS=: read -r file line content; do
        local rel="${file#$PLUGINS_DIR/}"
        violation "$plugin" "SELECT_STAR" "$rel" "$line" "— use explicit column lists"
    done < <(grep -rn 'SELECT \*' "$plugin_dir/app/Repositories" --include='*.php' 2>/dev/null || true)
}

# ── Rule 3: No template queries ──────────────────────────────────────────────

check_template_queries() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    local -a template_dirs=()
    for d in templates blocks includes/admin includes/partials; do
        [[ -d "$plugin_dir/$d" ]] && template_dirs+=("$plugin_dir/$d")
    done

    [[ ${#template_dirs[@]} -eq 0 ]] && return

    while IFS=: read -r file line content; do
        local rel="${file#$PLUGINS_DIR/}"
        violation "$plugin" "TEMPLATE_QUERY" "$rel" "$line" "— templates must not query DB"
    done < <(grep -rn 'global \$wpdb' "${template_dirs[@]}" --include='*.php' 2>/dev/null || true)
}

# ── Rule 4: Bounded queries (warn on SELECT without LIMIT) ──────────────────

check_unbounded_queries() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    [[ -d "$plugin_dir/app/Repositories" ]] || return

    # Find SELECT statements without LIMIT, WHERE...id, IN(), or aggregate
    # This is a heuristic — it catches obvious cases, not all.
    while IFS=: read -r file line content; do
        local rel="${file#$PLUGINS_DIR/}"
        # Skip if the same file+nearby lines have LIMIT, WHERE...=%d, IN(, COUNT(, SUM(, AVG(
        local context
        context=$(sed -n "$((line > 5 ? line - 5 : 1)),$((line + 10))p" "$file" 2>/dev/null || true)
        if echo "$context" | grep -qi 'LIMIT\|WHERE.*=.*%d\|IN\s*(\|COUNT(\|SUM(\|AVG(\|GROUP BY'; then
            continue
        fi
        warning "$plugin" "UNBOUNDED_SELECT" "$rel" "$line" "— SELECT may be unbounded (no LIMIT or unique key)"
    done < <(grep -rn 'get_results\|get_col' "$plugin_dir/app/Repositories" --include='*.php' 2>/dev/null || true)
}

# ── Rule 5: PHP syntax check on app/ files ───────────────────────────────────

check_php_syntax() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    local -a src_dirs=()
    [[ -d "$plugin_dir/app" ]] && src_dirs+=("$plugin_dir/app")
    [[ -d "$plugin_dir/src" ]] && src_dirs+=("$plugin_dir/src")
    [[ ${#src_dirs[@]} -eq 0 ]] && return

    while IFS= read -r file; do
        local result
        result=$(php -l "$file" 2>&1)
        if [[ $? -ne 0 ]]; then
            local rel="${file#$PLUGINS_DIR/}"
            violation "$plugin" "PHP_SYNTAX" "$rel" "" "— $result"
        fi
    done < <(find "${src_dirs[@]}" -name '*.php' 2>/dev/null | head -200)
}

# ── Rule 6: No add_shortcode( in BCC plugins ────────────────────────────────
#
# Per the headless architecture (CLAUDE.md §8: No UI in PHP), all user-facing
# UI lives in bcc-frontend (Next.js). Shortcodes render HTML — they're a UI
# surface and must not return.
#
# Allowed scopes for the search:
#   app/, src/, includes/  (anywhere code is)
# Exempt:
#   tests/, vendor/

check_no_shortcodes() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    local -a search_dirs=()
    for d in app src includes; do
        [[ -d "$plugin_dir/$d" ]] && search_dirs+=("$plugin_dir/$d")
    done
    local -a root_files=()
    while IFS= read -r f; do
        root_files+=("$f")
    done < <(find "$plugin_dir" -maxdepth 1 -name '*.php' 2>/dev/null)

    [[ ${#search_dirs[@]} -eq 0 && ${#root_files[@]} -eq 0 ]] && return

    while IFS=: read -r file line content; do
        # Skip comments
        if echo "$content" | grep -qE '^\s*(//|\*|#)'; then
            continue
        fi
        local rel="${file#$PLUGINS_DIR/}"
        violation "$plugin" "NO_SHORTCODES" "$rel" "$line" "— add_shortcode() forbidden (No UI in PHP)"
    done < <(grep -rn 'add_shortcode\s*(' "${search_dirs[@]}" "${root_files[@]}" --include='*.php' 2>/dev/null || true)
}

# ── Rule 7: No register_block_type( anywhere in BCC plugins ─────────────────
#
# Per the headless architecture, FSE/Gutenberg blocks are not the rendering
# layer. Registering a block surface is a regression to the hybrid path.

check_no_block_registration() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    [[ -d "$plugin_dir" ]] || return

    while IFS=: read -r file line content; do
        if echo "$content" | grep -qE '^\s*(//|\*|#)'; then
            continue
        fi
        local rel="${file#$PLUGINS_DIR/}"
        violation "$plugin" "NO_BLOCK_REG" "$rel" "$line" "— register_block_type() forbidden (No UI in PHP)"
    done < <(grep -rn 'register_block_type\s*(' "$plugin_dir" --include='*.php' --exclude-dir=vendor --exclude-dir=tests 2>/dev/null || true)
}

# ── Rule 8: No templates/ directory in BCC plugins ──────────────────────────
#
# templates/ holds PHP-rendered HTML that gets `include`d. Even an empty
# directory invites regressions — fail if it exists at all.

check_no_templates_dir() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    if [[ -d "$plugin_dir/templates" ]]; then
        violation "$plugin" "NO_TEMPLATE_DIR" "templates/" "" "— directory forbidden (No UI in PHP); delete it"
    fi
}

# ── Rule 9: No HTML echo in services/controllers ────────────────────────────
#
# Services and controllers must return arrays/DTOs/JSON. echo of HTML is a
# rendering act that belongs in the Next.js frontend, not in PHP.
#
# DOCUMENTED EXCEPTION (CLAUDE.md §8): wp-admin pages and admin notices
# render in PHP today (V2 retirement target — Next.js admin app).
# Auto-exempt:
#   - Files under any */Admin/ directory
#   - Files containing an add_action('admin_notices', ...) subscription

check_no_html_echo() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    local -a search_dirs=()
    for d in \
        app/Domain/Core/Services \
        app/Domain/Core/Controllers \
        app/Domain/Core/REST \
        app/Domain/Core/Application \
        app/Domain/Disputes/Services \
        app/Domain/Disputes/Controllers \
        app/Domain/Onchain/Services \
        app/Domain/Onchain/Controllers \
        app/Services \
        app/Controllers \
        src \
    ; do
        [[ -d "$plugin_dir/$d" ]] && search_dirs+=("$plugin_dir/$d")
    done

    [[ ${#search_dirs[@]} -eq 0 ]] && return

    while IFS=: read -r file line content; do
        # Skip comments
        if echo "$content" | grep -qE '^\s*(//|\*|#)'; then
            continue
        fi
        local rel="${file#$PLUGINS_DIR/}"

        # Documented admin exceptions
        # 1. Files under any */Admin/ directory
        if echo "$rel" | grep -q '/Admin/'; then
            continue
        fi
        # 2. Files containing an admin_notices hook subscription (file-local)
        if grep -q "admin_notices" "$file" 2>/dev/null; then
            continue
        fi
        # 3. Methods named *Notice (subscribed externally to admin_notices).
        #    Inspect 30 lines back for the most recent function declaration.
        local fn_name
        fn_name=$(sed -n "$((line > 30 ? line - 30 : 1)),${line}p" "$file" 2>/dev/null \
                    | grep -oE 'function\s+[a-zA-Z_][a-zA-Z0-9_]*' \
                    | tail -1 \
                    | awk '{print $2}')
        if [[ -n "$fn_name" ]] && echo "$fn_name" | grep -qi 'notice'; then
            continue
        fi

        violation "$plugin" "NO_HTML_ECHO" "$rel" "$line" "— echo of HTML in service/controller (No UI in PHP)"
    done < <(grep -rEn "echo\s+['\"]<" "${search_dirs[@]}" --include='*.php' 2>/dev/null || true)
}

# ── Rule 10: API contract check (opt-in via --with-contract) ────────────────
#
# Runs scripts/api-contract-check.sh which hits the live site and verifies
# the response envelope, error envelope, and pagination shapes match the
# contract locked in docs/api-contract-v1.md. Requires the local site to be
# running. Opt-in because static rules should run without network access.
#
# CI MUST pass --with-contract.

check_api_contract() {
    local plugin="$1"

    # Only run once, on the first plugin in the loop, since the script is
    # ecosystem-wide (not per-plugin).
    if [[ "$plugin" != "${PLUGINS[0]}" ]]; then
        return
    fi

    local script_path="$SCRIPT_DIR/api-contract-check.sh"
    if [[ ! -x "$script_path" && ! -f "$script_path" ]]; then
        return
    fi

    $JSON_MODE || echo -e "\n${BOLD}Running API contract check${NC}"
    if bash "$script_path" >/dev/null 2>&1; then
        $JSON_MODE || echo -e "  ${GREEN}CLEAN${NC} (api-contract-check.sh passed)"
    else
        violation "ecosystem" "API_CONTRACT_CHECK" "scripts/api-contract-check.sh" "" "— contract drift detected; run script directly for details"
    fi
}

# ── Run all checks ───────────────────────────────────────────────────────────

for plugin in "${PLUGINS[@]}"; do
    plugin_dir="$PLUGINS_DIR/$plugin"
    if [[ ! -d "$plugin_dir" ]]; then
        $JSON_MODE || echo -e "${YELLOW}SKIP${NC} $plugin (not found)"
        continue
    fi

    $JSON_MODE || echo -e "\n${BOLD}Scanning $plugin${NC}"

    check_wpdb_leaks "$plugin"
    check_select_star "$plugin"
    check_template_queries "$plugin"
    check_unbounded_queries "$plugin"
    check_no_shortcodes "$plugin"
    check_no_block_registration "$plugin"
    check_no_templates_dir "$plugin"
    check_no_html_echo "$plugin"
    # PHP syntax check is slow (~2s/file) — enable for single-plugin scans only.
    if [[ -n "$TARGET" ]]; then
        check_php_syntax "$plugin"
    fi
    if $WITH_CONTRACT; then
        check_api_contract "$plugin"
    fi

    $JSON_MODE || {
        if [[ $TOTAL_VIOLATIONS -eq 0 && $TOTAL_WARNINGS -eq 0 ]]; then
            echo -e "  ${GREEN}CLEAN${NC}"
        fi
    }
done

# ── Output ───────────────────────────────────────────────────────────────────

if $JSON_MODE; then
    echo "["
    sep=""
    for entry in "${JSON_ENTRIES[@]}"; do
        echo "${sep}  ${entry}"
        sep=","
    done
    echo "]"
else
    echo ""
    echo "──────────────────────────────────────────"
    if [[ $TOTAL_VIOLATIONS -gt 0 ]]; then
        echo -e "${RED}FAIL${NC}: $TOTAL_VIOLATIONS violation(s), $TOTAL_WARNINGS warning(s)"
    elif [[ $TOTAL_WARNINGS -gt 0 ]]; then
        echo -e "${YELLOW}WARN${NC}: 0 violations, $TOTAL_WARNINGS warning(s)"
    else
        echo -e "${GREEN}PASS${NC}: All guardrails satisfied"
    fi
    echo "──────────────────────────────────────────"
fi

exit $( [[ $TOTAL_VIOLATIONS -eq 0 ]] && echo 0 || echo 1 )
