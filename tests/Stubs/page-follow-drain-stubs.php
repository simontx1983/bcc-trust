<?php
/**
 * Fixture-backed stubs for PageFollowDrainTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses).
 * Fakes Plugin::instance()'s two repositories, the PeepSo follow writer and
 * the logger at their FQNs, so the REAL
 * PeepSoIntegration::migratePageFollowersToPeepSo() loop runs against an
 * in-memory follow table.
 *
 * ⚠ The two database-failure knobs model the REAL return contracts, not
 * convenient ones: `findByPageId()` THROWS RepositoryException (it cannot
 * return a sentinel, because `[]` already means "no followers"), and
 * `deleteByIds()` returns `false` (it cannot return 0, because 0 already
 * means "matched nothing"). A stub that softened either would make the fix
 * untestable in the exact place it was needed.
 *
 * Fixture shape ($GLOBALS['__bcc_drain']):
 *   rows           array<int, object>  id => follow row (the "table")
 *   fail_follow    list<int>           viewer ids whose follow() returns 0
 *   throw_follow   list<int>           viewer ids whose follow() throws
 *   fail_read      int                 pass number whose read throws (0 = never)
 *   fail_delete    int                 pass number whose delete fails (0 = never)
 *   delete_matches_nothing bool        delete succeeds but removes no rows
 *   reads          int                 findByPageId() call count
 *   follows        int                 PeepSoFollowWriter::follow() call count
 *   delete_calls   list<int>           handled-id counts passed to deleteByIds()
 *   deleted        list<int>           ids actually removed
 *   by_page_id     int                 deleteByPageId() call count (MUST stay 0)
 *   meta           list<int>           follow ids given watch-meta rows
 *   info           list<array>         Logger::info contexts
 *   errors         list<array>         Logger::error [message, context]
 */

declare(strict_types=1);

