<?php
/**
 * SecretPageAnnouncementsTest: a world for the announcement reads and the
 * announcement comment service around a SECRET page.
 *
 * ⚠ Required from a test's setUp(), i.e. inside its separate process —
 * NEVER at file level — and BEFORE the real classes autoload, so these
 * doubles win. Load tests/Stubs/announcement-endpoint-stubs.php first (the
 * REST classes, get_option, get_current_user_id, get_post over
 * $GLOBALS['__bcc_posts']) and tests/Stubs/profile-access-stubs.php for the
 * admin exemption (Permissions::is_admin).
 *
 * The REAL AnnouncementsEndpoint, AnnouncementCommentService,
 * AnnouncementViewService, PageAccess and ProfileAccess run. Doubled:
 *   - bcc-core's PeepSoPagePrivacy::isVisibleTo, from a fixture. The rule's
 *     own SQL is tested in bcc-core and in bcc-trust's secret-page suites;
 *     this world tests what the announcement code does with its verdict.
 *   - the announcement and comment repositories, recording every call, so a
 *     test can see that a hidden page's reads still RUN (a failing database
 *     must answer 503 for a hidden page exactly as for a missing one).
 *   - the operator authority, the viewer standing and bcc-core's throttle.
 *
 * Fixture ($GLOBALS['__bcc_ann']):
 *   rows     => array<int, list<object>>  pageId => list/rotator rows
 *   public   => array<string, object>     "pageId:announcementId" => findPublic row
 *   secret   => array<int, list<int>>     SECRET pageId => the viewers it admits
 *   comment  => object|null               what AnnouncementCommentRepository::findOne returns
 *   fail     => bool                      every announcement read throws
 *   calls    => list<string>              "method:pageId" per repository read
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Post', false)) {
        final class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public string $post_status = '';
            public string $post_name = '';
            public string $post_title = '';
            public int $post_author = 0;
            public int $post_parent = 0;
        }
    }

    if (!function_exists('bcc_ann_post')) {
        function bcc_ann_post(int $id, string $type, int $author = 0, int $parent = 0, string $name = ''): WP_Post
        {
            $post              = new WP_Post();
            $post->ID          = $id;
            $post->post_type   = $type;
            $post->post_status = 'publish';
            $post->post_author = $author;
            $post->post_parent = $parent;
            $post->post_name   = $name;

            return $post;
        }
    }

    if (!function_exists('bcc_ann_row')) {
        /** A row shaped like AnnouncementRepository::columns() returns it. */
        function bcc_ann_row(int $id, string $title): object
        {
            return (object) [
                'ID'                   => (string) $id,
                'post_title'           => $title,
                'post_excerpt'         => 'summary of ' . $title,
                'post_content'         => 'body of ' . $title,
                'post_status'          => 'publish',
                'post_author'          => '70',
                'post_date_gmt'        => '2026-01-01 00:00:00',
                'post_modified_gmt'    => '2026-01-01 00:00:00',
                'is_pinned'            => '0',
                'archived_at'          => null,
                'comments_enabled'     => '1',
                'publish_at'           => null,
                'blocked_at'           => null,
                'moderation_hidden_at' => null,
                'effective_at'         => '2026-01-01 00:00:00',
            ];
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoPagePrivacy', false)) {
        final class PeepSoPagePrivacy
        {
            public static function isVisibleTo(int $pageId, int $viewerId): bool
            {
                $secret = $GLOBALS['__bcc_ann']['secret'] ?? [];

                return !isset($secret[$pageId]) || in_array($viewerId, $secret[$pageId], true);
            }
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(__NAMESPACE__ . '\\Throttle', false)) {
        final class Throttle
        {
            public static function allow(string $bucket, int $limit, int $window): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    use BCC\Trust\Core\Exceptions\RepositoryException;

    if (!class_exists(__NAMESPACE__ . '\\AnnouncementRepository', false)) {
        final class AnnouncementRepository
        {
            /** @param mixed ...$tables */
            public function __construct(...$tables)
            {
            }

            /** @return array{rows: list<object>, has_more: bool} */
            public function listActive(int $pageId, int $limit, ?string $cursor): array
            {
                return ['rows' => self::rowsFor('listActive', $pageId), 'has_more' => false];
            }

            /** @return array{rows: list<object>, has_more: bool} */
            public function listArchived(int $pageId, int $limit, ?string $cursor): array
            {
                return ['rows' => self::rowsFor('listArchived', $pageId), 'has_more' => false];
            }

            /** @return list<object> */
            public function rotator(int $pageId): array
            {
                return self::rowsFor('rotator', $pageId);
            }

            public function findPublic(int $pageId, int $announcementId): ?object
            {
                self::record('findPublic', $pageId);

                return $GLOBALS['__bcc_ann']['public'][$pageId . ':' . $announcementId] ?? null;
            }

            /** @param array<string, mixed> $decision */
            public function findForOwner(int $pageId, array $decision, int $announcementId): ?object
            {
                self::record('findForOwner', $pageId);

                return null;
            }

            /**
             * @param list<int> $announcementIds
             * @return array<int, int>
             */
            public function commentCountsFor(array $announcementIds): array
            {
                return [];
            }

            /** @return list<object> */
            private static function rowsFor(string $method, int $pageId): array
            {
                self::record($method, $pageId);

                return $GLOBALS['__bcc_ann']['rows'][$pageId] ?? [];
            }

            private static function record(string $method, int $pageId): void
            {
                $GLOBALS['__bcc_ann']['calls'][] = $method . ':' . $pageId;
                if (!empty($GLOBALS['__bcc_ann']['fail'])) {
                    throw new RepositoryException('announcement_read_failed');
                }
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\AnnouncementCommentRepository', false)) {
        final class AnnouncementCommentRepository
        {
            /** @return array{rows: list<object>, has_more: bool} */
            public function listForAnnouncement(int $announcementId, int $limit, ?string $cursor): array
            {
                $GLOBALS['__bcc_ann']['calls'][] = 'listForAnnouncement:' . $announcementId;

                return ['rows' => [], 'has_more' => false];
            }

            public function findOne(int $commentId): ?\stdClass
            {
                $comment = $GLOBALS['__bcc_ann']['comment'] ?? null;

                return $comment instanceof \stdClass ? $comment : null;
            }

            public function commentsEnabled(int $announcementId, string $metaKey): bool
            {
                return true;
            }

            /** @param list<object> $rows */
            public function nextCursor(array $rows): ?string
            {
                return null;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!class_exists(__NAMESPACE__ . '\\ValidatorPageOperatorAuthority', false)) {
        /** Nobody in this world is an operator; that is not what is under test. */
        final class ValidatorPageOperatorAuthority
        {
            public const DECISION_OPERATOR = 'operator';
            public const DECISION_DENIED   = 'denied';
            public const PUBLIC_REASON     = 'not_claimer';

            /** @return array{decision: string, reason: string|null, operator_user_id: int|null} */
            public static function decide(int $pageId, int $viewerId): array
            {
                return ['decision' => self::DECISION_DENIED, 'reason' => 'claim_absent', 'operator_user_id' => null];
            }
        }
    }
}

namespace BCC\Trust\Core\Announcements {
    if (!class_exists(__NAMESPACE__ . '\\AnnouncementViewerStanding', false)) {
        final class AnnouncementViewerStanding
        {
            /** @return array<string, mixed> */
            public static function forViewer(int $viewerId): array
            {
                return ['authenticated' => $viewerId > 0];
            }
        }
    }
}
