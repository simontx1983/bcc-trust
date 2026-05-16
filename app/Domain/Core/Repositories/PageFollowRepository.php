<?php
/**
 * Page Follow Repository
 *
 * CRUD for `bcc_page_follows` — BCC's own page-keyed follow table.
 * Used exclusively for pre-claim placeholder pages (post_author=0)
 * where PeepSo's user→user follow model has no human to bind to.
 *
 * After an operator claims the page,
 * `PeepSoIntegration::onPageClaimed` migrates each row here into a
 * real PeepSo follow via `PeepSoFollowWriter::follow`, then deletes
 * the row from this table. So the canonical post-claim store stays
 * `peepso_user_friendships` + `bcc_pull_meta` — this table is the
 * pre-claim holding pen only.
 *
 * Architecture per §1: this repository owns the `bcc_page_follows`
 * table only. Cross-table queries (e.g., watchlist that UNIONs
 * with PeepSo follows) live in `WatchingRepository` which composes
 * both data sources.
 *
 * @package BCC\Trust\Core\Repositories
 * @since V1.6 (validator directory backfill, 2026-05-13)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type PageFollowRow object{
 *     id: numeric-string,
 *     user_id: numeric-string,
 *     page_id: numeric-string,
 *     card_kind: string,
 *     tier_at_pull: string|null,
 *     created_at: string
 * }
 */
final class PageFollowRepository
{
    /** Explicit column list — must match schema-page-follows.php. */
    private const COLUMNS = 'id, user_id, page_id, card_kind, tier_at_pull, created_at';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::pageFollows();
    }

    /**
     * Atomic insert-or-find via `INSERT … ON DUPLICATE KEY UPDATE
     * id = LAST_INSERT_ID(id)`. Always returns the row id.
     *
     * Returns `['id' => 0, 'inserted' => false]` on hard failure
     * (the WatchingService caller distinguishes "DB error" from
     * "already follows" by checking the rows_affected delta:
     * 1 = inserted, 2 = duplicate.
     */
    /** @return array{id: int, inserted: bool} */
    public function insertOrFind(int $userId, int $pageId, string $cardKind, ?string $tierAtPull): array
    {
        if ($userId <= 0 || $pageId <= 0 || $cardKind === '') {
            return ['id' => 0, 'inserted' => false];
        }

        global $wpdb;

        // id = LAST_INSERT_ID(id) on duplicate makes $wpdb->insert_id
        // return the existing row's ID, giving us a single round-trip
        // atomic upsert. Same idiom WalletRepository uses.
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->table}
                (user_id, page_id, card_kind, tier_at_pull)
             VALUES (%d, %d, %s, %s)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
            $userId,
            $pageId,
            $cardKind,
            $tierAtPull
        ));

        if ($result === false) {
            return ['id' => 0, 'inserted' => false];
        }

        return [
            'id'       => (int) $wpdb->insert_id,
            // rows_affected: 1 = inserted, 2 = duplicate-update triggered.
            'inserted' => ((int) $wpdb->rows_affected === 1),
        ];
    }

    /**
     * Find a page-follow row by id. Used during unpull to verify the
     * row belongs to the viewer before deleting.
     *
     * @phpstan-return PageFollowRow|null
     */
    public function findById(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        global $wpdb;

        /** @var PageFollowRow|null */
        return $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE id = %d LIMIT 1",
            $id
        ));
    }

    /**
     * Resolve a (user_id, page_id) → row. Used by `WatchingService::pull`
     * for the idempotency check.
     *
     * @phpstan-return PageFollowRow|null
     */
    public function findByUserAndPage(int $userId, int $pageId): ?object
    {
        if ($userId <= 0 || $pageId <= 0) {
            return null;
        }

        global $wpdb;

        /** @var PageFollowRow|null */
        return $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE user_id = %d AND page_id = %d
              LIMIT 1",
            $userId,
            $pageId
        ));
    }

    /**
     * Page-follows belonging to one user, ordered newest-first.
     * Bounded by limit/offset; caller paginates. The WatchingRepository
     * composes this with PeepSo follows into a unified watchlist view.
     *
     * @phpstan-return list<PageFollowRow>
     */
    public function findByUserId(int $userId, int $limit, int $offset): array
    {
        if ($userId <= 0) {
            return [];
        }

        global $wpdb;

        /** @var list<PageFollowRow>|null */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE user_id = %d
              ORDER BY created_at DESC, id DESC
              LIMIT %d OFFSET %d",
            $userId,
            max(1, min($limit, 100)),
            max(0, $offset)
        ));

        return $rows ?: [];
    }

    /**
     * Count page-follows for one user. Cheap companion to
     * `findByUserId` used in pagination totals.
     */
    public function countByUserId(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE user_id = %d",
            $userId
        ));
    }

    /**
     * Every page-follow row for a single page. Called by the
     * onPageClaimed migration: we need each (user_id, tier_at_pull)
     * to materialize the corresponding PeepSo follow + pull_meta row.
     * Bounded at 5000 — extremely defensive, since a single placeholder
     * is unlikely to accrue more than a few dozen pre-claim followers.
     *
     * @phpstan-return list<PageFollowRow>
     */
    public function findByPageId(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }

        global $wpdb;

        /** @var list<PageFollowRow>|null */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE page_id = %d
              ORDER BY created_at ASC, id ASC
              LIMIT 5000",
            $pageId
        ));

        return $rows ?: [];
    }

    /**
     * Delete by row id. Returns rows-deleted (0 or 1) or false on
     * DB error. Used by `WatchingService::unpull` and the claim-time
     * migration cleanup.
     *
     * @return int|false
     */
    public function deleteById(int $id): int|false
    {
        if ($id <= 0) {
            return false;
        }

        global $wpdb;

        return $wpdb->delete($this->table, ['id' => $id], ['%d']);
    }

    /**
     * Delete every page-follow row for one page. Called by the
     * onPageClaimed migration once each row has been promoted to a
     * PeepSo follow. Returns rows-deleted.
     */
    public function deleteByPageId(int $pageId): int
    {
        if ($pageId <= 0) {
            return 0;
        }

        global $wpdb;

        $deleted = $wpdb->delete($this->table, ['page_id' => $pageId], ['%d']);
        return $deleted !== false ? (int) $deleted : 0;
    }
}