namespace {
    // F-334: what a failed wp_update_post(..., true) returns.
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            public function __construct(private string $code = '', private string $message = '')
            {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

    if (!function_exists('__bcc_drain_state')) {
        function __bcc_drain_state(): array { return $GLOBALS['__bcc_drain']; }
    }

    // F-299: PeepSo Pages' membership model, backed by
    // $GLOBALS['__bcc_drain']['page_members'][pageId][userId] = status.
    // Mirrors the real one: member_join() inserts `member` only when there is
    // no row (an existing row is re-set to `member`); member_modify() updates
    // an EXISTING row only (an UPDATE that matches nothing still "succeeds");
    // both re-read the flags. Knobs: `fail_join`, `fail_modify` (bool).
    //
    // F-360: a pair may hold SEVERAL rows (no unique (page, user) key): give
    // `page_members[pageId][userId]` a list of statuses in pm_id order. Like
    // the real model, init() then reads ONE row (`LIMIT 1`, no ORDER BY —
    // modelled as the first), and member_modify() rewrites EVERY row of the
    // pair (its UPDATE matches on page + user only).
    if (!function_exists('__bcc_pm_rows')) {
        /** @return list<string> the pair's statuses in pm_id order */
        function __bcc_pm_rows(int $pageId, int $userId): array
        {
            $rows = $GLOBALS['__bcc_drain']['page_members'][$pageId][$userId] ?? [];

            return is_array($rows) ? array_values($rows) : [$rows];
        }
    }
    if (!class_exists('PeepSoPageUser', false)) {
        class PeepSoPageUser
        {
            /** @var string|false */
            public $role = false;
            public bool $is_member = false;
            public bool $is_owner  = false;

            public function __construct(private int $page_id, private ?int $user_id = null)
            {
                $this->init();
            }

            private function init(): void
            {
                $role            = __bcc_pm_rows($this->page_id, (int) $this->user_id)[0] ?? false;
                $this->role      = $role;
                $this->is_member = is_string($role) && str_starts_with($role, 'member');
                $this->is_owner  = $role === 'member_owner';
            }

            public function member_join(): bool
            {
                $GLOBALS['__bcc_drain']['page_writes'][] = ['join', $this->page_id, (int) $this->user_id];
                if (!empty($GLOBALS['__bcc_drain']['fail_join'])) {
                    return false;
                }
                if (__bcc_pm_rows($this->page_id, (int) $this->user_id) !== []) {
                    return $this->member_modify('member'); // the real join on an existing row
                }
                $GLOBALS['__bcc_drain']['page_members'][$this->page_id][(int) $this->user_id] = 'member';
                $this->init();
                return true;
            }

            public function member_modify(string $role): bool
            {
                $GLOBALS['__bcc_drain']['page_writes'][] = ['modify', $this->page_id, (int) $this->user_id, $role];
                if (!empty($GLOBALS['__bcc_drain']['fail_modify'])) {
                    return false;
                }
                $current = $GLOBALS['__bcc_drain']['page_members'][$this->page_id][(int) $this->user_id] ?? null;
                if (is_array($current)) {
                    $GLOBALS['__bcc_drain']['page_members'][$this->page_id][(int) $this->user_id] = array_fill(0, count($current), $role);
                } elseif ($current !== null) {
                    $GLOBALS['__bcc_drain']['page_members'][$this->page_id][(int) $this->user_id] = $role;
                }
                $this->init();
                return true;
            }
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(PageFollowRepository::class, false)) {
        final class PageFollowRepository
        {
            public const DRAIN_PAGE_SIZE = 5000;

            /**
             * @return list<object>
             * @throws \BCC\Trust\Core\Exceptions\RepositoryException
             */
            public function findByPageId(int $pageId): array
            {
                $GLOBALS['__bcc_drain']['reads']++;

                if ((int) ($GLOBALS['__bcc_drain']['fail_read'] ?? 0) === $GLOBALS['__bcc_drain']['reads']) {
                    // The real repository throws rather than returning [],
                    // which is the whole point: `[]` is indistinguishable
                    // from a page with no followers.
                    throw new \BCC\Trust\Core\Exceptions\RepositoryException('page_follows_read_failed');
                }

                $out = [];
                foreach ($GLOBALS['__bcc_drain']['rows'] as $row) {
                    if ((int) $row->page_id === $pageId) {
                        $out[] = $row;
                    }
                    if (count($out) >= self::DRAIN_PAGE_SIZE) {
                        break;
                    }
                }

                return $out;
            }

            /**
             * @param list<int> $ids
             * @return int|false
             */
            public function deleteByIds(array $ids): int|false
            {
                $GLOBALS['__bcc_drain']['delete_calls'][] = count($ids);

                if ((int) ($GLOBALS['__bcc_drain']['fail_delete'] ?? 0) === count($GLOBALS['__bcc_drain']['delete_calls'])) {
                    // A failed DELETE removes NOTHING. Every row the caller
                    // just handled is still in the table.
                    return false;
                }

                if (!empty($GLOBALS['__bcc_drain']['delete_matches_nothing'])) {
                    // The other zero: the statement ran and matched no rows.
                    // Indistinguishable from the line above before the fix.
                    return 0;
                }

                $n = 0;
                foreach ($ids as $id) {
                    $id = (int) $id;
                    $GLOBALS['__bcc_drain']['deleted'][] = $id;
                    if (isset($GLOBALS['__bcc_drain']['rows'][$id])) {
                        unset($GLOBALS['__bcc_drain']['rows'][$id]);
                        $n++;
                    }
                }

                return $n;
            }

            /** ⚠ The drain must NEVER call this — it would delete unhandled rows. */
            public function deleteByPageId(int $pageId): int
            {
                $GLOBALS['__bcc_drain']['by_page_id']++;
                foreach ($GLOBALS['__bcc_drain']['rows'] as $id => $row) {
                    if ((int) $row->page_id === $pageId) {
                        unset($GLOBALS['__bcc_drain']['rows'][$id]);
                    }
                }

                return 0;
            }
        }
    }

    if (!class_exists(WatchMetaRepository::class, false)) {
        final class WatchMetaRepository
        {
            public function find(int $followId): ?object { return null; }

            public function insert(int $followId, ?string $tier, ?int $batchId): bool
            {
                $GLOBALS['__bcc_drain']['meta'][] = $followId;
                return true;
            }
        }
    }

    // F-299: onPageClaimed() counts the transferred page; recorded only.
    if (!class_exists('BCC\Trust\Core\Repositories\UserInfoRepository', false)) {
        final class UserInfoRepository
        {
            public function incrementPageCount(int $userId, int $pageId): void
            {
                $GLOBALS['__bcc_drain']['page_counts'][] = [$userId, $pageId];
            }

            // F-334: a released page no longer counts for its old owner.
            public function transferPageOwnership(int $oldOwnerId, int $newOwnerId, int $pageId): bool
            {
                $GLOBALS['__bcc_drain']['page_count_drops'][] = [$oldOwnerId, $pageId];
                $GLOBALS['__bcc_drain']['page_counts'][]      = [$newOwnerId, $pageId];
                return true;
            }

            public function decrementPageCount(int $userId, int $pageId): void
            {
                $GLOBALS['__bcc_drain']['page_count_drops'][] = [$userId, $pageId];
            }
        }
    }

    // F-334: the owner-keyed score rows, as `page_id => page_owner_id`
    // ($GLOBALS['__bcc_drain']['score_owner']; absent = no score row).
    if (!class_exists('BCC\Trust\Core\Repositories\ScoreRepository', false)) {
        final class ScoreRepository
        {
            public function getPageOwnerId(int $pageId): int
            {
                return (int) ($GLOBALS['__bcc_drain']['score_owner'][$pageId] ?? 0);
            }

            public function updateOwner(int $pageId, int $ownerId): void
            {
                $GLOBALS['__bcc_drain']['score_owner'][$pageId] = $ownerId;
            }

            public function existsForPage(int $pageId): bool
            {
                return array_key_exists($pageId, $GLOBALS['__bcc_drain']['score_owner'] ?? []);
            }

            /** The guarded UPDATE: only rows still keyed to $fromOwner move (`release_owner_fails` = throw). */
            public function releaseOwner(int $pageId, int $fromOwner, int $toOwner): int
            {
                $GLOBALS['__bcc_drain']['release_owner_calls'][] = [$pageId, $fromOwner, $toOwner];
                if (!empty($GLOBALS['__bcc_drain']['release_owner_fails'])) {
                    throw new \RuntimeException('update failed');
                }
                if (($GLOBALS['__bcc_drain']['score_owner'][$pageId] ?? null) !== $fromOwner) {
                    return 0;
                }
                $GLOBALS['__bcc_drain']['score_owner'][$pageId] = $toOwner;
                return 1;
            }
        }
    }
}

// F-334: whether ANY verified claim still binds a page
// ($GLOBALS['__bcc_drain']['page_held'][pageId] = true; `held_read_fails`
// = throw, as the real fail-closed read does).
namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\ClaimRepository', false)) {
        final class ClaimRepository
        {
            public static function anyPrimaryClaimBoundToPage(int $pageId): bool
            {
                if (!empty($GLOBALS['__bcc_drain']['held_read_fails'])) {
                    throw new \RuntimeException('read failed');
                }
                return !empty($GLOBALS['__bcc_drain']['page_held'][$pageId]);
            }
        }
    }
}

