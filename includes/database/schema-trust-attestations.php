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
 * Migration: §J.11 — existing bcc_endorsements rows materialize as
 * kind=vouch with target_kind matching the source post_type. This
 * runs idempotently inside the activation hook; the unique key
 * prevents duplicate migration on re-activation.
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

    // §J.11 migration: materialize legacy endorsements as
    // kind=vouch attestations. Idempotent — guarded by a
    // one-time option + the unique key on the attestations table.
    if (!get_option('bcc_trust_attestation_legacy_endorsement_migrated')) {
        bcc_trust_migrate_legacy_endorsements_to_attestations();
        update_option('bcc_trust_attestation_legacy_endorsement_migrated', time(), false);
    }
}

/**
 * Migrate legacy bcc_endorsements rows into bcc_trust_attestations.
 *
 * Per §J.11: existing endorsement rows materialize as
 * kind=vouch with target_kind in the *_card set. Idempotent —
 * INSERT IGNORE skips rows that already exist (the unique key on
 * the attestations table catches them). Re-running is safe.
 *
 * The migration is intentionally additive: legacy endorsement rows
 * remain in bcc_trust_endorsements; the new attestation rows are a
 * parallel copy. The FE prefers the new shape via viewer_attestation
 * but the legacy boolean viewer_has_endorsed continues to work for
 * the Phase 1 Endorse→Vouch fallback per §J.11.
 */
function bcc_trust_migrate_legacy_endorsements_to_attestations(): void {
    global $wpdb;

    $endorsements_table = \BCC\Trust\Core\Database\TableRegistry::endorsements();
    $attestations_table = \BCC\Trust\Core\Database\TableRegistry::trustAttestations();

    // Determine target_kind from post_type. Only *_card kinds get
    // migrated — endorsements against pages with unrecognized
    // post_types are skipped (no target_kind to apply).
    //
    // We use INSERT IGNORE so re-running is safe: the unique key on
    // the attestations table catches duplicates. The CASE expression
    // resolves the target_kind from the page's post_type at
    // migration time.
    //
    // Phase 1 plan §5.3 SQL with the consistency-audit reconciliation
    // applied: the WHERE clause filters to known post_types only;
    // revoked_at is copied through verbatim (no redundant tautology).
    //
    // attestation_order_in_target is left at the default 0 here; a
    // followup recompute (when the synthesis layer ships) will
    // populate it via ROW_NUMBER() OVER (PARTITION BY target_kind,
    // target_id ORDER BY created_at).
    $sql = "INSERT IGNORE INTO {$attestations_table} (
        attestor_user_id, target_kind, target_id, kind,
        weight_at_time, attestation_order_in_target,
        created_at, reaffirmed_at, revoked_at
    )
    SELECT
        e.endorser_user_id,
        CASE
            WHEN p.post_type = 'peepso-validator' THEN 'validator_card'
            WHEN p.post_type = 'peepso-project'   THEN 'project_card'
            WHEN p.post_type = 'peepso-creator'   THEN 'creator_card'
            ELSE NULL
        END AS target_kind,
        e.page_id AS target_id,
        'vouch' AS kind,
        e.weight AS weight_at_time,
        0 AS attestation_order_in_target,
        e.created_at,
        NULL AS reaffirmed_at,
        CASE WHEN e.status = 1 THEN NULL ELSE e.created_at END AS revoked_at
    FROM {$endorsements_table} e
    JOIN {$wpdb->posts} p ON p.ID = e.page_id
    WHERE p.post_type IN ('peepso-validator', 'peepso-project', 'peepso-creator')";

    $wpdb->query($sql);
}
