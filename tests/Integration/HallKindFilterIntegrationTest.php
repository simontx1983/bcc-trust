<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\FeedColdStartService;
use BCC\Trust\Onchain\Repositories\HallRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Topic Halls, the read side, on a real server.
 *
 * bcc-core's Hall-kind filter (listHalls() / countHalls() `$hallKind`): a
 * chain Hall has a chain tag naming a chain; a topic Hall has a non-empty
 * topic and no such chain tag; a Hall with neither is only in the
 * unfiltered list. The page and its total agree under every filter.
 *
 * bcc-trust on top of it: each Hall's `hall_kind` / `topic`
 * (HallRepository::kindFactsForHalls) follow the same rule, on GET /halls
 * and GET /halls/:slug; an untrusted read leaves them null; the feed's
 * cold-start panel tops a home chain's Halls up with topic Halls.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(HallRepository::class)]
#[CoversClass(FeedColdStartService::class)]
final class HallKindFilterIntegrationTest extends TestCase
{
    private const CHAIN_A      = 997101;   // chain tag 7
    private const CHAIN_B      = 997102;   // chain tag 9
    private const BOTH         = 997103;   // chain tag 7 AND a topic: a chain Hall
    private const TOPIC        = 997104;   // topic 'defi'
    private const ZERO_TAG     = 997105;   // chain tag '0' and a topic: names no chain, so a topic Hall
    private const GENERAL      = 997106;   // neither: a Hall from before topics
    private const EMPTY_TOPIC  = 997107;   // an empty topic row: neither
    private const DRAFT_TOPIC  = 997108;   // a topic Hall, not published
    private const NOT_A_HALL   = 997109;   // a topic row on a group that is not a Hall

    private const MEMBERS_ON_TOPIC = [997601, 997602];

    /** @var array<int, array{string, string, bool, ?string, ?string}> id => [title, status, hall, chain tag, topic] */
    private const GROUPS = [
        self::CHAIN_A     => ['Kind A Chain Hall', 'publish', true, '7', null],
        self::CHAIN_B     => ['Kind B Chain Hall', 'publish', true, '9', null],
        self::BOTH        => ['Kind C Chain Hall with topic', 'publish', true, '7', 'defi'],
        self::TOPIC       => ['Kind D DeFi', 'publish', true, null, 'defi'],
        self::ZERO_TAG    => ['Kind E NFTs', 'publish', true, '0', 'nfts'],
        self::GENERAL     => ['Kind F General', 'publish', true, null, null],
        self::EMPTY_TOPIC => ['Kind G Empty topic', 'publish', true, null, ''],
        self::DRAFT_TOPIC => ['Kind H Draft topic', 'draft', true, null, 'mining'],
        self::NOT_A_HALL  => ['Kind I Member group', 'publish', false, null, 'defi'],
    ];

