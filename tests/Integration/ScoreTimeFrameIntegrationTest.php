<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Support\SiteClock;
use BCC\Trust\Core\ValueObjects\PageScore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-87 L3 / F-222: score math in ONE time frame.
 *
 * Vote and score rows are stamped site-local (current_time('mysql')). The
 * windows over them were measured against three other clocks — the recalc's
 * PHP DateTimeImmutable (UTC), gmdate() (UTC), UTC_TIMESTAMP() and the DB
 * session's NOW() — which only agree with site-local while the site time zone
 * is UTC. Production is UTC today, so nothing was visibly wrong; these tests
 * run the same code with the site offset by hours, the way core applies
 * gmt_offset ($GLOBALS['__bcc_test_site_utc_offset'] in the bootstrap's
 * current_time()), and on a DB session whose clock is not UTC either.
 *
 * Every assertion fails against the pre-fix code in at least one of the two
 * offsets: +14 h (site ahead of UTC) and -10 h (behind).
 */
#[Group('integration')]
#[CoversClass(ScoreRepository::class)]
#[CoversClass(VoteRepository::class)]
#[CoversClass(SiteClock::class)]
final class ScoreTimeFrameIntegrationTest extends TestCase
{
    private const PAGE_FRESH = 970701;
    private const PAGE_OLD   = 970702;
    private const PAGE_VOTES = 970703;
    private const VOTER_A    = 987101;
    private const VOTER_B    = 987102;