namespace BCC\Trust\Core {
    if (!class_exists(Plugin::class, false)) {
        final class Plugin
        {
            private static ?self $instance = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public function pageFollowRepository(): \BCC\Trust\Core\Repositories\PageFollowRepository
            {
                return new \BCC\Trust\Core\Repositories\PageFollowRepository();
            }

            public function watchMetaRepository(): \BCC\Trust\Core\Repositories\WatchMetaRepository
            {
                return new \BCC\Trust\Core\Repositories\WatchMetaRepository();
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoFollowWriter::class, false)) {
        final class PeepSoFollowWriter
        {
            public static function follow(int $viewerId, int $authorId): int
            {
                $GLOBALS['__bcc_drain']['follows']++;

                if (in_array($viewerId, $GLOBALS['__bcc_drain']['throw_follow'] ?? [], true)) {
                    throw new \RuntimeException('simulated PeepSo failure');
                }
                if (in_array($viewerId, $GLOBALS['__bcc_drain']['fail_follow'] ?? [], true)) {
                    return 0;
                }

                return 9000 + $viewerId;
            }
        }
    }
}

/**
 * F-29: the drain asks bcc-core whether the viewer and the claimer are
 * blocked (either direction) BEFORE following. bcc-core is not autoloaded
 * in the unit suite, so the read is stubbed here. Default: not blocked.
 *   blocked        list<int>  viewer ids whose pair with the claimer is blocked
 *   block_unknown  list<int>  viewer ids whose block state is unreadable (null)
 *   block_reads    int        call count
 */
namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoBlockRepository::class, false)) {
        final class PeepSoBlockRepository
        {
            public static function isMutuallyBlockedOrUnknown(int $userA, int $userB): ?bool
            {
                $GLOBALS['__bcc_drain']['block_reads']++;

                if (in_array($userA, $GLOBALS['__bcc_drain']['block_unknown'] ?? [], true)) {
                    return null;
                }

                return in_array($userA, $GLOBALS['__bcc_drain']['blocked'] ?? [], true);
            }
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    // F-299 live guard's other-owner read, from the in-memory page members.
    // `owner_read_fails` makes it answer "unreadable" (null).
    //
    // F-360 claimerPageStatus(): the ranked read (a ban first, then an owner
    // row, then the oldest). The real SQL ordering is proved against MySQL
    // and MariaDB in PageOwnerBackfillCandidatesIntegrationTest; this fake
    // lets the unit tests prove the CALLERS decide on it and not on
    // PeepSoPageUser's one-row flags. `status_read_fails` = unreadable (null).
    if (!class_exists(PeepSoQueryRepository::class, false)) {
        final class PeepSoQueryRepository
        {
            public static function otherPageOwnerId(int $pageId, int $userId): ?int
            {
                if (!empty($GLOBALS['__bcc_drain']['owner_read_fails'])) {
                    return null;
                }
                foreach (array_keys($GLOBALS['__bcc_drain']['page_members'][$pageId] ?? []) as $uid) {
                    if ((int) $uid !== $userId && in_array('member_owner', __bcc_pm_rows($pageId, (int) $uid), true)) {
                        return (int) $uid;
                    }
                }

                return 0;
            }

            /** The compare-and-set author move (`cas_fails` = the write fails). */
            public static function compareAndSetPageAuthor(int $pageId, int $fromUserId, int $toUserId): ?bool
            {
                if (!empty($GLOBALS['__bcc_drain']['cas_fails'])) {
                    return null;
                }
                if (($GLOBALS['__bcc_drain']['post_author'][$pageId] ?? 0) !== $fromUserId) {
                    return false;
                }
                $GLOBALS['__bcc_drain']['post_author'][$pageId] = $toUserId;
                return true;
            }

            /** @return array{status: ?string}|null */
            public static function claimerPageStatus(int $pageId, int $userId): ?array
            {
                if (!empty($GLOBALS['__bcc_drain']['status_read_fails'])) {
                    return null;
                }
                $rows = __bcc_pm_rows($pageId, $userId);
                foreach (['banned', 'member_owner'] as $ranked) {
                    if (in_array($ranked, $rows, true)) {
                        return ['status' => $ranked];
                    }
                }

                return ['status' => $rows[0] ?? null];
            }
        }
    }
}

namespace {
    if (!function_exists('clean_post_cache')) {
        function clean_post_cache(int $postId): void
        {
        }
    }
}

namespace BCC\Trust\Core\Security {
    // The admin audit log, recorded instead of written.
    if (!class_exists(AuditLogger::class, false)) {
        final class AuditLogger
        {
            /** @param array<string, mixed> $meta */
            public static function log(string $action, ?int $targetId = null, array $meta = [], ?string $targetType = null, ?int $userId = null): void
            {
                $GLOBALS['__bcc_drain']['audits'][] = ['action' => $action, 'target' => $targetId, 'meta' => $meta];
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $ctx */
            public static function error(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_drain']['errors'][] = ['message' => $m, 'ctx' => $ctx];
            }
            /** @param array<string, mixed> $ctx */
            public static function warning(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_drain']['warnings'][] = ['message' => $m, 'ctx' => $ctx];
            }
            /** @param array<string, mixed> $ctx */
            public static function info(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_drain']['info'][] = $ctx;
            }
        }
    }
}

/**
 * ⚠ Needed by the RETRY entry point (`onClaimFollowersDrain`), not by the
 * drain itself: the retry reads `post_author` to refuse a claimer who is not
 * the page author, so without this the non-owner guard could not be
 * exercised and would have had to be taken on trust.
 *
 * Defined in PeepSoIntegration's own namespace, which is where PHP resolves
 * an unqualified call before falling back to global - the same convention
 * page-claim-dispatch-stubs.php uses for PagesEndpoint.
 */
namespace BCC\Trust\Core\Integration {
    if (!function_exists('BCC\Trust\Core\Integration\get_post_field')) {
        /**
         * @param  int|string $postId
         * @return mixed
         */
        function get_post_field(string $field, $postId = 0)
        {
            $GLOBALS['__bcc_drain']['author_reads']++;

            return $GLOBALS['__bcc_drain']['post_author'][(int) $postId] ?? 0;
        }
    }

    // F-299: onPageClaimed()'s ownership transfer on an author-0 page.
    // Knob `fail_author_write` (bool): it fails the way the real one does
    // with $wpError = true, returning a WP_Error and writing NOTHING.
    if (!function_exists('BCC\Trust\Core\Integration\wp_update_post')) {
        /**
         * @param array<string, mixed> $post
         * @return int|\WP_Error
         */
        function wp_update_post(array $post, bool $wpError = false)
        {
            if (!empty($GLOBALS['__bcc_drain']['fail_author_write'])) {
                return new \WP_Error('db_update_error', 'Could not update post in the database.');
            }
            $id = (int) ($post['ID'] ?? 0);
            $GLOBALS['__bcc_drain']['post_author'][$id] = (int) ($post['post_author'] ?? 0);

            return $id;
        }
    }
    if (!function_exists('BCC\Trust\Core\Integration\is_wp_error')) {
        /** @param mixed $thing */
        function is_wp_error($thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }
}
