<?php
/**
 * Trust Attestation Layer schema — §J wire contract + Phase 1 plan §5.1.
 *
 * The bcc_trust_attestations table is the canonical store for V1
 * Layer-1 attestations (Vouch + Stand Behind). Disputes live in a
 * separate table because they carry stake + panel mechanics that
 * don't fit this shape.
 *
 * Constitutional alignment:
 *   - §J.4.1 synthesis invisibility: weight_at_time is server-side
 *     only — it's stored so the synthesis layer can apply decay at
 *     read time, but it never leaks to third-party API responses
 *     (see api-contract-v1.md §4.20 §J.4 + the v1.11 consistency
 *     reconciliation entry).
 *   - §J.3.2 asymmetric display: no reliability or standing fields
 *     on the row — those are derived per-attestor at read time.
 *   - §J.1 long-term graph health: revoked_at is part of the
 *     unique key so operators can revoke and re-attest; only one
 *     active row per (attestor, target, kind) at a time.
 *
 * Migration history: the §J.11 / Slice E legacy-endorsement
 * materializations ran once (option-gated) and were deleted in the
 * endorse-retirement final slice — see the note inside
 * bcc_trust_create_trust_attestations_table().
 *
 * @package BCC\Trust\Core
 * @subpackage Database
 * @since V2 Trust Attestation Layer (2026-05-13)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create the bcc_trust_attestations table and migrate legacy endorsements.
 */
function bcc_trust_create_trust_attestations_table(): void {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $attestations_table = \BCC\Trust\Core\Database\TableRegistry::trustAttestations();

    $sql = "CREATE TABLE $attestations_table (
        id                            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        attestor_user_id              BIGINT UNSIGNED NOT NULL,
        target_kind                   VARCHAR(20) NOT NULL,
        target_id                     BIGINT UNSIGNED NOT NULL,
        kind                          VARCHAR(20) NOT NULL,
        weight_at_time                DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
        context_note                  TEXT NULL,
        attestation_order_in_target   INT UNSIGNED NOT NULL DEFAULT 0,
        created_at                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reaffirmed_at                 DATETIME NULL,
        revoked_at                    DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_active_attestation (attestor_user_id, target_kind, target_id, kind, revoked_at),
        KEY idx_target_active (target_kind, target_id, kind, revoked_at, created_at),
        KEY idx_attestor_active (attestor_user_id, kind, revoked_at, created_at),
        KEY idx_attestor_target (attestor_user_id, target_kind, target_id),
        KEY idx_created (created_at)
    ) $charset_collate;";

    dbDelta($sql);

    // NOTE (endorse-retirement final slice, 2026-07-02): the two §J.11 /
    // Slice E legacy-endorsement migrations
    // (bcc_trust_migrate_legacy_endorsements_to_attestations +
    // bcc_trust_migrate_postvouch_endorsements_to_attestations) were
    // DELETED. They ran once on the live site (option-gated:
    // bcc_trust_attestation_legacy_endorsement_migrated +
    // bcc_trust_attestation_postvouch_migrated remain set) and fresh
    // installs have no legacy endorsements table to migrate from — the
    // table itself is dropped by drop-endorsements-table.php.

    // Slice E: backfill attestation_order_in_target for ALL rows. The
    // (now-deleted) migrations left order=0; order is NOT score-relevant
    // (the synthesis ignores it), so a one-time ROW_NUMBER() backfill is safe.
    if (!get_option('bcc_trust_attestation_order_backfilled')) {
        bcc_trust_backfill_attestation_order();
        update_option('bcc_trust_attestation_order_backfilled', time(), false);
    }
}

/**
 * Backfill attestation_order_in_target for ALL attestation rows
 * (Slice E). The legacy + post_vouch migrations insert order=0; this
 * one-time pass assigns a deterministic per-target ordinal via
 * ROW_NUMBER() OVER (PARTITION BY target_kind, target_id ORDER BY
 * created_at, id) (MySQL 8).
 *
 * Order is NOT score-relevant — AttestationScoreSynthesis ignores it —
 * so rewriting every row is safe; it only fixes the migrations'
 * placeholder 0. Native casts already set a correct order via
 * nextOrderInTarget; re-deriving them from (created_at, id) preserves
 * their relative ordering.
 */
function bcc_trust_backfill_attestation_order(): void {
    global $wpdb;

    $attestations_table = \BCC\Trust\Core\Database\TableRegistry::trustAttestations();

    $sql = "UPDATE {$attestations_table} a
        JOIN (
            SELECT id,
                   ROW_NUMBER() OVER (
                       PARTITION BY target_kind, target_id
                       ORDER BY created_at, id
                   ) AS rn
            FROM {$attestations_table}
        ) r ON r.id = a.id
        SET a.attestation_order_in_target = r.rn";

    $wpdb->query($sql);
}

