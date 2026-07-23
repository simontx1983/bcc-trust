<?php

declare(strict_types=1);

/**
 * Member-target reviews in the feed: the vote's page_id is a self-page
 * id (≥ MemberSelfPageService::ID_BASE) with no backing wp_post, so the
 * hydrator must resolve the owner's display name + bcc_handle via
 * UserMiniRepository and emit page_kind 'member' — never send self-page
 * ids into get_posts. Regression pin for the blank feed-card bug.
 */

namespace BCC\Trust\Core\Services\Feed {
    // In-namespace WP stubs — the hydrator's unqualified calls resolve
    // here before the (undefined) global functions. Guarded so a future
    // sibling test can share them.
    if (!function_exists(__NAMESPACE__ . '\\get_posts')) {
        /**
         * @param array<string, mixed> $args
         * @return list<object>
         */
        function get_posts(array $args): array
        {
            $GLOBALS['bcc_test_get_posts_calls'][] = $args;
            /** @var list<object> */
            return $GLOBALS['bcc_test_get_posts_result'] ?? [];
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_post_meta')) {
        /**
         * @param int|string $postId
         */
        function get_post_meta($postId, string $key, bool $single = false): string
        {
            unset($postId, $key, $single);
            return '';
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Core\Repositories\UserMiniRepository;
    use BCC\Trust\Core\Repositories\VoteRepository;
    use BCC\Trust\Core\Services\Feed\ReviewBodyHydrator;
    use BCC\Trust\Core\Services\MemberSelfPageService;
    use PHPUnit\Framework\TestCase;

    final class ReviewBodyHydratorMemberTargetTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['bcc_test_get_posts_calls']  = [];
            $GLOBALS['bcc_test_get_posts_result'] = [];
            if (!\defined('ARRAY_A')) {
                \define('ARRAY_A', 'ARRAY_A');
            }
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['bcc_test_get_posts_calls'],
                $GLOBALS['bcc_test_get_posts_result'],
                $GLOBALS['wpdb']
            );
        }

        /**
         * @param array<int, object> $votes vote_id → row
         * @param list<array{ID: string, user_login: string, display_name: string, handle: string|null}> $miniRows
         */
        private function hydrator(array $votes, array $miniRows): ReviewBodyHydrator
        {
            $voteRepo = new class ($votes) extends VoteRepository {
                /**
                 * Skips the parent ctor (TableRegistry + weight
                 * constants) — findManyByIds is fully overridden.
                 *
                 * @param array<int, object> $rows
                 */
                public function __construct(private readonly array $rows)
                {
                }

                /**
                 * @param list<int> $voteIds
                 * @return array<int, object>
                 */
                public function findManyByIds(array $voteIds): array
                {
                    unset($voteIds);
                    return $this->rows;
                }
            };

            // UserMiniRepository is final — feed it canned rows through
            // a fake $wpdb instead of subclassing.
            $GLOBALS['wpdb'] = new class ($miniRows) {
                public string $users    = 'wp_users';
                public string $usermeta = 'wp_usermeta';

                /** @param list<array{ID: string, user_login: string, display_name: string, handle: string|null}> $rows */
                public function __construct(private array $rows)
                {
                }

                public function prepare(string $sql, int|string ...$args): string
                {
                    unset($args);
                    return $sql;
                }

                /** @return list<array{ID: string, user_login: string, display_name: string, handle: string|null}> */
                public function get_results(string $sql, string $output = 'OBJECT'): array
                {
                    unset($sql, $output);
                    return $this->rows;
                }
            };

            return new ReviewBodyHydrator($voteRepo, new UserMiniRepository());
        }

        private static function vote(int $pageId): object
        {
            return (object) [
                'page_id'     => $pageId,
                'vote_type'   => 1,
                'explanation' => 'Solid operator.',
            ];
        }

        public function testMemberTargetEmitsMemberBodyAndSkipsGetPosts(): void
        {
            $selfPage = MemberSelfPageService::selfPageId(77);
            $h = $this->hydrator(
                [9 => self::vote($selfPage)],
                [['ID' => '77', 'user_login' => 'simon_login', 'display_name' => 'Simon TX', 'handle' => 'simontx']]
            );

            $bodies = $h->loadReviewBodies([9]);

            self::assertSame([], $GLOBALS['bcc_test_get_posts_calls'], 'self-page ids must never reach get_posts');
            self::assertSame('member', $bodies[9]['page_kind']);
            self::assertSame('Simon TX', $bodies[9]['page_name']);
            self::assertSame('simontx', $bodies[9]['page_handle']);
            self::assertSame($selfPage, $bodies[9]['page_id']);
            self::assertSame('trust', $bodies[9]['grade']);
        }

        public function testMixedVotesPartitionEntityIdsIntoGetPosts(): void
        {
            $selfPage = MemberSelfPageService::selfPageId(77);
            $h = $this->hydrator(
                [
                    1 => self::vote(123),
                    2 => self::vote($selfPage),
                ],
                [['ID' => '77', 'user_login' => 'l', 'display_name' => 'Simon TX', 'handle' => 'simontx']]
            );
            $GLOBALS['bcc_test_get_posts_result'] = [
                (object) ['ID' => 123, 'post_name' => 'everstake', 'post_title' => 'Everstake'],
            ];

            $bodies = $h->loadReviewBodies([1, 2]);

            self::assertCount(1, $GLOBALS['bcc_test_get_posts_calls']);
            self::assertSame([123], $GLOBALS['bcc_test_get_posts_calls'][0]['post__in']);
            self::assertSame('Everstake', $bodies[1]['page_name']);
            self::assertSame('member', $bodies[2]['page_kind']);
            self::assertSame('Simon TX', $bodies[2]['page_name']);
        }

        public function testHandlelessMemberSuppressesHandleNeverLogin(): void
        {
            $h = $this->hydrator(
                [9 => self::vote(MemberSelfPageService::selfPageId(77))],
                [['ID' => '77', 'user_login' => 'simon_login', 'display_name' => 'Simon TX', 'handle' => null]]
            );

            $bodies = $h->loadReviewBodies([9]);

            self::assertSame('', $bodies[9]['page_handle'], 'no bcc_handle → empty, never user_login');
            self::assertSame('member', $bodies[9]['page_kind']);
        }

        public function testUnknownMemberDegradesGracefully(): void
        {
            $h = $this->hydrator(
                [9 => self::vote(MemberSelfPageService::selfPageId(404))],
                []
            );

            $bodies = $h->loadReviewBodies([9]);

            self::assertSame('', $bodies[9]['page_name']);
            self::assertSame('', $bodies[9]['page_handle']);
            self::assertSame('member', $bodies[9]['page_kind']);
        }
    }
}
