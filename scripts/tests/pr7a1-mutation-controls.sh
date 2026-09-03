#!/usr/bin/env bash
#
# PR 7A.1 mutation controls.
#
# PR 7A shipped a cron handler bound to an event that was never scheduled, and
# a fully green suite said nothing — because every test called tick() directly
# and none asserted the SCHEDULE. These controls exist so that hole cannot
# reopen: each one plants a specific defect and REQUIRES the suite to go red.
#
# A control that "passes" (suite still green with the defect planted) is a
# FAILURE of the control: it means the test describes the code instead of
# constraining it.
#
# ⚠ PREFLIGHT FIRST, in BOTH harnesses. A runner that reports "all killed"
# having executed nothing is the exact false-green this file guards against —
# and an unreachable database makes every kill a FALSE kill.
#
# Usage:
#   BCC_TEST_DB_HOST=127.0.0.1 BCC_TEST_DB_PORT=13401 \
#   BCC_TEST_DB_USER=root BCC_TEST_DB_PASS=root BCC_TEST_DB_NAME=bcc_pr7a1_test \
#   bash scripts/tests/pr7a1-mutation-controls.sh

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 2

PHP="${PHP_BIN:-php}"
UNIT_FILTER='DiscoveryMaintenanceScheduleTest'
INT_FILTER='DiscoveryMaintenanceCronIntegrationTest'

WORKER='app/Domain/Onchain/Workers/DiscoveryRunMaintenance.php'
BOOTSTRAP='bcc-trust.php'

KILLED=0
SURVIVED=0
declare -a SURVIVORS=()

backup() { cp "$WORKER" "$WORKER.orig"; cp "$BOOTSTRAP" "$BOOTSTRAP.orig"; }
restore() { mv -f "$WORKER.orig" "$WORKER"; mv -f "$BOOTSTRAP.orig" "$BOOTSTRAP"; }
trap 'restore 2>/dev/null || true' EXIT

run_unit() {
  "$PHP" vendor/bin/phpunit --filter "$UNIT_FILTER" --no-coverage >/dev/null 2>&1
}
run_int() {
  "$PHP" -d extension=mysqli vendor/bin/phpunit -c phpunit-integration.xml.dist \
    --filter "$INT_FILTER" --no-coverage >/dev/null 2>&1
}

# ── PREFLIGHT ───────────────────────────────────────────────────────────
echo "── preflight ──────────────────────────────────────────────────────"

if ! run_unit; then
  echo "ABORT: the unit anchor does not pass on unmutated code."
  echo "       Every 'killed' below would be meaningless."
  exit 2
fi
echo "  ok   unit harness green before mutating"

if ! run_int; then
  echo "ABORT: the integration anchor does not pass on unmutated code."
  echo "       Check the throwaway MySQL is running and BCC_TEST_DB_* are set —"
  echo "       an unreachable database makes every kill a FALSE kill."
  exit 2
fi
echo "  ok   integration harness green before mutating"
echo

# ── controls ────────────────────────────────────────────────────────────
# $1 = label, $2 = which harness(es): unit|int|both, $3.. = sed/python mutator
control() {
  local label="$1" harness="$2"
  shift 2

  backup
  "$@"

  local unit_red=0 int_red=0
  if [ "$harness" = "unit" ] || [ "$harness" = "both" ]; then
    run_unit || unit_red=1
  fi
  if [ "$harness" = "int" ] || [ "$harness" = "both" ]; then
    run_int || int_red=1
  fi
  restore

  local red=0
  case "$harness" in
    unit) red=$unit_red ;;
    int)  red=$int_red ;;
    both) [ "$unit_red" = 1 ] && [ "$int_red" = 1 ] && red=1 ;;
  esac

  if [ "$red" = 1 ]; then
    KILLED=$((KILLED + 1))
    printf '  KILLED    %s\n' "$label"
  else
    SURVIVED=$((SURVIVED + 1))
    SURVIVORS+=("$label")
    printf '  SURVIVED  %s   <-- the tests do not constrain this\n' "$label"
  fi
}

echo "── controls ───────────────────────────────────────────────────────"

# 1. THE PR 7A DEFECT ITSELF: handler wired, nothing scheduled.
control "registerRecurring() removed — the exact PR 7A defect" both \
  python -c "
import io
p='$WORKER'
s=io.open(p,encoding='utf-8',newline='').read()
old='        AsyncDispatcher::registerRecurring(self::HOOK, self::INTERVAL);'
assert old in s, 'anchor not found'
io.open(p,'w',encoding='utf-8',newline='').write(s.replace(old,'        // MUTANT: scheduling removed'))
"

# 2. Idempotency bypassed: schedule unconditionally, no wp_next_scheduled guard.
control "idempotency guard bypassed — schedules on every call" both \
  python -c "
