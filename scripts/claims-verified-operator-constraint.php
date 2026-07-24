<?php
/**
 * Claims verified-operator uniqueness — audit + hand-run migration.
 *
 * Adds the DB-level guarantee that at most ONE verified operator claim
 * exists per (entity_type, entity_id). MySQL has no partial unique
 * indexes, so this uses a STORED generated column that is non-NULL
 * only for verified operator rows, plus a UNIQUE key on it (multiple
 * NULLs never collide):
 *
 *   verified_operator_slot = CASE WHEN claim_role='operator'
 *                                  AND status='verified'
 *                            THEN CONCAT(entity_type, ':', entity_id) END
 *
 * The app-level advisory lock in ClaimRepository::createExclusiveClaim
 * remains the friendly-error layer; this constraint is the backstop
 * against lockless upsert paths (which the 2026-07-23 audit showed can
 * bypass exclusivity).
 *
 * USAGE (hand-run per the rollout plan — deliberately NOT wired into
 * activation or the schema-version hook):
 *
 *   wp eval-file wp-content/plugins/bcc-trust/scripts/claims-verified-operator-constraint.php
 *       → audit-only report (always safe)
 *
 *   wp eval-file wp-content/plugins/bcc-trust/scripts/claims-verified-operator-constraint.php apply
 *       → runs the ALTER, but ONLY when: MySQL >= 5.7, both audits are
 *         clean, and the column does not already exist.
 *
 * ROLLBACK:
 *   ALTER TABLE {claims} DROP INDEX uq_verified_operator,
 *                        DROP COLUMN verified_operator_slot;
 *
 * Duplicate remediation is manual and operator-decided (prefer flipping
 * losers to status='superseded'; never DELETE evidence). Re-run the
 * audit until clean before `apply`. STOP CONDITION: if MySQL < 5.7 (no
 * stored generated columns + unique), do not improvise — the approved
 * fallback (app-level enforcement + hourly reconcile sweep) needs
 * separate sign-off.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run via: wp eval-file scripts/claims-verified-operator-constraint.php [apply]\n");
    exit(1);
}

global $wpdb;

$claims     = $wpdb->prefix . 'bcc_onchain_claims';
$validators = $wpdb->prefix . 'bcc_onchain_validators';
$wallets    = $wpdb->prefix . 'bcc_wallet_links';
$apply      = isset($args) && in_array('apply', (array) $args, true);

echo "== claims verified-operator constraint — " . ($apply ? "APPLY" : "audit-only") . " ==\n";

// ── 0. Environment ─────────────────────────────────────────────────
$version = (string) $wpdb->get_var('SELECT VERSION()');
echo "MySQL version: {$version}\n";
$versionOk = version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '5.7.0', '>=');
if (!$versionOk) {
    echo "FAIL: MySQL >= 5.7 required for stored generated columns + unique keys. STOP — the fallback path needs separate approval.\n";
    return;
}

$columnExists = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
        AND COLUMN_NAME = 'verified_operator_slot'",
    $claims
)) > 0;
echo 'Constraint column present: ' . ($columnExists ? 'YES (nothing to do)' : 'no') . "\n";

// ── 1. Duplicate audit — >1 distinct verified operator per entity ──
$dupes = $wpdb->get_results(
    "SELECT entity_type, entity_id, COUNT(DISTINCT user_id) AS c,
            GROUP_CONCAT(id ORDER BY id) AS claim_ids
       FROM {$claims}
      WHERE status = 'verified' AND claim_role = 'operator'
      GROUP BY entity_type, entity_id
     HAVING c > 1"
);
if ($dupes) {
    echo "DUPLICATES (" . count($dupes) . ") — remediate manually (prefer status='superseded'; never DELETE), then re-run:\n";
    foreach ($dupes as $d) {
        echo "  {$d->entity_type}:{$d->entity_id} — {$d->c} distinct operators, claim ids [{$d->claim_ids}]\n";
    }
} else {
    echo "Duplicate audit: CLEAN\n";
}

// ── 2. Orphan audit — verified validator-operator claims invisible to
//      the page resolver (validator missing / wallet link dangling).
//      Informational: orphans don't block the constraint, but they are
//      the wallet-disconnect residue the resolver audit flagged. ─────
$orphans = $wpdb->get_results(
    "SELECT cl.id, cl.entity_id, cl.user_id,
            (v.id IS NULL)             AS validator_missing,
            (v.wallet_link_id IS NULL) AS wallet_link_null,
            (w.id IS NULL)             AS wallet_row_missing
       FROM {$claims} cl
       LEFT JOIN {$validators} v ON v.id = cl.entity_id
       LEFT JOIN {$wallets} w ON w.id = v.wallet_link_id
      WHERE cl.entity_type = 'validator'
        AND cl.status = 'verified' AND cl.claim_role = 'operator'
        AND (v.id IS NULL OR v.wallet_link_id IS NULL OR w.id IS NULL)"
);
if ($orphans) {
    echo "ORPHANS (" . count($orphans) . ") — resolver-invisible verified operator claims (informational):\n";
    foreach ($orphans as $o) {
        $why = $o->validator_missing ? 'validator row missing'
            : ($o->wallet_row_missing && !$o->wallet_link_null ? 'wallet_link dangling' : 'wallet_link NULL');
        echo "  claim {$o->id} (entity {$o->entity_id}, user {$o->user_id}) — {$why}\n";
    }
} else {
    echo "Orphan audit: CLEAN\n";
}

// ── 3. Apply ────────────────────────────────────────────────────────
if (!$apply) {
    echo "Audit-only run complete. Re-run with `apply` to add the constraint.\n";
    return;
}
if ($columnExists) {
    echo "SKIP: constraint already present.\n";
    return;
}
if ($dupes) {
    echo "REFUSED: duplicate audit not clean — the ALTER would fail and the data needs operator-decided remediation first.\n";
    return;
}

$ok = $wpdb->query(
    "ALTER TABLE {$claims}
       ADD COLUMN verified_operator_slot VARCHAR(41)
           GENERATED ALWAYS AS (CASE WHEN claim_role = 'operator' AND status = 'verified'
                                     THEN CONCAT(entity_type, ':', entity_id) END) STORED,
       ADD UNIQUE KEY uq_verified_operator (verified_operator_slot)"
);
if ($ok === false) {
    echo "FAIL: ALTER errored — {$wpdb->last_error}\n";
    return;
}
echo "APPLIED: verified_operator_slot + uq_verified_operator.\n";
echo "Rollback: ALTER TABLE {$claims} DROP INDEX uq_verified_operator, DROP COLUMN verified_operator_slot;\n";
