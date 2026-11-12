<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\FeedEndpoint;
use BCC\Trust\Core\REST\PagesEndpoint;
use BCC\Trust\Core\REST\PostsEndpoint;
use BCC\Trust\Core\Services\Feed\FeedRankingService;
use BCC\Trust\Core\Services\PostsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Posts an operator writes for a validator page: who may write one, and where
 * it shows up afterwards.
 *
 * Every case calls the real endpoint and reads real tables. PeepSo is the only
 * thing doubled (see page-posts-stubs.php), so the marker, the page feed's JOIN
 * and the `page_context` hydration all run for real.
 *
 * Authority comes from claim rows and the page binding, the way
 * ValidatorPageOperatorAuthority reads them. No case sets post_author or a page
 * mirror row to grant anything, because neither may confer authority.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(PostsEndpoint::class)]
#[CoversClass(PostsService::class)]
#[CoversClass(PagesEndpoint::class)]
#[CoversClass(FeedRankingService::class)]
final class PagePostsIntegrationTest extends TestCase
{
    private const OPERATOR   = 140;
    private const OPERATOR_B = 141;
    private const STRANGER   = 142;

    private const PAGE_A       = 3101;
    private const PAGE_B       = 3102;
    private const PAGE_NOT_VAL = 3103;

    private const REFUSAL_BODY = [
        'code'    => 'bcc_forbidden',
        'message' => 'Only the verified operator of this validator page can post for it.',
        'status'  => 403,
        'data'    => ['reason_code' => 'not_claimer'],
    ];

    private int $chainId = 0;

    private int $validatorA = 0;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/page-posts-stubs.php';

        $GLOBALS['__bcc_actions']         = [];
        $GLOBALS['__bcc_throttle_calls']  = [];
        $GLOBALS['__bcc_marker_at_event'] = [];
        $GLOBALS['__bcc_current_user']    = self::OPERATOR;
        Logger::reset();

        $this->buildFeedTables();

        global $wpdb;
        $chain = $wpdb->get_var(
            "SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE chain_type = 'cosmos' ORDER BY id ASC LIMIT 1"
        );
        self::assertNotNull($chain, 'the bootstrap must seed a Cosmos chain');
        $this->chainId = (int) $chain;

        $this->insertPage(self::PAGE_A, 'Frozen Title A', 'page-a');
        $this->insertPage(self::PAGE_B, 'Frozen Title B', 'page-b');
        $this->insertPage(self::PAGE_NOT_VAL, 'Builder Page', 'builder-page');

