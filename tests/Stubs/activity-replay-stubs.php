<?php
/**
 * Fixture-backed stubs for ActivityReplayGuardTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses), and
 * required from setUp() rather than file scope: these declare functions and
 * classes at the FQNs the real code uses, and a file-scope declaration would
 * pollute the discovery parent and hijack sibling suites (notably
 * ActivityBackingPostTypeTest, which declares its own wp_insert_post).
 *
 * The in-memory post store is keyed the way the production replay guard is:
 * (post_author, _bcc_activity_module, _bcc_activity_sidecar_id). `title` is
 * stored too, but NOTHING in `get_posts()` below looks at it — that is
 * deliberate, so a test can seed a title collision and prove the guard does
 * not deduplicate on it.
 *
 * Fixture shape ($GLOBALS['__bcc_replay']):
 *   posts       array<int, array{author:int,module:string,sidecar:string,title:string}>
 *   next_post   int   next wp_posts.ID to mint
 *   inserts     list<array{author:int,title:string}>   wp_insert_post calls
 *   acts        array<string, int>   "<module>|<postId>" => act_id
 *   next_act    int   next act_id to mint
 *   act_inserts list<array{actor:int,owner:int,module:string,post:int}>
 *   act_lookups list<array{module:string,post:int}>
 *   claims      array<string, object>  "<type>|<entityId>|<userId>" => claim row
 *   logs        list<array{level:string,msg:string}>
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Feed {
    if (!function_exists(__NAMESPACE__ . '\\wp_insert_post')) {
        /**
         * @param array<string, mixed> $args
         * @return int|object
         */
        function wp_insert_post(array $args, bool $wpError = false)
        {
            unset($wpError);

            $id = $GLOBALS['__bcc_replay']['next_post']++;

            $GLOBALS['__bcc_replay']['posts'][$id] = [
                'author'  => (int) ($args['post_author'] ?? 0),
                'module'  => '',   // written by update_post_meta below
                'sidecar' => '',
                'title'   => (string) ($args['post_title'] ?? ''),
            ];
            $GLOBALS['__bcc_replay']['inserts'][] = [
                'author' => (int) ($args['post_author'] ?? 0),
                'title'  => (string) ($args['post_title'] ?? ''),
            ];

            return $id;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\is_wp_error')) {
        /** @param mixed $thing */
        function is_wp_error($thing): bool
        {
            unset($thing);
            return false;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\update_post_meta')) {
        /**
         * @param int|string $postId
         * @param string|int $value
         */
        function update_post_meta($postId, string $key, $value): bool
        {
            $postId = (int) $postId;
            if (!isset($GLOBALS['__bcc_replay']['posts'][$postId])) {
                return false;
            }

            if ($key === '_bcc_activity_module') {
                $GLOBALS['__bcc_replay']['posts'][$postId]['module'] = (string) $value;
            }
            if ($key === '_bcc_activity_sidecar_id') {
                $GLOBALS['__bcc_replay']['posts'][$postId]['sidecar'] = (string) $value;
            }

            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_posts')) {
        /**
         * Meta-only lookup, mirroring what the real query does: author plus
         * the two meta keys. The stored title is NEVER consulted.
         *
         * @param array<string, mixed> $args
         * @return list<int>
         */
        function get_posts(array $args = []): array
        {
            $author = (int) ($args['author'] ?? 0);

            // ⭐ The stub ANSWERS a title lookup on purpose. Without this a
            // title-based production query would simply find nothing here,
            // and `testAPostWhoseTitleMatchesButWhoseSidecarDiffersIsNotReused`
            // would pass vacuously — it would be asserting that the STUB
            // ignores titles, not that production does. Answering the title
            // query is what lets that case kill a title-based duplicate check.
            $byTitle = $args['title'] ?? ($args['post_title'] ?? ($args['s'] ?? null));
            if ($byTitle !== null) {
                $hits = [];
                foreach ($GLOBALS['__bcc_replay']['posts'] as $id => $post) {
                    if ($post['title'] === (string) $byTitle
                        && ($author === 0 || $post['author'] === $author)
                    ) {
                        $hits[] = (int) $id;
                    }
                }

                return $hits;
            }

            $wantModule  = null;
            $wantSidecar = null;
            foreach ((array) ($args['meta_query'] ?? []) as $clause) {
                if (!is_array($clause) || !isset($clause['key'])) {
                    continue;
                }
                if ($clause['key'] === '_bcc_activity_module') {
                    $wantModule = (string) $clause['value'];
                }
                if ($clause['key'] === '_bcc_activity_sidecar_id') {
                    $wantSidecar = (string) $clause['value'];
                }
            }

            if ($wantModule === null || $wantSidecar === null) {
                // A lookup that lost one half of the identity would match far
                // too much; refuse rather than return a wrong post.
                return [];
            }

            $hits = [];
            foreach ($GLOBALS['__bcc_replay']['posts'] as $id => $post) {
                if ($post['author'] === $author
                    && $post['module'] === $wantModule
                    && $post['sidecar'] === $wantSidecar
                ) {
                    $hits[] = (int) $id;
                }
            }

            return $hits;
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(WatchBatchRepository::class, false)) {
        final class WatchBatchRepository
        {
        }
    }

    if (!class_exists(PeepSoActivityWriter::class, false)) {
        final class PeepSoActivityWriter
        {
            public function findActIdForExternal(string $moduleId, int $wpPostId): int
            {
                $GLOBALS['__bcc_replay']['act_lookups'][] = [
                    'module' => $moduleId,
                    'post'   => $wpPostId,
                ];

                return (int) ($GLOBALS['__bcc_replay']['acts'][$moduleId . '|' . $wpPostId] ?? 0);
            }

            public function insert(
                int $actorUserId,
                int $ownerUserId,
                string $moduleId,
                int $wpPostId
            ): int {
                $actId = $GLOBALS['__bcc_replay']['next_act']++;

                $GLOBALS['__bcc_replay']['acts'][$moduleId . '|' . $wpPostId] = $actId;
                $GLOBALS['__bcc_replay']['act_inserts'][] = [
                    'actor'  => $actorUserId,
                    'owner'  => $ownerUserId,
                    'module' => $moduleId,
                    'post'   => $wpPostId,
                ];

                return $actId;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(ClaimRepository::class, false)) {
        final class ClaimRepository
        {
            public static function getUserClaim(int $userId, string $entityType, int $entityId): ?object
            {
                $key = $entityType . '|' . $entityId . '|' . $userId;

                return $GLOBALS['__bcc_replay']['claims'][$key] ?? null;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $ctx */
            public static function info(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_replay']['logs'][] = ['level' => 'info', 'msg' => $m];
            }

            /** @param array<string, mixed> $ctx */
            public static function warning(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_replay']['logs'][] = ['level' => 'warning', 'msg' => $m];
            }

            /** @param array<string, mixed> $ctx */
            public static function error(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_replay']['logs'][] = ['level' => 'error', 'msg' => $m];
            }

            /** @param array<string, mixed> $ctx */
            public static function debug(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_replay']['logs'][] = ['level' => 'debug', 'msg' => $m];
            }
        }
    }
}
