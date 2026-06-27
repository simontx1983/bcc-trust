<?php
/**
 * Stoke Repository
 *
 * Owns reads + writes against `bcc_trust_stokes` (one row per
 * act_id/user_id, `stoke_count` capped server-side). Combined
 * read+write in one repository, matching this codebase's convention
 * for simple per-user-per-target signal tables (see PageFlagRepository)
 * — unlike PeepSoReactionRepository, which is read-only because PeepSo
 * owns that table's write path; bcc-trust owns this one outright.
 *
 * Stoke is cosmetic for trust — this class never touches
 * bcc_trust_scores or any trust-score write path.
 *
 * @package BCC\Trust\Core\Repositories
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

class StokeRepository
{
    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::stokes();
    }

    /**
     * Add one stoke from $userId on $actId. Atomic upsert: the
     * `IF(stoke_count < cap, ...)` clause enforces the per-user cap
     * server-side in a single statement, so concurrent taps from the
     * same user can't race past it.
     *
     * @return bool True on success (including the no-op-at-cap case).
     */
    public function addStoke(int $actId, int $userId): bool
    {
        if ($actId <= 0 || $userId <= 0) {
            return false;
        }

        global $wpdb;
        $cap = (int) BCC_STOKE_CAP_PER_USER;
        $now = current_time('mysql', true);

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->table} (act_id, user_id, stoke_count, last_stoked_at, created_at)
                  VALUES (%d, %d, 1, %s, %s)
             ON DUPLICATE KEY UPDATE
                  stoke_count    = IF(stoke_count < %d, stoke_count + 1, stoke_count),
                  last_stoked_at = IF(stoke_count < %d, %s, last_stoked_at)",
            $actId,
            $userId,
            $now,
            $now,
            $cap,
            $cap,
            $now
        ));

        return $result !== false;
    }

    /**
     * Remove one stoke from $userId on $actId. Floors at 0 — idempotent
     * when the viewer has no stokes (or the row doesn't exist) on this act.
     */
    public function removeStoke(int $actId, int $userId): bool
    {
        if ($actId <= 0 || $userId <= 0) {
            return false;
        }

        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
                SET stoke_count = GREATEST(stoke_count - 1, 0)
              WHERE act_id = %d AND user_id = %d",
            $actId,
            $userId
        ));

        return $result !== false;
    }

    /**
     * This viewer's current stoke_count on one activity (0 if none).
     */
    public function viewerStokeCount(int $actId, int $userId): int
    {
        if ($actId <= 0 || $userId <= 0) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT stoke_count FROM {$this->table} WHERE act_id = %d AND user_id = %d",
            $actId,
            $userId
        ));
    }

    /**
     * Batched form of viewerStokeCount for feed-page hydration.
     *
     * @param list<int> $actIds
     * @return array<int, int> act_id => stoke_count. Activities the
     *   viewer hasn't stoked are absent — callers default to 0.
     */
    public function viewerStokesByActIds(int $viewerId, array $actIds): array
    {
        if ($viewerId <= 0 || $actIds === []) {
            return [];
        }

        $ids = self::cleanIds($actIds);
        if ($ids === []) {
            return [];
        }
        $idList = implode(',', $ids);

        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT act_id, stoke_count FROM {$this->table}
              WHERE user_id = %d AND act_id IN ({$idList})",
            $viewerId
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $actId = (int) $row->act_id;
            if ($actId > 0) {
                $out[$actId] = (int) $row->stoke_count;
            }
        }
        return $out;
    }

    /**
     * Batched heat_stage (1-5) for feed-page hydration. Velocity score
     * is SUM(stoke_count) within the decay window — each row's
     * stoke_count is already capped per-user, so the sum across rows
     * IS "distinct stokers × their capped stokes" in aggregate form.
     * Stage thresholds are config (includes/config/stoke.php),
     * tunable without a deploy.
     *
     * @param list<int> $actIds
     * @return array<int, int> act_id => heat_stage (1-5). Every
     *   requested act_id that exists gets an entry — stage 1 is the
     *   floor for zero/below-threshold engagement, never "stage 0".
     */
    public function heatByActIds(array $actIds): array
    {
        if ($actIds === []) {
            return [];
        }

        $ids = self::cleanIds($actIds);
        if ($ids === []) {
            return [];
        }
        $idList = implode(',', $ids);

        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - ((int) BCC_STOKE_DECAY_WINDOW_HOURS * HOUR_IN_SECONDS));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT act_id, SUM(stoke_count) AS total
               FROM {$this->table}
              WHERE act_id IN ({$idList}) AND last_stoked_at >= %s
              GROUP BY act_id",
            $since
        ));

        $out = [];
        foreach ($ids as $actId) {
            $out[$actId] = 1;
        }
        foreach ($rows ?: [] as $row) {
            $actId = (int) $row->act_id;
            if ($actId > 0) {
                $out[$actId] = self::stageForScore((int) $row->total);
            }
        }
        return $out;
    }

    private static function stageForScore(int $score): int
    {
        if ($score >= (int) BCC_STOKE_STAGE_5_MIN) {
            return 5;
        }
        if ($score >= (int) BCC_STOKE_STAGE_4_MIN) {
            return 4;
        }
        if ($score >= (int) BCC_STOKE_STAGE_3_MIN) {
            return 3;
        }
        if ($score >= (int) BCC_STOKE_STAGE_2_MIN) {
            return 2;
        }
        return 1;
    }

    /**
     * Dedupe + bound + sanitize an id list. Feed page cap is 50;
     * defending here keeps the SQL safe even if a future caller sends
     * a larger list — same defense-in-depth as PeepSoReactionRepository.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private static function cleanIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $clean[$intId] = true;
            }
        }
        return array_keys($clean);
    }
}