        $this->validatorA = $this->bindValidator(self::PAGE_A, 'cosmosvaloper1aaa', 'Live Moniker A');
        $validatorB       = $this->bindValidator(self::PAGE_B, 'cosmosvaloper1bbb', 'Live Moniker B');
        $this->insertOperatorClaim(self::OPERATOR, $this->validatorA);
        $this->insertOperatorClaim(self::OPERATOR_B, $validatorB);
    }

    /**
     * ⚠ The last case's database is the one the next suite inherits: only the
     * bootstrap rebuilds it, and suites that share a process do not re-run that.
     * Published pages left here read as real pages to PageReadModelSync's batch
     * counts, which is how this was found.
     */
    protected function tearDown(): void
    {
        global $wpdb;

        foreach (['bcc_onchain_claims', 'bcc_onchain_validators', 'bcc_hidden_activities', 'bcc_trust_page_scores'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}{$table}");
        }
        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
        $wpdb->query("DELETE FROM {$wpdb->posts}");
        // ⚠ peepso_users is deliberately NOT dropped here, unlike the two
        // tables this suite owns. It is created with IF NOT EXISTS, it holds
        // no rows this suite inserted, and the §F-02 gate in any later suite
        // needs it — dropping a shared table is the sabotage
        // BlogTabGroupVisibilityIntegrationTest::tearDown() documents at
        // length. An empty leftover table costs nothing.
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}peepso_activities, {$wpdb->prefix}peepso_blocks");
        $wpdb->query(
            "ALTER TABLE {$wpdb->posts}
                DROP COLUMN post_author,
                DROP COLUMN post_content,
                DROP COLUMN post_excerpt,
                DROP COLUMN post_date_gmt"
        );
    }

    // ── writing ─────────────────────────────────────────────────────────

    public function testOperatorPostIsStampedBeforeTheEventFires(): void
    {
        $response = $this->post(self::OPERATOR, ['target_page_id' => self::PAGE_A]);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        self::assertSame(['ok', 'feed_id', 'post_id', 'act_id'], array_keys($data));
        self::assertSame('feed_' . $data['act_id'], $data['feed_id']);

        self::assertSame((string) self::PAGE_A, get_post_meta($data['post_id'], '_bcc_page_id', true));
        self::assertSame(self::OPERATOR, (int) get_post($data['post_id'])->post_author);

        self::assertSame(
            [['bcc_post_created', self::OPERATOR, $data['post_id'], $data['act_id']]],
            $GLOBALS['__bcc_actions']
        );
        self::assertSame(
            [(string) self::PAGE_A],
            $GLOBALS['__bcc_marker_at_event'],
            'subscribers must see the marker on the post'
        );
    }

    public function testPlainStatusPostGetsNoMarker(): void
    {
        $response = $this->post(self::STRANGER);

        self::assertSame(200, $response->get_status());
        self::assertSame('', get_post_meta($response->get_data()['data']['post_id'], '_bcc_page_id', true));
    }

    public function testStrangerIsRefusedAndNothingIsWritten(): void
    {
        $this->assertRefusedWithoutWrites(self::STRANGER, self::PAGE_A);
    }

    public function testOperatorOfAnotherPageIsRefusedAndNothingIsWritten(): void
    {
        $this->assertRefusedWithoutWrites(self::OPERATOR_B, self::PAGE_A);
    }

    public function testUnclaimedPageIsRefusedAndNothingIsWritten(): void
    {
        $this->insertPage(3104, 'Unclaimed', 'unclaimed');
        $this->bindValidator(3104, 'cosmosvaloper1ccc', 'Unclaimed Moniker');

        $this->assertRefusedWithoutWrites(self::OPERATOR, 3104);
    }

    public function testAmbiguousPageRefusesBothClaimantsAndNothingIsWritten(): void
    {
        $this->insertOperatorClaim(self::STRANGER, $this->validatorA);

        $this->assertRefusedWithoutWrites(self::OPERATOR, self::PAGE_A);
        $this->assertRefusedWithoutWrites(self::STRANGER, self::PAGE_A);
    }

    public function testNonValidatorPageIsRefusedAndNothingIsWritten(): void
    {
        $this->assertRefusedWithoutWrites(self::OPERATOR, self::PAGE_NOT_VAL);
    }

    /**
     * post_author is moved by the claim flow, so owning the post must not read
     * as being the operator.
     */
    public function testPostAuthorOfThePageGrantsNothing(): void
    {
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_author' => self::STRANGER], ['ID' => self::PAGE_A]);

        $this->assertRefusedWithoutWrites(self::STRANGER, self::PAGE_A);
    }

    public function testSuspendedOperatorIsRefusedAsSuspendedAndNothingIsWritten(): void
    {
        $GLOBALS['__bcc_suspended'] = [self::OPERATOR];

        $before   = $this->snapshot();
        $response = $this->post(self::OPERATOR, ['target_page_id' => self::PAGE_A]);

        self::assertSame(403, $response->get_status());
        self::assertSame('Your account is suspended.', $response->get_data()['error']['message']);
        $this->assertNothingWritten($before);
    }

    public function testPageAndGroupTogetherAreRefusedEvenForTheOperator(): void
    {
        $before   = $this->snapshot();
        $response = $this->post(self::OPERATOR, ['target_page_id' => self::PAGE_A, 'group_id' => 77]);

        self::assertSame(400, $response->get_status());
        self::assertSame('bcc_invalid_request', $response->get_data()['error']['code']);
        $this->assertNothingWritten($before);
    }

    public function testAnOperatorsInvalidPostFailsOnContentNotOnAuthority(): void
    {
        $before   = $this->snapshot();
        $response = $this->post(self::OPERATOR, ['target_page_id' => self::PAGE_A, 'content' => '   ']);

        self::assertSame(400, $response->get_status());
        self::assertSame('bcc_invalid_request', $response->get_data()['error']['code']);
        $this->assertNothingWritten($before);
    }

    /** `target_page_id` on a review still names the page under review. */
    public function testReviewKeepsItsOwnMeaningOfTargetPageId(): void
    {
        $before   = $this->snapshot();
        $response = $this->post(self::STRANGER, [
            'kind'           => 'review',
            'target_page_id' => self::PAGE_A,
            'grade'          => 'not-a-grade',
        ]);

        self::assertSame(400, $response->get_status());
        self::assertSame('bcc_invalid_request', $response->get_data()['error']['code']);
        self::assertArrayNotHasKey('data', $response->get_data()['error'], 'no operator gate on a review');
        $this->assertNothingWritten($before);
    }

    // ── the page feed ───────────────────────────────────────────────────

    public function testFeedRouteIsARegisteredPublicGet(): void
    {
        $GLOBALS['__bcc_routes'] = [];
        PagesEndpoint::register();

        $routes = array_column($GLOBALS['__bcc_routes'], 'args', 'route');
        $route  = $routes['/pages/(?P<id>\d+)/feed'] ?? null;

        self::assertIsArray($route, 'the feed route must be registered');
        self::assertSame('GET', $route['methods']);
        self::assertSame('__return_true', $route['permission_callback']);
        self::assertSame(20, $route['args']['limit']['default']);
        self::assertSame(50, $route['args']['limit']['maximum']);
    }

    public function testFeedHoldsOnlyThatPagesPostsNewestFirst(): void
    {
        $first  = $this->postedActId(self::OPERATOR, self::PAGE_A);
        $this->postedActId(self::OPERATOR_B, self::PAGE_B);
        $this->postedActId(self::OPERATOR);
        $this->postedActId(self::STRANGER);
        $second = $this->postedActId(self::OPERATOR, self::PAGE_A);

        $response = $this->feed(self::PAGE_A);

        self::assertSame(200, $response->get_status());
        self::assertSame(['feed_' . $second, 'feed_' . $first], $this->ids($response));
    }

    public function testAnonymousViewerCanReadWithTheGroupFeedHeaders(): void
    {
        $actId = $this->postedActId(self::OPERATOR, self::PAGE_A);

        $GLOBALS['__bcc_current_user'] = 0;
        $response                      = $this->feed(self::PAGE_A);

        self::assertSame(200, $response->get_status());
        self::assertSame(['feed_' . $actId], $this->ids($response));
        self::assertSame(
            ['Cache-Control' => 'private, no-store', 'Vary' => 'Authorization, Cookie'],
            $response->get_headers()
        );
        self::assertSame(['items', 'pagination'], array_keys($response->get_data()['data']));
        self::assertSame('v1', $response->get_data()['_meta']['version']);
    }

    public function testExOperatorsEarlierPostStaysWithItsLabelButNewOnesAreRefused(): void
    {
        $actId = $this->postedActId(self::OPERATOR, self::PAGE_A);

        global $wpdb;
        $removed = $wpdb->delete($wpdb->prefix . 'bcc_onchain_claims', ['user_id' => self::OPERATOR]);
        self::assertSame(1, $removed);

        $this->assertRefusedWithoutWrites(self::OPERATOR, self::PAGE_A);

        $GLOBALS['__bcc_current_user'] = 0;
        $items = $this->feed(self::PAGE_A)->get_data()['data']['items'];
        self::assertSame('feed_' . $actId, $items[0]['id']);
        self::assertSame('Operator of Live Moniker A', $items[0]['page_context']['label']);
    }

    public function testPagePostAlsoAppearsOnTheAuthorsWallTheFloorAndThePermalink(): void
    {
        $actId = $this->postedActId(self::OPERATOR, self::PAGE_A);
        $id    = 'feed_' . $actId;
        $ranking = Plugin::instance()->feedRankingService();

        $expected = [
            'id'     => self::PAGE_A,
            'name'   => 'Live Moniker A',
            'handle' => 'page-a',
            'url'    => '/v/page-a',
            'label'  => 'Operator of Live Moniker A',
        ];

        $wall = $ranking->getActivityForAuthor(self::OPERATOR, 0)['items'];
        self::assertSame([$id], array_column($wall, 'id'));
        self::assertSame($expected, $wall[0]['page_context']);

        $floor = $ranking->getFeed(self::STRANGER, 'for_you')['items'];
        self::assertSame([$id], array_column($floor, 'id'));
        self::assertSame($expected, $floor[0]['page_context']);

        $hot = $ranking->getHotFeed()['items'];
        self::assertSame([$id], array_column($hot, 'id'));
        self::assertSame($expected, $hot[0]['page_context']);

        $permalink = (new FeedEndpoint())->feedItem(new \WP_REST_Request(['id' => $actId]));
        self::assertSame(200, $permalink->get_status());
        self::assertSame($expected, $permalink->get_data()['data']['page_context']);

        $pageFeed = $this->feed(self::PAGE_A)->get_data()['data']['items'];
        self::assertSame($expected, $pageFeed[0]['page_context']);
    }

    public function testPlainPostCarriesANullPageContextKey(): void
    {
        $this->postedActId(self::STRANGER);

        $items = Plugin::instance()->feedRankingService()->getActivityForAuthor(self::STRANGER, 0)['items'];

        self::assertArrayHasKey('page_context', $items[0]);
        self::assertNull($items[0]['page_context']);
    }

    /** The blog tab builds FeedItems itself, outside the hydration pipeline. */
    public function testBlogTabItemsCarryANullPageContextKey(): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'post_type'    => 'peepso-post',
            'post_status'  => 'publish',
            'post_author'  => self::OPERATOR,
            'post_content' => 'Long form',
            'post_excerpt' => 'Short form',
        ]), 'blog post fixture: ' . $wpdb->last_error);
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_activities', [
            'act_owner_id'    => self::OPERATOR,
            'act_module_id'   => 'blog',
            'act_external_id' => (int) $wpdb->insert_id,
        ]), 'blog activity fixture: ' . $wpdb->last_error);

        $items = Plugin::instance()->blogService()->getUserBlog(self::OPERATOR, 0)['items'];

        self::assertCount(1, $items);
        self::assertArrayHasKey('page_context', $items[0]);
        self::assertNull($items[0]['page_context']);
    }

    public function testPageContextGoesNullOnceThePageIsNoLongerPublished(): void
    {
        $this->postedActId(self::OPERATOR, self::PAGE_A);
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_status' => 'trash'], ['ID' => self::PAGE_A]);

        $items = Plugin::instance()->feedRankingService()->getActivityForAuthor(self::OPERATOR, 0)['items'];

        self::assertCount(1, $items, 'the post itself stays on the wall');
        self::assertNull($items[0]['page_context']);
    }

    public function testPageContextFallsBackToThePageTitleWithoutAMoniker(): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'bcc_onchain_validators', ['moniker' => '  '], ['id' => $this->validatorA]);
        $this->postedActId(self::OPERATOR, self::PAGE_A);

        $context = $this->feed(self::PAGE_A)->get_data()['data']['items'][0]['page_context'];

        self::assertSame('Frozen Title A', $context['name']);
        self::assertSame('Operator of Frozen Title A', $context['label']);
    }

    public function testNotFoundUnlessThePageIsAPublishedValidatorPage(): void
    {
        global $wpdb;
        $trashed = 3105;
        $this->insertPage($trashed, 'Trashed', 'trashed');
        $this->bindValidator($trashed, 'cosmosvaloper1ddd', 'Trashed Moniker');
        $wpdb->update($wpdb->posts, ['post_status' => 'trash'], ['ID' => $trashed]);

        $wpdb->insert($wpdb->posts, ['ID' => 3106, 'post_type' => 'post', 'post_status' => 'publish']);
        update_post_meta(3106, '_bcc_onchain_validator_id', (string) $this->validatorA);

        foreach ([self::PAGE_NOT_VAL, $trashed, 3106, 999999, 0] as $pageId) {
            $response = $this->feed($pageId);

            self::assertSame(404, $response->get_status(), "page {$pageId}");
            self::assertSame('bcc_not_found', $response->get_data()['error']['code'], "page {$pageId}");
        }
    }

    public function testPaginationWalksEveryPostOnceAndStops(): void
    {
        $expected = [];
        for ($i = 0; $i < 5; ++$i) {
            array_unshift($expected, 'feed_' . $this->postedActId(self::OPERATOR, self::PAGE_A));
            $this->postedActId(self::OPERATOR);
        }

        $seen   = [];
        $cursor = null;
        $pages  = [];
        do {
            $pagination = $this->feed(self::PAGE_A, $cursor, 2);
            $seen       = array_merge($seen, $this->ids($pagination));
            $meta       = $pagination->get_data()['data']['pagination'];
            $pages[]    = $meta['has_more'];
            $cursor     = $meta['next_cursor'];
        } while ($cursor !== null && count($pages) < 10);

        self::assertSame($expected, $seen);
        self::assertSame([true, true, false], $pages);
    }

    public function testPostsAtTheSameInstantStillPaginateWithoutLossOrRepeats(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->postedActId(self::OPERATOR, self::PAGE_A);
        }
        global $wpdb;
        $wpdb->query("UPDATE {$wpdb->posts} SET post_date_gmt = '2027-01-01 00:00:00' WHERE post_type = 'peepso-post'");

        $first  = $this->feed(self::PAGE_A, null, 2);
        $second = $this->feed(self::PAGE_A, $first->get_data()['data']['pagination']['next_cursor'], 2);

        self::assertSame(
            ['feed_' . $ids[2], 'feed_' . $ids[1], 'feed_' . $ids[0]],
            array_merge($this->ids($first), $this->ids($second))
        );
    }

    public function testModerationHiddenPostLeavesTheFeed(): void
    {
        $hidden  = $this->postedActId(self::OPERATOR, self::PAGE_A);
        $visible = $this->postedActId(self::OPERATOR, self::PAGE_A);

        self::assertTrue(Plugin::instance()->hiddenActivityRepository()->add($hidden, self::STRANGER, 'spam'));

        self::assertSame(['feed_' . $visible], $this->ids($this->feed(self::PAGE_A)));
    }

    public function testBlockHidesTheAuthorInBothDirections(): void
    {
        global $wpdb;
        $this->postedActId(self::OPERATOR, self::PAGE_A);

        $table = $wpdb->prefix . 'peepso_blocks';
        self::assertNotFalse($wpdb->insert($table, ['blk_user_id' => self::STRANGER, 'blk_blocked_id' => self::OPERATOR]));
        $GLOBALS['__bcc_current_user'] = self::STRANGER;
        self::assertSame([], $this->ids($this->feed(self::PAGE_A)), 'viewer blocked the author');

        $wpdb->query("DELETE FROM {$table}");
        self::assertNotFalse($wpdb->insert($table, ['blk_user_id' => self::OPERATOR, 'blk_blocked_id' => self::STRANGER]));
        self::assertSame([], $this->ids($this->feed(self::PAGE_A)), 'author blocked the viewer');

        $GLOBALS['__bcc_current_user'] = 0;
        self::assertCount(1, $this->ids($this->feed(self::PAGE_A)), 'an anonymous viewer has no block list');
    }

    public function testReputationShadowListHidesTheAuthor(): void
    {
        global $wpdb;
        $this->postedActId(self::OPERATOR, self::PAGE_A);

        $ok = $wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
            'page_id'         => \BCC\Trust\Core\Services\MemberSelfPageService::ID_BASE + self::OPERATOR,
            'page_owner_id'   => self::OPERATOR,
            'category_id'     => 0,
            'reputation_tier' => 'caution',
        ]);
        self::assertNotFalse($ok, 'score fixture: ' . $wpdb->last_error);

        // Anyone else (anonymous here: no block list can explain an empty
        // page) does not see the flagged author's post.
        $GLOBALS['__bcc_current_user'] = 0;
        self::assertSame([], $this->ids($this->feed(self::PAGE_A)));

        // F-269 (user decision 2026-11-12): the author always sees their own.
        $GLOBALS['__bcc_current_user'] = self::OPERATOR;
        self::assertCount(1, $this->ids($this->feed(self::PAGE_A)), 'the flagged author still sees their own post');
    }

    // ── the card ────────────────────────────────────────────────────────

    /**
     * The frontend mounts the page stream on this key, so it must not inherit
     * the announcements switch, which omits the sibling `announcements` key.
     */
    public function testPageStreamIsEmittedWithAnnouncementsOffForEveryViewer(): void
    {
        $GLOBALS['__bcc_options']['bcc_announcements_enabled'] = false;
        $GLOBALS['__bcc_test_options']['bcc_announcements_enabled'] = false;
        self::assertFalse(\BCC\Trust\Core\Announcements\AnnouncementFeature::isEnabled());

        $cards = Plugin::instance()->cardViewService();

        foreach ([
            'operator'       => [self::OPERATOR, true],
            'other operator' => [self::OPERATOR_B, false],
            'stranger'       => [self::STRANGER, false],
            'anonymous'      => [0, false],
        ] as $who => [$viewerId, $canPost]) {
            $card = $cards->getCard('validator', 'page-a', $viewerId);

            self::assertIsArray($card, $who);
            self::assertArrayNotHasKey('announcements', $card, $who);
            self::assertSame(['can_post' => $canPost], $card['page_stream'] ?? null, $who);
        }
    }

    public function testPageStreamIsEmittedWithAnnouncementsOnToo(): void
    {
        $GLOBALS['__bcc_options']['bcc_announcements_enabled'] = true;
        $GLOBALS['__bcc_test_options']['bcc_announcements_enabled'] = true;
        self::assertTrue(\BCC\Trust\Core\Announcements\AnnouncementFeature::isEnabled());

        $card = Plugin::instance()->cardViewService()->getCard('validator', 'page-a', self::OPERATOR);

        self::assertIsArray($card);
        self::assertArrayHasKey('announcements', $card);
        self::assertSame(['can_post' => true], $card['page_stream']);
    }

    /**
     * `can_post_as_entity` reads the mirror row and post_author moves with the
     * claim flow. Neither may open the composer, or the write gate behind it.
     */
    public function testMirrorRowAndOwnershipGrantNeitherTheComposerNorThePost(): void
    {
        global $wpdb;
        $GLOBALS['__bcc_owns_page'] = true;
        $wpdb->update($wpdb->posts, ['post_author' => self::STRANGER], ['ID' => self::PAGE_A]);
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
            'user_id'        => self::STRANGER,
            'entity_type'    => 'page',
            'entity_id'      => self::PAGE_A,
            'wallet_address' => 'cosmos1mirror',
            'chain_id'       => $this->chainId,
            'claim_role'     => 'operator',
            'status'         => 'verified',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'mirror fixture: ' . $wpdb->last_error);

        $card = Plugin::instance()->cardViewService()->getCard('validator', 'page-a', self::STRANGER);

        self::assertIsArray($card);
        self::assertSame(['can_post' => false], $card['page_stream']);
        $this->assertRefusedWithoutWrites(self::STRANGER, self::PAGE_A);
    }

    public function testNonValidatorCardsHaveNoPageStream(): void
    {
        update_post_meta(self::PAGE_NOT_VAL, '_bcc_page_type', 'builder');

        $card = Plugin::instance()->cardViewService()->getCard('project', 'builder-page', self::OPERATOR);

        self::assertIsArray($card);
        self::assertArrayNotHasKey('page_stream', $card);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function assertRefusedWithoutWrites(int $userId, int $pageId): void
    {
        $GLOBALS['__bcc_actions']        = [];
        $GLOBALS['__bcc_throttle_calls'] = [];
        $before   = $this->snapshot();
        $response = $this->post($userId, ['target_page_id' => $pageId]);

        self::assertSame(403, $response->get_status());
        self::assertSame(self::REFUSAL_BODY, $response->get_data()['error']);
        $this->assertNothingWritten($before);
    }

    /** @param array<string, mixed> $before */
    private function assertNothingWritten(array $before): void
    {
        self::assertSame($before, $this->snapshot(), 'a refused post must not change any row');
        self::assertSame([], $GLOBALS['__bcc_actions'], 'a refused post must not fire any event');
        self::assertSame([], $GLOBALS['__bcc_throttle_calls'], 'a refused post must not burn a throttle slot');
    }

    /** @param array<string, mixed> $extra */
    private function post(int $userId, array $extra = []): \WP_REST_Response
    {
        $GLOBALS['__bcc_current_user'] = $userId;

        return (new PostsEndpoint())->create(new \WP_REST_Request($extra + [
            'kind'    => 'status',
            'content' => 'Everyday update',
        ]));
    }

    private function postedActId(int $userId, int $pageId = 0): int
    {
        $response = $this->post($userId, $pageId > 0 ? ['target_page_id' => $pageId] : []);
        self::assertSame(200, $response->get_status(), (string) json_encode($response->get_data()));

        return (int) $response->get_data()['data']['act_id'];
    }

    private function feed(int $pageId, ?string $cursor = null, ?int $limit = null): \WP_REST_Response
    {
        return (new PagesEndpoint())->feed(new \WP_REST_Request([
            'id'     => $pageId,
            'cursor' => $cursor,
            'limit'  => $limit,
        ]));
    }

    /** @return list<string> */
    private function ids(\WP_REST_Response $response): array
    {
        self::assertSame(200, $response->get_status());

        return array_column($response->get_data()['data']['items'], 'id');
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        global $wpdb;
        $out = [];
        foreach (
            [
                $wpdb->posts                       => 'ID',
                $wpdb->postmeta                    => 'meta_id',
                $wpdb->prefix . 'peepso_activities' => 'act_id',
            ] as $table => $order
        ) {
            $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY `{$order}` ASC", ARRAY_A);
            self::assertSame('', (string) $wpdb->last_error, "snapshot of {$table} must really run");
            $out[$table] = $rows;
        }

        return $out;
    }

    /**
     * The harness's wp_posts is deliberately reduced, and PeepSo's tables are
     * not part of it. Every case runs in its own process on a freshly rebuilt
     * database, so these are built once per case; tearDown() takes them away.
     */
    private function buildFeedTables(): void
    {
        global $wpdb;

        // Add only what the shared bootstrap's wp_posts lacks: other suites
        // widen that table too, and a second ADD of one column fails.
        $wanted = [
            'post_author'   => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'post_content'  => 'LONGTEXT NULL',
            'post_excerpt'  => 'TEXT NULL',
            'post_date_gmt' => "DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'",
        ];
        $existing = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $wpdb->posts
        ));
        $adds = [];
        foreach ($wanted as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $adds[] = "ADD COLUMN {$column} {$definition}";
            }
        }

        $statements = [];
        if ($adds !== []) {
            $statements[] = "ALTER TABLE {$wpdb->posts} " . implode(', ', $adds);
        }
        $statements = [
            ...$statements,
            "CREATE TABLE {$wpdb->prefix}peepso_activities (
                act_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                act_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                act_module_id VARCHAR(20) NOT NULL DEFAULT '0',
                act_external_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                act_access INT NOT NULL DEFAULT 10,
                act_comment_object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (act_id)
            ) DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}peepso_blocks (
                blk_user_id BIGINT UNSIGNED NOT NULL,
                blk_blocked_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (blk_user_id, blk_blocked_id)
            ) DEFAULT CHARSET=utf8mb4",
            // ⚠ Required by §F-02: the per-post visibility gate LEFT JOINs
            // this table to read each author's profile level. The join is
            // LEFT, so an author with no row reads as level 10 (public) and
            // the fixtures behave as they did — but a MISSING TABLE is a SQL
            // error, which surfaces as an empty result set rather than an
            // error, i.e. every feed assertion failing for a reason that
            // looks like a visibility bug. Same shape as peepso_blocks:
            // created if absent, dropped in teardown.
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}peepso_users (
                usr_id BIGINT UNSIGNED NOT NULL,
                usr_profile_acc TINYINT NOT NULL DEFAULT 10,
                PRIMARY KEY (usr_id)
            ) DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($statements as $sql) {
            self::assertNotFalse($wpdb->query($sql), 'feed fixture: ' . $wpdb->last_error);
        }

        require_once dirname(__DIR__, 2) . '/includes/database/schema-hidden-activities.php';
        bcc_trust_create_hidden_activities_table();
    }

    private function insertPage(int $pageId, string $title, string $slug): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->posts, [
            'ID'          => $pageId,
            'post_type'   => 'peepso-page',
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_name'   => $slug,
        ]);
        self::assertNotFalse($ok, 'page fixture: ' . $wpdb->last_error);
        update_post_meta($pageId, '_bcc_page_type', 'validator');
    }

    private function bindValidator(int $pageId, string $operatorAddress, string $moniker): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'operator_address' => $operatorAddress,
            'chain_id'         => $this->chainId,
            'moniker'          => $moniker,
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        self::assertNotFalse($ok, 'validator fixture: ' . $wpdb->last_error);
        $validatorId = (int) $wpdb->insert_id;
        self::assertTrue(update_post_meta($pageId, '_bcc_onchain_validator_id', (string) $validatorId));

        return $validatorId;
    }

    private function insertOperatorClaim(int $userId, int $validatorId): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
            'user_id'        => $userId,
            'entity_type'    => 'validator',
            'entity_id'      => $validatorId,
            'wallet_address' => 'cosmos1fixture' . $userId,
            'chain_id'       => $this->chainId,
            'claim_role'     => 'operator',
            'status'         => 'verified',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'claim fixture: ' . $wpdb->last_error);
    }
}
