<?php
/**
 * Stoke Repository
 *
 * Owns reads + writes against `bcc_trust_stokes` — one row per
 * (act_id, user_id), enforced by a unique key, so the row's mere
 * existence IS the stoke (X-"like" model: one stoke per person, no
 * counter). `stoke_count` is a vestigial always-1 column kept from the
 * original accumulate-and-cap design rather than dropped in an ALTER
 * TABLE — every read here now treats presence/COUNT(*) as the signal,
 * not the column's value. Combined read+write in one repository,
 * matching this codebase's convention for simple per-user-per-target
 * signal tables (see PageFlagRepository) — unlike PeepSoReactionRepository,
 * which is read-only because PeepSo owns that table's write path;
 * bcc-trust owns this one outright.
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
     * Add $userId's one stoke on $actId. Idempotent insert: the unique
     * key on (act_id, user_id) means a second POST from the same user
     * just hits the duplicate and no-ops (INSERT IGNORE) rather than
     * accumulating — one stoke per person, X-"like" semantics, not a
     * counter anymore.
     *
     * @return bool True on success (including the already-stoked no-op).
     */
    public function addStoke(int $actId, int $userId): bool
    {
        if ($actId <= 0 || $userId <= 0) {
            return false;
        }

        global $wpdb;
        $now = current_time('mysql', true);

        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table} (act_id, user_id, stoke_count, last_stoked_at, created_at)
                  VALUES (%d, %d, 1, %s, %s)",
            $actId,
            $userId,
            $now,
            $now
        ));

        return $result !== false;
    }

    /**
     * Remove $userId's stoke on $actId. Deletes the row outright (one
     * stoke per person — there's no count to decrement anymore).
     * Idempotent: no-op when the viewer has no stoke on this act.
     */
    public function removeStoke(int $actId, int $userId): bool
    {
        if ($actId <= 0 || $userId <= 0) {
            return false;
        }

        global $wpdb;

        $result = $wpdb->delete(
            $this->table,
            ['act_id' => $actId, 'user_id' => $userId],
            ['%d', '%d']
        );

        return $result !== false;
    }

    /**
     * Does $userId have a stoke on $actId. Drives the rail's fill
     * (ash <-> orange) — the personal axis, orthogonal to heat_stage.
     */
    public function viewerHasStoked(int $actId, int $userId): bool
    {
        if ($actId <= 0 || $userId <= 0) {
            return false;
        }

        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table} WHERE act_id = %d AND user_id = %d LIMIT 1",
            $actId,
            $userId
        ));

        return $exists !== null;
    }

    /**
     * Batched form of viewerHasStoked for feed-page hydration.
     *
     * @param list<int> $actIds
     * @return array<int, true> act_id => true for every activity this
     *   viewer has stoked. Activities absent from the map mean false —
     *   callers default missing entries to false.
     */
    public function viewerStokedActIds(int $viewerId, array $actIds): array
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
            "SELECT act_id FROM {$this->table}
              WHERE user_id = %d AND act_id IN ({$idList})",
            $viewerId
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $actId = (int) $row->act_id;
            if ($actId > 0) {
                $out[$actId] = true;
            }
        }
        return $out;
    }

    /**
     * Total distinct stokers on one activity (the public count shown
     * beside the flame). All-time — unlike heat_stage, this never decays.
     */
    public function countForActivity(int $actId): int
    {
        if ($actId <= 0) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE act_id = %d",
            $actId
        ));
    }

    /**
     * Batched form of countForActivity for feed-page hydration.
     *
     * @param list<int> $actIds
     * @return array<int, int> act_id => total distinct stokers.
     *   Activities with zero stokes are absent — callers default to 0.
     */
    public function countsByActIds(array $actIds): array
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

        $rows = $wpdb->get_results(
            "SELECT act_id, COUNT(*) AS total
               FROM {$this->table}
              WHERE act_id IN ({$idList})
              GROUP BY act_id"
        );

        $out = [];
        foreach ($rows ?: [] as $row) {
            $actId = (int) $row->act_id;
            if ($actId > 0) {
                $out[$actId] = (int) $row->total;
            }
        }
        return $out;
    }

    /**
     * Batched heat_stage (1-5) for feed-page hydration. Velocity score
     * is COUNT(*) within the decay window — distinct stokers, recency-
     * weighted by the window rather than by any single user's repeat
     * taps (there are none anymore — one stoke per person). Stage
     * thresholds are config (includes/config/stoke.php), tunable
     * without a deploy.
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
            "SELECT act_id, COUNT(*) AS total
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
