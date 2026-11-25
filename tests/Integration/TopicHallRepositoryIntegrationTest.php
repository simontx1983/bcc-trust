<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\HallRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Topic Halls (F-597), HallRepository's topic reads and writes on a real
 * server: the reverse lookup the topic provisioner runs inside its lock, the
 * address checks, the marker write, and the admin edit's Hall check. The
 * provisioner's reads are verified: a read that cannot be trusted throws
 * (or, for the admin check, answers null), never "no Hall".
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(HallRepository::class)]
final class TopicHallRepositoryIntegrationTest extends TestCase
{
    private const DEFI       = 998101;   // a published topic Hall, topic 'defi'
    private const ARCHIVED   = 998102;   // a topic Hall in 'private' status, topic 'nfts'
    private const NOT_A_HALL = 998103;   // a member group carrying a topic row, address 'mining'
    private const TWO_KINDS  = 998104;   // a 'hall' row and a 'holders' row
    private const CHAIN_HALL = 998105;   // a chain Hall

    protected function setUp(): void
    {
        $this->purge();
        $this->group(self::DEFI, 'defi', 'publish', ['_bcc_group_kind' => ['hall'], '_bcc_hall_topic' => ['defi'], '_bcc_hall_topic_label' => ['DeFi']]);
        $this->group(self::ARCHIVED, 'nfts', 'private', ['_bcc_group_kind' => ['hall'], '_bcc_hall_topic' => ['nfts']]);
        $this->group(self::NOT_A_HALL, 'mining', 'publish', ['_bcc_hall_topic' => ['mining']]);
        $this->group(self::TWO_KINDS, 'two-kinds', 'publish', ['_bcc_group_kind' => ['hall', 'holders']]);
        $this->group(self::CHAIN_HALL, 'cosmos-hall', 'publish', ['_bcc_group_kind' => ['hall'], '_bcc_chain_tag' => ['7']]);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    public function testATopicResolvesToItsHallInAnyStatus(): void
    {
        self::assertSame(self::DEFI, HallRepository::findHallForTopic('defi'));
        self::assertSame(self::ARCHIVED, HallRepository::findHallForTopic('nfts'), 'an archived topic Hall keeps its topic');
        self::assertNull(HallRepository::findHallForTopic('mining'), 'a topic row on a group that is not a Hall');
        self::assertNull(HallRepository::findHallForTopic('gaming'));
        self::assertNull(HallRepository::findHallForTopic(''));
    }

    public function testAnAddressIsTakenByAnyGroupInAnyStatus(): void
    {
        self::assertTrue(HallRepository::groupSlugInUse('defi'));
        self::assertTrue(HallRepository::groupSlugInUse('nfts'), 'a private group still holds its address');
        self::assertTrue(HallRepository::groupSlugInUse('mining'), 'any group, Hall or not');
        self::assertFalse(HallRepository::groupSlugInUse('gaming'));

        self::assertSame('defi', HallRepository::groupSlug(self::DEFI));
        self::assertNull(HallRepository::groupSlug(998199));
    }

    public function testTheMarkerMakesAGroupTheTopicsHall(): void
    {
        $this->group(998106, 'gaming', 'publish', []);

        HallRepository::writeTopicHallMeta(998106, 'gaming', 'Gaming');

        self::assertSame(998106, HallRepository::findHallForTopic('gaming'));
        self::assertSame(['hall_kind' => 'topic', 'topic' => ['slug' => 'gaming', 'label' => 'Gaming']], HallRepository::kindFactsForHalls([998106])[998106] ?? null);

        HallRepository::writeTopicLabel(998106, 'Games');
        self::assertSame('Games', HallRepository::kindFactsForHalls([998106])[998106]['topic']['label'] ?? null);
    }

    public function testTheAdminCheckKnowsAHallInAnyStatus(): void
    {
        self::assertTrue(HallRepository::isHallOrUnknown(self::DEFI));
        self::assertTrue(HallRepository::isHallOrUnknown(self::ARCHIVED));
        self::assertTrue(HallRepository::isHallOrUnknown(self::CHAIN_HALL));
        self::assertFalse(HallRepository::isHallOrUnknown(self::NOT_A_HALL));
        self::assertFalse(HallRepository::isHallOrUnknown(self::TWO_KINDS), 'a second kind row: not a Hall');
        self::assertFalse(HallRepository::isHallOrUnknown(0));
    }

    /** @return array<string, array{string}> */
    public static function faults(): array
    {
        return ['the read errors' => ['failQueriesMatching'], 'the read bails before flush()' => ['failSilentlyMatching']];
    }

    /** ⭐ The provisioner's reads never read "no Hall" from a read that cannot be trusted. */
    #[DataProvider('faults')]
    public function testAnUntrustedReadThrowsOrIsUnknown(string $fault): void
    {
        global $wpdb;
        self::assertNotEmpty($wpdb->get_results('SELECT 1 AS left_behind'));

        $wpdb->{$fault} = '/_bcc_hall_topic|post_name|_bcc_group_kind/';
        $thrown = [];
        try {
            foreach (['findHallForTopic' => static fn () => HallRepository::findHallForTopic('defi'),
                      'groupSlugInUse'   => static fn () => HallRepository::groupSlugInUse('defi'),
                      'groupSlug'        => static fn () => HallRepository::groupSlug(self::DEFI)] as $name => $read) {
                try {
                    $read();
                } catch (RepositoryReadFailure) {
                    $thrown[] = $name;
                }
            }
            $isHall = HallRepository::isHallOrUnknown(self::DEFI);
            $fired  = $wpdb->injectedFailures + $wpdb->silentFailures;
        } finally {
            $wpdb->clearFaultInjection();
        }

        self::assertGreaterThan(0, $fired, 'the fault must actually have fired');
        self::assertSame(['findHallForTopic', 'groupSlugInUse', 'groupSlug'], $thrown);
        self::assertNull($isHall, 'unknown, not "not a Hall"');
    }

    // ── fixture ─────────────────────────────────────────────────────────

    /** @param array<string, list<string>> $meta */
    private function group(int $id, string $slug, string $status, array $meta): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID' => $id, 'post_title' => 'Topic fixture ' . $id, 'post_name' => $slug, 'post_type' => 'peepso-group', 'post_status' => $status,
        ]), $wpdb->last_error);
        foreach ($meta as $key => $values) {
            foreach ($values as $value) {
                self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => $key, 'meta_value' => $value]), $wpdb->last_error);
            }
        }
    }

    private function purge(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
        $wpdb->query("DELETE FROM {$wpdb->posts}");
    }
}
