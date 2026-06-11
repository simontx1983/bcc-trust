<?php
/**
 * TargetDivergenceStateRepository — bounded reads/writes against the
 * `bcc_target_divergence_state` sidecar table.
 *
 * Drives the `PolarizationTransitionNotifier`'s prior-state memory +
 * its 24-hour per-(recipient, target, state) coalescing window.
 *
 * **Idiom + safety posture:** mirrors the locked AttestationRepository
 * patterns — `TableRegistry::targetDivergenceState()` for the table
 * name, `$wpdb->prepare()` with `is_string` guard, `LIMIT` belt-and-
 * suspenders on every read, no `SELECT *`.
 *
 * **Cache:** none. The sweep runs daily and reads + writes per target
 * are O(N) where N is the candidate set (typically dozens to hundreds
 * at V1 scale). Adding a cache here would inflate complexity for
 * unmeasurable gain.
 *
 * @package BCC\Trust\Core\Repositories
 * @since V2 Trust Attestation Layer PR-8b (2026-05-14)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;
use wpdb;

if (!defined('ABSPATH')) {
    exit;
}

final class TargetDivergenceStateRepository
{
    private const COLUMNS = 'id, target_kind, target_id, current_state, computed_at, last_notified_at';

    /**
     * Fetch the stored row for a target, or null if never classified.
     *
     * The returned object exposes (when present): id, target_kind,
     * target_id, current_state, computed_at, last_notified_at — all
     * coming from `$wpdb->get_row` so they arrive as `string|null`.
     * Callers cast on read; see PolarizationTransitionNotifier for
     * the canonical consumption pattern. Mirrors the generic `?object`
     * return on AttestationRepository::findOneById.
     */
    public function findByTarget(string $targetKind, int $targetId): ?object
    {
        if ($targetKind === '' || $targetId <= 0) {
            return null;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::targetDivergenceState();

        $sql = 'SELECT ' . self::COLUMNS
            . " FROM `{$table}`"
            . ' WHERE target_kind = %s'
            . ' AND target_id = %d'
            . ' LIMIT 1';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $targetKind, $targetId);
        if (!is_string($prepared)) {
            return null;
        }

        $row = $wpdb->get_row($prepared);
        return is_object($row) ? $row : null;
    }

    /**
     * Insert or update the row for a target. Idempotent under racing
     * cron writes via ON DUPLICATE KEY (the uq_target unique index
     * makes the upsert safe).
     *
     * Does NOT bump `last_notified_at` — that's a separate concern
     * driven by the notifier's actual dispatch decision, not by every
     * classification. Use `markNotified()` for the notify path.
     */
    public function upsertState(string $targetKind, int $targetId, string $state): void
    {
        if ($targetKind === '' || $targetId <= 0 || $state === '') {
            return;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::targetDivergenceState();
        $now   = gmdate('Y-m-d H:i:s');

        $sql = "INSERT INTO `{$table}` (target_kind, target_id, current_state, computed_at)"
            . ' VALUES (%s, %d, %s, %s)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' current_state = VALUES(current_state),'
            . ' computed_at = VALUES(computed_at)';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $targetKind, $targetId, $state, $now);
        if (!is_string($prepared)) {
            return;
        }
        $wpdb->query($prepared);
    }

    /**
     * Stamp `last_notified_at = NOW()` for a target. Called from the
     * notifier after a successful dispatch — feeds the 24h coalescing
     * check on the next sweep. Row MUST already exist (upsertState was
     * called first); silently no-ops on missing rows for defensiveness.
     */
    public function markNotified(string $targetKind, int $targetId): void
    {
        if ($targetKind === '' || $targetId <= 0) {
            return;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::targetDivergenceState();
        $now   = gmdate('Y-m-d H:i:s');

        $sql = "UPDATE `{$table}`"
            . ' SET last_notified_at = %s'
            . ' WHERE target_kind = %s'
            . ' AND target_id = %d'
            . ' LIMIT 1';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $now, $targetKind, $targetId);
        if (!is_string($prepared)) {
            return;
        }
        $wpdb->query($prepared);
    }

    /**
     * Hard-delete the divergence-state sidecar rows for a deleted page —
     * rows whose `target_id` is the page and whose `target_kind` is one of
     * the card kinds. Mirrors AttestationRepository::deleteForPageTarget so
     * the §J.8 notifier's prior-state memory doesn't keep a dangling row
     * for a target that no longer exists.
     *
     * Reuses AttestationRepository::PAGE_TARGET_KINDS as the single source
     * of truth for "which target_kinds are page-keyed" — the two cleanups
     * must never diverge.
     *
     * Called from UserLifecycleService::onPageDelete (before_delete_post).
     */
    public function deleteForPageTarget(int $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::targetDivergenceState();

        $kinds            = AttestationRepository::PAGE_TARGET_KINDS;
        $kindPlaceholders = implode(',', array_fill(0, count($kinds), '%s'));
        $sql = "DELETE FROM `{$table}`"
            . ' WHERE target_id = %d'
            . " AND target_kind IN ({$kindPlaceholders})";

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $pageId, ...$kinds);
        if (!is_string($prepared)) {
            return;
        }
        $wpdb->query($prepared);
    }
}