import io
p='$WORKER'
s=io.open(p,encoding='utf-8',newline='').read()
old='        AsyncDispatcher::registerRecurring(self::HOOK, self::INTERVAL);'
new='        wp_schedule_event(time(), self::INTERVAL, self::HOOK); // MUTANT: no guard'
assert old in s
io.open(p,'w',encoding='utf-8',newline='').write(s.replace(old,new))
"

# 3. Wrong recurrence: a real but different interval.
control "recurrence changed to 'hourly'" both \
  python -c "
import io
p='$WORKER'
s=io.open(p,encoding='utf-8',newline='').read()
old=\"public const INTERVAL = 'bcc_five_minutes';\"
assert old in s
io.open(p,'w',encoding='utf-8',newline='').write(s.replace(old,\"public const INTERVAL = 'hourly';\"))
"

# 4. Handler bound to the wrong hook.
control "handler bound to the executor's hook instead" both \
  python -c "
import io
p='$WORKER'
s=io.open(p,encoding='utf-8',newline='').read()
old=\"        add_action(self::HOOK, [self::class, 'handleSweep'], 10, 0);\"
new=\"        add_action('bcc_discovery_run_execute', [self::class, 'handleSweep'], 10, 0); // MUTANT\"
assert old in s
io.open(p,'w',encoding='utf-8',newline='').write(s.replace(old,new))
"

# 5. Handler is a closure again — two registrations become two subscribers,
#    so the sweep would run twice per tick.
control "handler reverted to a closure (duplicate subscriber)" unit \
  python -c "
import io
p='$WORKER'
s=io.open(p,encoding='utf-8',newline='').read()
old=\"        add_action(self::HOOK, [self::class, 'handleSweep'], 10, 0);\"
new='        add_action(self::HOOK, static function (): void { self::tick(); }, 10, 0); // MUTANT'
assert old in s
io.open(p,'w',encoding='utf-8',newline='').write(s.replace(old,new))
"

# 6. Registration taken OFF the plugins_loaded path (the PR 7A shape: present
#    in the file, never reached by initialization).
control "register() call moved out of the plugins_loaded block" unit \
  python -c "
import io
p='$BOOTSTRAP'
s=io.open(p,encoding='utf-8',newline='').read()
old='    \\\\BCC\\\\Trust\\\\Onchain\\\\Workers\\\\DiscoveryRunMaintenance::register();'
assert old in s, 'anchor not found'
io.open(p,'w',encoding='utf-8',newline='').write(s.replace(old,'    // MUTANT: registration no longer on the init path',1))
"

# 7. The bare file-scope add_action restored alongside register() — a second
#    binding, which would run the sweep twice per tick.
#    ⚠ Anchor on a SINGLE line. bcc-trust.php is CRLF, so a multi-line
#    Python anchor containing "\n" never matches and the control silently
#    mutates nothing — which reads as a survivor and hides real coverage.
control "bare file-scope add_action restored (double binding)" unit \
  python -c "
import io
p='$BOOTSTRAP'
s=io.open(p,encoding='utf-8',newline='').read()
anchor='    \\\\BCC\\\\Trust\\\\Onchain\\\\Workers\\\\DiscoveryRunExecutor::HOOK,'
assert anchor in s, 'anchor not found'
inject='add_action(\\\\BCC\\\\Trust\\\\Onchain\\\\Workers\\\\DiscoveryRunMaintenance::HOOK, static function (): void {}, 10, 0); // MUTANT'
nl='\r\n' if '\r\n' in s else '\n'
io.open(p,'w',encoding='utf-8',newline='').write(s.replace(anchor, inject + nl + anchor, 1))
"

# 8. The declaration dropped from includes/cron-hooks.php — deactivation would
#    stop clearing the event and drift detection would stop expecting it.
control "declaration removed from includes/cron-hooks.php" unit \
  python -c "
import io
p='includes/cron-hooks.php'
import shutil; shutil.copy(p, p + '.mutbak')
s=io.open(p,encoding='utf-8',newline='').read()
lines=[l for l in s.split('\n') if 'bcc_discovery_run_maintenance' not in l]
assert len(lines) < len(s.split('\n')), 'anchor not found'
io.open(p,'w',encoding='utf-8',newline='').write('\n'.join(lines))
"
# control 8 mutates a third file; restore it explicitly.
if [ -f includes/cron-hooks.php.mutbak ]; then
  mv -f includes/cron-hooks.php.mutbak includes/cron-hooks.php
fi

echo
echo "── result ─────────────────────────────────────────────────────────"
printf '  killed:   %d\n' "$KILLED"
printf '  survived: %d\n' "$SURVIVED"

if [ "$SURVIVED" -gt 0 ]; then
  echo
  echo "  survivors:"
  for s in "${SURVIVORS[@]}"; do printf '    - %s\n' "$s"; done
  exit 1
fi

echo
echo "  All controls killed. The scheduling tests constrain the code."
exit 0