    protected function setUp(): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_group_members` (
                gm_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gm_user_id BIGINT UNSIGNED NOT NULL,
                gm_group_id BIGINT UNSIGNED NOT NULL,
                gm_user_status VARCHAR(32) NOT NULL DEFAULT 'member',
                gm_joined DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (gm_id)
            )"
        ), $wpdb->last_error);
        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    /** @return array<string, array{?string, list<int>}> */
    public static function filters(): array
    {
        return [
            'no kind: every published Hall' => [null, [self::CHAIN_A, self::CHAIN_B, self::BOTH, self::TOPIC, self::ZERO_TAG, self::GENERAL, self::EMPTY_TOPIC]],
            'chain'                         => [PeepSoGroupRepository::HALL_KIND_CHAIN, [self::CHAIN_A, self::CHAIN_B, self::BOTH]],
            '⭐ topic: a topic and no chain'  => [PeepSoGroupRepository::HALL_KIND_TOPIC, [self::TOPIC, self::ZERO_TAG]],
            'an unknown kind: nothing'      => ['general', []],
        ];
    }

    /** @param list<int> $expected in title order */
    #[DataProvider('filters')]
    public function testEachKindKeepsItsHallsAndTheTotalAgrees(?string $kind, array $expected): void
    {
        self::assertSame($expected, $this->ids(PeepSoGroupRepository::listHalls(null, 0, 50, '', $kind)));
        self::assertSame(count($expected), PeepSoGroupRepository::countHalls(null, '', $kind));
    }

    /** GET /halls takes exactly bcc-core's Hall kinds (it holds them as literals, so loading it never loads core). */
    public function testTheRouteTakesCoresHallKinds(): void
    {
        self::assertSame(
            [PeepSoGroupRepository::HALL_KIND_CHAIN, PeepSoGroupRepository::HALL_KIND_TOPIC],
            \BCC\Trust\Core\REST\HallsEndpoint::HALL_KINDS
        );
    }

    public function testTheKindsNeverOverlap(): void
    {
        $chain = $this->ids(PeepSoGroupRepository::listHalls(null, 0, 50, '', PeepSoGroupRepository::HALL_KIND_CHAIN));
        $topic = $this->ids(PeepSoGroupRepository::listHalls(null, 0, 50, '', PeepSoGroupRepository::HALL_KIND_TOPIC));

        self::assertSame([], array_values(array_intersect($chain, $topic)));
    }

    public function testItCombinesWithTheChainFilter(): void
    {
        self::assertSame([self::CHAIN_A, self::BOTH], $this->ids(PeepSoGroupRepository::listHalls(7, 0, 50, '', PeepSoGroupRepository::HALL_KIND_CHAIN)));
        self::assertSame(2, PeepSoGroupRepository::countHalls(7, '', PeepSoGroupRepository::HALL_KIND_CHAIN));
        self::assertSame([], PeepSoGroupRepository::listHalls(7, 0, 50, '', PeepSoGroupRepository::HALL_KIND_TOPIC), 'a chain filter and a topic Hall exclude each other');
        self::assertSame(0, PeepSoGroupRepository::countHalls(7, '', PeepSoGroupRepository::HALL_KIND_TOPIC));
    }

    public function testItCombinesWithTheNameFilter(): void
    {
        self::assertSame([self::TOPIC], $this->ids(PeepSoGroupRepository::listHalls(null, 0, 50, 'defi', PeepSoGroupRepository::HALL_KIND_TOPIC)));
        self::assertSame(1, PeepSoGroupRepository::countHalls(null, 'defi', PeepSoGroupRepository::HALL_KIND_TOPIC));
    }

    /** The filter is an EXISTS, not a join: member counts are not multiplied. */
    public function testMemberCountsStayExactUnderAKind(): void
    {
        $rows = PeepSoGroupRepository::listHalls(null, 0, 50, '', PeepSoGroupRepository::HALL_KIND_TOPIC);
        $byId = array_column(array_map(static fn (object $r): array => ['id' => (int) $r->id, 'n' => (int) $r->member_count], $rows), 'n', 'id');

        self::assertSame(count(self::MEMBERS_ON_TOPIC), $byId[self::TOPIC] ?? null);
    }

    public function testItPagesInTitleOrder(): void
    {
        self::assertSame([self::CHAIN_B], $this->ids(PeepSoGroupRepository::listHalls(null, 1, 1, '', PeepSoGroupRepository::HALL_KIND_CHAIN)));
        self::assertSame([self::ZERO_TAG], $this->ids(PeepSoGroupRepository::listHalls(null, 1, 5, '', PeepSoGroupRepository::HALL_KIND_TOPIC)));
    }

    // ── bcc-trust: hall_kind and topic ──────────────────────────────────

    /** ⭐ Every Hall's `hall_kind` is the filter that lists it, and only that one. */
    public function testEachHallsKindIsTheFilterThatListsIt(): void
    {
        $halls = array_keys(array_filter(self::GROUPS, static fn (array $g): bool => $g[2] && $g[1] === 'publish'));
        $chain = $this->ids(PeepSoGroupRepository::listHalls(null, 0, 50, '', PeepSoGroupRepository::HALL_KIND_CHAIN));
        $topic = $this->ids(PeepSoGroupRepository::listHalls(null, 0, 50, '', PeepSoGroupRepository::HALL_KIND_TOPIC));

        $facts = HallRepository::kindFactsForHalls($halls);
        self::assertIsArray($facts);
        self::assertSame(self::sorted($halls), self::sorted(array_keys($facts)), 'every requested Hall is answered');
        foreach ($halls as $id) {
            $expected = in_array($id, $chain, true) ? 'chain' : (in_array($id, $topic, true) ? 'topic' : null);
            self::assertSame($expected, $facts[$id]['hall_kind'], "Hall {$id}");
            self::assertSame($expected === 'topic', $facts[$id]['topic'] !== null, "Hall {$id}: a topic only on a topic Hall");
        }
    }

    public function testATopicCarriesItsLabelOrFallsBackToItsSlug(): void
    {
        $facts = HallRepository::kindFactsForHalls([self::TOPIC, self::ZERO_TAG]);

        self::assertSame(['slug' => 'defi', 'label' => 'DeFi'], $facts[self::TOPIC]['topic'] ?? null);
        self::assertSame(['slug' => 'nfts', 'label' => 'nfts'], $facts[self::ZERO_TAG]['topic'] ?? null, 'no label row: the slug');
    }

    public function testTheHallsListCarriesKindAndTopic(): void
    {
        $halls = Plugin::instance()->hallsService();

        $topic = $halls->getHalls(0, 1, 50, null, '', PeepSoGroupRepository::HALL_KIND_TOPIC);
        self::assertSame([self::TOPIC, self::ZERO_TAG], array_column($topic['items'], 'id'));
        self::assertSame(['topic', 'topic'], array_column($topic['items'], 'hall_kind'));
        self::assertSame(['slug' => 'defi', 'label' => 'DeFi'], $topic['items'][0]['topic']);
        self::assertNull($topic['items'][0]['chain'], 'a topic Hall has no chain');
        self::assertSame(2, $topic['pagination']['total']);

        $chain = $halls->getHalls(0, 1, 50, null, '', PeepSoGroupRepository::HALL_KIND_CHAIN);
        self::assertSame([self::CHAIN_A, self::CHAIN_B, self::BOTH], array_column($chain['items'], 'id'));
        self::assertSame(['chain', 'chain', 'chain'], array_column($chain['items'], 'hall_kind'));
        self::assertSame([null, null, null], array_column($chain['items'], 'topic'), 'a chain Hall shows no topic, even with a topic row');

        $all     = $halls->getHalls(0, 1, 50, null, '');
        $general = array_values(array_filter($all['items'], static fn (array $i): bool => $i['id'] === self::GENERAL));
        self::assertSame([[null, null]], array_map(static fn (array $i): array => [$i['hall_kind'], $i['topic']], $general), 'neither: null, nothing inferred');
    }

    public function testTheHallDetailCarriesKindAndTopic(): void
    {
        $hall = Plugin::instance()->hallsService()->getHall(0, 'kind-' . self::TOPIC);

        self::assertIsArray($hall);
        self::assertSame('topic', $hall['hall_kind']);
        self::assertSame(['slug' => 'defi', 'label' => 'DeFi'], $hall['topic']);
        self::assertNull($hall['chain']);
        self::assertNull($hall['chain_profile']);
    }

    /** @return array<string, array{string}> */
    public static function untrusted(): array
    {
        return ['the read errors' => ['failQueriesMatching'], 'the read bails before flush()' => ['failSilentlyMatching']];
    }

    /** An untrusted facts read says nothing about the kind rather than guess; the list itself still answers. */
    #[DataProvider('untrusted')]
    public function testAnUntrustedFactsReadLeavesKindAndTopicNull(string $fault): void
    {
        global $wpdb;
        // Rows another read left behind, as wpdb's pre-flush bail would return them.
        self::assertNotEmpty($wpdb->get_results('SELECT 1 AS left_behind'));

        // Only the facts read names the label key; the core filter does not.
        $wpdb->{$fault} = '/_bcc_hall_topic_label/';
        try {
            $facts = HallRepository::kindFactsForHalls([self::TOPIC]);
            $page  = Plugin::instance()->hallsService()->getHalls(0, 1, 50, null, '', PeepSoGroupRepository::HALL_KIND_TOPIC);
            $fired = $wpdb->injectedFailures + $wpdb->silentFailures;
        } finally {
            $wpdb->clearFaultInjection();
        }

        self::assertGreaterThan(0, $fired, 'the fault must actually have fired');
        self::assertNull($facts);
        self::assertSame([self::TOPIC, self::ZERO_TAG], array_column($page['items'], 'id'));
        self::assertSame([null, null], array_column($page['items'], 'hall_kind'));
        self::assertSame([null, null], array_column($page['items'], 'topic'));
    }

    // ── bcc-trust: the feed's cold-start panel ──────────────────────────

    public function testTheColdStartTopsAHomeChainUpWithTopicHalls(): void
    {
        self::assertSame([self::CHAIN_A, self::BOTH, self::TOPIC], $this->ids(FeedColdStartService::hallRows(7, 3)));
        self::assertSame([self::CHAIN_B, self::TOPIC, self::ZERO_TAG], $this->ids(FeedColdStartService::hallRows(9, 3)));
        self::assertSame([self::CHAIN_A, self::BOTH], $this->ids(FeedColdStartService::hallRows(7, 2)), 'full: no top-up');
        self::assertSame([self::CHAIN_A, self::CHAIN_B, self::BOTH], $this->ids(FeedColdStartService::hallRows(null, 3)), 'no home chain: unchanged, title order');
    }

    // ── fixture ─────────────────────────────────────────────────────────

    /**
     * @param list<object> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_values(array_map(static fn (object $r): int => (int) $r->id, $rows));
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);
        return $ids;
    }

    private function seed(): void
    {
        global $wpdb;
        foreach (self::GROUPS as $id => [$title, $status, $hall, $chainTag, $topic]) {
            self::assertNotFalse($wpdb->insert($wpdb->posts, [
                'ID' => $id, 'post_title' => $title, 'post_name' => 'kind-' . $id,
                'post_type' => 'peepso-group', 'post_status' => $status,
            ]), $wpdb->last_error);
            $meta = array_filter([
                '_bcc_group_kind'       => $hall ? 'hall' : null,
                '_bcc_chain_tag'        => $chainTag,
                '_bcc_hall_topic'       => $topic,
                // One label, on the DeFi Hall; ZERO_TAG has none (its slug labels it).
                '_bcc_hall_topic_label' => $id === self::TOPIC ? 'DeFi' : null,
            ], static fn (?string $v): bool => $v !== null);
            foreach ($meta as $key => $value) {
                self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => $key, 'meta_value' => $value]), $wpdb->last_error);
            }
        }
        foreach (self::MEMBERS_ON_TOPIC as $userId) {
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_group_members', [
                'gm_user_id' => $userId, 'gm_group_id' => self::TOPIC, 'gm_user_status' => 'member',
            ]), $wpdb->last_error);
        }
    }

    private function purge(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
        $wpdb->query("DELETE FROM {$wpdb->posts}");
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}peepso_group_members` WHERE gm_group_id BETWEEN %d AND %d", 997100, 997199));
    }
}
