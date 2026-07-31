<?php
/**
 * Rank State Repository — owns `wp_bcc_trust_rank_state`, the canonical
 * Rank progression row (Rank Phase 5).
 *
 * Missing row = New Member (fail-safe deny). Rows are written ONLY by
 * the promotion engine (award/materialize) — the append-only
 * rank_events ledger stays authoritative and this row reproducible
 * from it (§24.1). Reads back every rank_label view-model emitter via
 * RankStateService, batched through getForUsers for list surfaces
 * (MemberSummaryPrefetcher seam — never per-row).
 *
 * @package BCC\Trust\Rank\Repositories
 * @since Rank redesign Phase 5 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type RankStateRow object{
 *     user_id: numeric-string,
 *     rank_slug: string,
 *     apprentice_awarded_at: string,
 *     journeyman_awarded_at: string|null,
 *     veteran_awarded_at: string|null,
 *     score: numeric-string,
 *     cat_contribution: numeric-string,
 *     cat_helping: numeric-string,
 *     cat_recognition: numeric-string,
 *     cat_outcomes: numeric-string,
 *     cat_time: numeric-string,
 *     recovery_status: string,
 *     recovery_deadline: string|null
 * }
 */
class RankStateRepository
{
    /** Cache group for per-user rank-state generation counters (§5). */
    private const CACHE_GROUP = 'bcc_rank_state';

    private const COLUMNS = 'user_id, rank_slug, apprentice_awarded_at, journeyman_awarded_at, veteran_awarded_at, score, cat_contribution, cat_helping, cat_recognition, cat_outcomes, cat_time, recovery_status, recovery_deadline';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::rankState();
    }

    /**
     * @phpstan-return RankStateRow|null Null = New Member.
     */
    public function getForUser(int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }

        global $wpdb;

        /** @var RankStateRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE user_id = %d",
            $userId
        ));

        return $row;
    }

    /**
     * Batched rank read for list surfaces — one bounded IN SELECT.
     * Absent users are New Members (callers default to null rank).
     *
     * @param list<int> $userIds
     * @return array<int, string> user_id => rank_slug
     */
    public function getRanksForUsers(array $userIds): array
    {
        $clean = [];
        foreach ($userIds as $id) {
            $iid = (int) $id;
            if ($iid > 0) {
                $clean[$iid] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $ids = array_slice(array_keys($clean), 0, 500);

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        /** @var list<object{user_id: numeric-string, rank_slug: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, rank_slug FROM {$this->table}
              WHERE user_id IN ({$placeholders})
              LIMIT 500",
            ...$ids
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->user_id] = (string) $row->rank_slug;
        }
        return $map;
    }

    /**
     * Award the initial Apprentice row. INSERT IGNORE — a concurrent
     * sweep tick cannot double-award (PK user_id).
     *
     * @return bool True when the row was created by THIS call.
     */
    public function awardApprentice(int $userId): bool
    {
        global $wpdb;
        $now = current_time('mysql', true);

        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table}
                (user_id, rank_slug, apprentice_awarded_at, updated_at)
             VALUES (%d, 'apprentice', %s, %s)",
            $userId,
            $now,
            $now
        ));

        $created = $result !== false && (int) $result > 0;
        if ($created) {
            $this->invalidateCache($userId);
        }
        return $created;
    }

    /**
     * Materialize computed scores + (possibly changed) rank. The engine
     * owns transition semantics; this persists them. Stamps the
     * awarded-at column only on an upward transition into that rung.
     *
     * @param array<string, float> $categories category => score
     */
    public function persistEvaluation(
        int $userId,
        string $rankSlug,
        float $score,
        array $categories,
        string $recoveryStatus,
        ?string $recoveryDeadline
    ): bool {
        global $wpdb;
        $now = current_time('mysql', true);

        $stamp = '';
        if ($rankSlug === 'journeyman') {
            $stamp = ', journeyman_awarded_at = COALESCE(journeyman_awarded_at, %s)';
        } elseif ($rankSlug === 'veteran') {
            $stamp = ', journeyman_awarded_at = COALESCE(journeyman_awarded_at, %s), veteran_awarded_at = COALESCE(veteran_awarded_at, %s)';
        }

        $sql = "UPDATE {$this->table}
                   SET rank_slug = %s,
                       score = %f,
                       cat_contribution = %f,
                       cat_helping = %f,
                       cat_recognition = %f,
                       cat_outcomes = %f,
                       cat_time = %f,
                       recovery_status = %s,
                       recovery_deadline = " . ($recoveryDeadline !== null ? '%s' : 'NULL') . ",
                       updated_at = %s
                       {$stamp}
                 WHERE user_id = %d";

        $params = [
            $rankSlug,
            $score,
            $categories['contribution'] ?? 0.0,
            $categories['helping'] ?? 0.0,
            $categories['recognition'] ?? 0.0,
            $categories['outcomes'] ?? 0.0,
            $categories['time'] ?? 0.0,
            $recoveryStatus,
        ];
        if ($recoveryDeadline !== null) {
            $params[] = $recoveryDeadline;
        }
        $params[] = $now;
        if ($rankSlug === 'journeyman') {
            $params[] = $now;
        } elseif ($rankSlug === 'veteran') {
            $params[] = $now;
            $params[] = $now;
        }
        $params[] = $userId;

        $result = $wpdb->query($wpdb->prepare($sql, ...$params));
        if ($result === false) {
            return false;
        }

        $this->invalidateCache($userId);
        return true;
    }

    /**
     * Page of ranked user ids after a cursor — the daily evaluate
     * sweep's iteration source.
     *
     * @return list<int>
     */
    public function listUserIdsAfter(int $cursor, int $limit): array
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$this->table} WHERE user_id > %d ORDER BY user_id ASC LIMIT %d",
            max(0, $cursor),
            max(1, $limit)
        ));

        return array_values(array_map('intval', $ids ?: []));
    }

    /** §5 generation-counter bump for rank-state read caches. */
    private function invalidateCache(int $userId): void
    {
        $genKey = "rank_state_gen:{$userId}";
        $result = wp_cache_incr($genKey, 1, self::CACHE_GROUP);
        if ($result === false) {
            wp_cache_set($genKey, 2, self::CACHE_GROUP, 0);
        }
    }
}
