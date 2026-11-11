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
 * `peepso_user_followers` + `bcc_watch_meta` — this table is the
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
use BCC\Trust\Core\Exceptions\RepositoryException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type PageFollowRow object{
 *     id: numeric-string,
 *     user_id: numeric-string,
 *     page_id: numeric-string,
 *     card_kind: string,
 *     tier_at_watch: string|null,
 *     created_at: string
 * }
 */
final class PageFollowRepository
{
    /** Explicit column list — must match schema-page-follows.php. */
    private const COLUMNS = 'id, user_id, page_id, card_kind, tier_at_watch, created_at';

    /** One read page of follow rows; also bounds deleteByIds(). */
    public const DRAIN_PAGE_SIZE = 5000;

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
    public function insertOrFind(int $userId, int $pageId, string $cardKind, ?string $tierAtWatch): array
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
                (user_id, page_id, card_kind, tier_at_watch)
             VALUES (%d, %d, %s, %s)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
            $userId,
            $pageId,
            $cardKind,
            $tierAtWatch
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
     * Find a page-follow row by id. Used during unwatch to verify the
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
     * Resolve a (user_id, page_id) → row. Used by `WatchingService::watch`
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
     * onPageClaimed migration: we need each (user_id, tier_at_watch)
     * to materialize the corresponding PeepSo follow + watch_meta row.
     * Bounded at 5000 — extremely defensive, since a single placeholder
     * is unlikely to accrue more than a few dozen pre-claim followers.
     *
     * @phpstan-return list<PageFollowRow>
     * @throws RepositoryException when the read itself failed, so the caller
     *         cannot mistake a broken query for a page with no followers
     */
    public function findByPageId(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }

        global $wpdb;

        // Can a failed read be told apart from an empty one? YES, and only
        // this way. `get_results()` returns an empty array for BOTH "this
        // page has no followers" and "the query errored", and the drain's
        // loop reads `[]` as "nothing left to do" and stops — so a failed
        // read looked exactly like a completed drain: no warning, no retry
        // owed, rows silently left behind. `last_error` is the one thing
        // that separates them, and it is cleared immediately before the read
        // so a stale error from an unrelated earlier query cannot be
        // attributed to this one (wpdb::query() flushes it the same way).
        //
        // Fail CLOSED by throwing, rather than returning a sentinel: the
        // caller is a claim-time hook and the safe answer to "I could not
        // read the follow table" is to stop and leave the rows alone.
        // Same idiom as PageReadModelRepository::readScoreRowOrThrow().
        $sql = $wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE page_id = %d
              ORDER BY created_at ASC, id ASC
              LIMIT " . self::DRAIN_PAGE_SIZE . "",
            $pageId
        );

        // An unbuildable statement is a failure in its own right, and one
        // WordPress reports WITHOUT setting an error.
        if ($sql === null || $sql === '') {
            throw new RepositoryException('page_follows_read_not_prepared');
        }

        $wpdb->last_error = '';

        /** @var list<PageFollowRow>|null $rows */
        $rows = $wpdb->get_results($sql);

        // Local copy + strlen: PHPStan's wpdb stub narrows last_error to the
        // literal '' (same workaround as readScoreRowOrThrow()).
        $lastError = (string) $wpdb->last_error;
        if (strlen($lastError) > 0) {
            throw new RepositoryException('page_follows_read_failed');
        }

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
     * Delete exactly the rows named, and nothing else.
     *
     * ⚠ The companion to {@see deleteByPageId()}, which deletes EVERY row
     * for a page. The follower drain must only remove rows it actually
     * handled: a row whose PeepSo follow failed has to survive for the next
     * attempt, and a page with more rows than one read page must not have
     * the unread remainder destroyed.
     *
     * Bounded (§4): the id list is capped at one read page's worth, and the
     * caller passes ids it just read.
     *
     * ⚠ Returns `false` on a failed query, NOT 0. Collapsing the two made
     * the drain's summary log claim `deleted: 0` for both "the DELETE matched
     * nothing" and "the DELETE errored and every row is still there" — and
     * the caller, seeing no failure, re-read the same full page and migrated
     * every row again. {@see deleteById()} has carried `int|false` for the
     * same reason since it was written.
     *
     * @param list<int> $ids
     * @return int|false rows deleted, or false when the DELETE itself failed
     */
    public function deleteByIds(array $ids): int|false
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = true;
            }
        }
        if ($clean === []) {
            return 0;
        }

        $list = array_slice(array_keys($clean), 0, self::DRAIN_PAGE_SIZE);

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($list), '%d'));
        $deleted      = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE id IN ({$placeholders})",
            ...$list
        ));

        return is_int($deleted) ? $deleted : false;
    }

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