    private string $sessionZone = 'SYSTEM';

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        $GLOBALS['__bcc_test_object_cache'] = [];
        $this->sessionZone = (string) $wpdb->get_var('SELECT @@session.time_zone');
        $this->deleteFixtures();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_test_site_utc_offset']);
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare('SET time_zone = %s', $this->sessionZone));
        $this->deleteFixtures();
        $GLOBALS['__bcc_test_object_cache'] = [];
    }

    private function deleteFixtures(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `' . TableRegistry::scores() . '` WHERE page_id BETWEEN %d AND %d',
            self::PAGE_FRESH,
            self::PAGE_VOTES
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->prefix}bcc_trust_votes` WHERE page_id = %d",
            self::PAGE_VOTES
        ));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `wp_users` WHERE ID IN (%d, %d)',
            self::VOTER_A,
            self::VOTER_B
        ));
    }

    /** @return array<string, array{int}> */
    public static function siteOffsets(): array
    {
        return [
            'site ahead of UTC (+14 h)' => [14 * 3600],
            'site behind UTC (-10 h)'   => [-10 * 3600],
        ];
    }

    private function site(int $offsetSeconds): void
    {
        $GLOBALS['__bcc_test_site_utc_offset'] = $offsetSeconds;
        self::assertNotSame(gmdate('Y-m-d H'), substr(SiteClock::now(), 0, 13), 'precondition: the site clock is not UTC');
    }

    private function seedScore(int $pageId, ?string $lastCalculatedAt, ?string $lastVoteAt = null): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert(TableRegistry::scores(), [
            'page_id'            => $pageId,
            'category_id'        => 0,
            'page_owner_id'      => 970790,
            'total_score'        => 50,
            'last_calculated_at' => $lastCalculatedAt,
            'last_vote_at'       => $lastVoteAt,
        ]), (string) $wpdb->last_error);
    }

    private function column(int $pageId, string $column): string
    {
        $wpdb = $GLOBALS['wpdb'];

        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT {$column} FROM `" . TableRegistry::scores() . '` WHERE page_id = %d AND category_id = 0',
            $pageId
        ));
    }

    // ── F-222: last_calculated_at is written in ONE frame ───────────────

    #[DataProvider('siteOffsets')]
    public function testTheRecalcStampsLastCalculatedAtSiteLocalLikeEveryOtherWriter(int $offset): void
    {
        $this->site($offset);
        $this->seedScore(self::PAGE_FRESH, '2001-01-01 00:00:00');

        (new ScoreRepository())->saveRecalculated(PageScore::createDefault(self::PAGE_FRESH, 970790), 0);

        self::assertEqualsWithDelta(
            strtotime(SiteClock::now()),
            strtotime($this->column(self::PAGE_FRESH, 'last_calculated_at')),
            5,
            'the recalc wrote UTC (PageScore\'s DateTimeImmutable) while every other writer writes site-local'
        );
    }

    #[DataProvider('siteOffsets')]
    public function testTheStaleSweepMeasuresAgeInTheSiteFrame(int $offset): void
    {
        $this->site($offset);
        // The DB session clock is a THIRD zone, as on a server whose MySQL
        // default is not UTC — NOW() must not be what decides.
        $GLOBALS['wpdb']->query("SET time_zone = '+05:00'");

        $this->seedScore(self::PAGE_FRESH, SiteClock::secondsAgo(600));   // 10 min ago
        $this->seedScore(self::PAGE_OLD, SiteClock::secondsAgo(7200));    // 2 h ago

        $stale = (new ScoreRepository())->getStalePageIds(100000);

        self::assertNotContains(self::PAGE_FRESH, $stale, 'a page recalculated 10 minutes ago is not stale');
        self::assertContains(self::PAGE_OLD, $stale, 'a page recalculated 2 hours ago is stale');
    }

    #[DataProvider('siteOffsets')]
    public function testHasVoteSinceMeasuresTheWindowInTheSiteFrame(int $offset): void
    {
        $this->site($offset);
        $this->seedScore(self::PAGE_FRESH, SiteClock::now(), SiteClock::secondsAgo(5));
        $this->seedScore(self::PAGE_OLD, SiteClock::now(), SiteClock::secondsAgo(120));

        $repo = new ScoreRepository();
        self::assertTrue($repo->hasVoteSince(self::PAGE_FRESH, 30), 'a vote 5 s ago is inside a 30 s window');
        self::assertFalse($repo->hasVoteSince(self::PAGE_OLD, 30), 'a vote 2 min ago is outside a 30 s window');
    }

    // ── F-87 L3: the vote aggregate's clocks ─────────────────────────────

    private function insertVote(int $voterId, string $registeredUtc, string $createdAt): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `wp_users` (ID, user_login, user_registered) VALUES (%d, %s, %s)",
            $voterId,
            'f87voter' . $voterId,
            $registeredUtc
        ));
        self::assertSame('', $wpdb->last_error, 'fixture user insert');
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
            'voter_user_id' => $voterId,
            'page_id'       => self::PAGE_VOTES,
            'vote_type'     => 1,
            'weight'        => 2.0,
            'vested_weight' => 2.0,
            'status'        => 1,
            'created_at'    => $createdAt,
        ]), (string) $wpdb->last_error);
    }

    #[DataProvider('siteOffsets')]
    public function testAJustCastVoteIsNotDecayedOrInflated(int $offset): void
    {
        $this->site($offset);
        $this->insertVote(self::VOTER_A, gmdate('Y-m-d H:i:s', time() - 400 * 86400), SiteClock::now());

        $agg = (new VoteRepository())->getVoteAggregatesForPage(self::PAGE_VOTES);

        self::assertSame(1, (int) $agg->vote_count, 'the query ran');
        // Measured against UTC_TIMESTAMP() a site-local created_at looked
        // 14 h in the FUTURE (factor > 1) or 10 h old (decayed).
        self::assertEqualsWithDelta(2.0, (float) $agg->positive_score, 0.0005, 'a vote cast now carries its full vested weight');
    }

    public function testVoterMaturityComparesUserRegisteredInUtc(): void
    {
        // user_registered is core's UTC column. A DB session clock ahead of
        // UTC made NOW() - 7 days fall AFTER a registration that is only
        // 6 days 22 hours old, so a young account counted as mature.
        $GLOBALS['wpdb']->query("SET time_zone = '+10:00'");
        $this->insertVote(self::VOTER_A, gmdate('Y-m-d H:i:s', time() - (7 * 86400 - 7200)), SiteClock::now());
        $this->insertVote(self::VOTER_B, gmdate('Y-m-d H:i:s', time() - 8 * 86400), SiteClock::now());

        $agg = (new VoteRepository())->getVoteAggregatesForPage(self::PAGE_VOTES);

        self::assertSame(2, (int) $agg->unique_voters, 'the query ran over both voters');
        self::assertSame(1, (int) $agg->mature_unique_voters, 'only the 8-day-old account is mature (minimum age 7 days)');
    }

    // ── Guard: no database clock left in the score / vote repositories ──

    public function testNoScoreOrVoteRepositoryQueryReadsTheDatabaseClock(): void
    {
        // The behavioural tests above cover the sweep, the recency window and
        // the aggregate; this pins the remaining windows (cleanup, fraud
        // stats, flip-flop and downvote counters) to bound SiteClock values.
        foreach (['ScoreRepository', 'VoteRepository'] as $class) {
            $src  = (string) file_get_contents(dirname(__DIR__, 2) . "/app/Domain/Core/Repositories/{$class}.php");
            $code = '';
            foreach (token_get_all($src) as $tok) {
                if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($tok) ? $tok[1] : $tok;
            }
            self::assertDoesNotMatchRegularExpression(
                // Not preceded by :: / -> — SiteClock::now() is the fix.
                '/(?<!::)(?<!->)\b(NOW|UTC_TIMESTAMP|CURDATE|CURRENT_TIMESTAMP|SYSDATE)\s*\(/i',
                $code,
                "{$class} compares a site-local column against the database clock"
            );
        }
    }
}
