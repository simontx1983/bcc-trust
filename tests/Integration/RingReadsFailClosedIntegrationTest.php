<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\TrustGraph;
use BCC\Trust\Core\Services\CronService;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-209 — a failed ring-edge read is never cached as "no rings".
 *
 * The three ring-edge reads came back as [] on a DB error, which the
 * detectors cached for an hour and the daily cron's 'ring_results' for a
 * day: every ring vanished from the admin dashboard and FraudDetector.
 * Against real MySQL / MariaDB:
 *   - each read throws RepositoryReadFailure on a failed query (and still
 *     returns rows when nothing fails);
 *   - detectVoteRings / detectSlowEndorsementRings cache nothing and leave
 *     their cursor alone on a failure;
 *   - CronService::dailyGraphUpdate() leaves the last good 'ring_results'
 *     in place, and overwrites it as before when the reads succeed;
 *   - weeklySlowRingScan() does not let the failure escape the cron.
 *
 * ⚠ Faults are injected with failQueriesMatching, not by renaming a table
 * (the schema pass would re-create it empty). Every failure case asserts
 * the fault actually fired.
 */
#[Group('integration')]
#[CoversClass(VoteRepository::class)]
#[CoversClass(AttestationRepository::class)]
#[CoversClass(TrustGraph::class)]
#[CoversClass(CronService::class)]
final class RingReadsFailClosedIntegrationTest extends TestCase
{
    private const VOTE_PAIRS   = 'v1.voter_user_id as user_a';
    private const VOUCH_EDGES  = 'a.attestor_user_id AS endorser_user_id';
    private const VOUCH_PAIRS  = 'a1.attestor_user_id AS user_a';
    private const SENTINEL     = [['users' => [1, 2, 3], 'size' => 3, 'strength' => 9.0, 'detected_at' => 'last good run']];

    protected function setUp(): void
    {
        // The vouch reads need the attestations table; the harness does not
        // create it by default (MutualVouchPairsPagingIntegrationTest precedent).
        require_once dirname(__DIR__, 2) . '/includes/database/schema-trust-attestations.php';
        bcc_trust_create_trust_attestations_table();
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['__bcc_test_object_cache'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['__bcc_test_object_cache'] = [];
    }

    private function failOnly(string $sqlFragment): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote($sqlFragment, '/') . '/';
    }

    /** @return array<string, array{string, \Closure(): array<mixed>}> */
    public static function ringReads(): array
    {
        return [
            'mutual vote pairs'  => [self::VOTE_PAIRS, static fn (): array => (new VoteRepository())->getMutualVotePairs(3)],
            'vouch edges'        => [self::VOUCH_EDGES, static fn (): array => (new AttestationRepository())->getVouchEdges(100)],
            'mutual vouch pairs' => [self::VOUCH_PAIRS, static fn (): array => (new AttestationRepository())->getMutualVouchPairsInWindow(14, 1, 50)],
        ];
    }

    /** @param \Closure(): array<mixed> $read */
    #[DataProvider('ringReads')]
    public function testAFailedRingReadThrowsInsteadOfReturningNoRows(string $fragment, \Closure $read): void
    {
        $this->failOnly($fragment);

        try {
            $read();
            self::fail('a failed read must not come back as "no rows"');
        } catch (RepositoryReadFailure) {
        }

        self::assertSame(1, $GLOBALS['wpdb']->injectedFailures, 'exactly this read must have failed');
    }

    /** @param \Closure(): array<mixed> $read */
    #[DataProvider('ringReads')]
    public function testWithNoFaultTheRingReadStillRuns(string $fragment, \Closure $read): void
    {
        self::assertIsArray($read(), 'the SQL itself must run on this server');
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures);
    }

    public function testAFailedVoteRingDetectionCachesNothingAndKeepsTheCursor(): void
    {
        wp_cache_set('vote_ring_cursor', [41, 42], TrustGraph::CACHE_GROUP);
        $this->failOnly(self::VOTE_PAIRS);

        try {
            Plugin::instance()->trustGraph()->detectVoteRings(3);
            self::fail('detectVoteRings must propagate the failure');
        } catch (RepositoryReadFailure) {
        }

        self::assertSame(1, $GLOBALS['wpdb']->injectedFailures);
        self::assertFalse(wp_cache_get('vote_rings', TrustGraph::CACHE_GROUP), 'a failure must never be cached as "no rings"');
        self::assertSame([41, 42], wp_cache_get('vote_ring_cursor', TrustGraph::CACHE_GROUP), 'the walk resumes where it was');
    }

    public function testAFailedSlowRingDetectionCachesNothingAndKeepsTheCursor(): void
    {
        wp_cache_set('slow_endorsement_ring_cursor_w14', [41, 42], TrustGraph::CACHE_GROUP);
        $this->failOnly(self::VOUCH_PAIRS);

        try {
            Plugin::instance()->trustGraph()->detectSlowEndorsementRings(14, 3);
            self::fail('detectSlowEndorsementRings must propagate the failure');
        } catch (RepositoryReadFailure) {
        }

        self::assertSame(1, $GLOBALS['wpdb']->injectedFailures);
        self::assertFalse(wp_cache_get('slow_endorsement_rings_w14', TrustGraph::CACHE_GROUP));
        self::assertSame([41, 42], wp_cache_get('slow_endorsement_ring_cursor_w14', TrustGraph::CACHE_GROUP), 'a failed page is not a short page');
    }

    /** @return array<string, array{string}> */
    public static function clusterReads(): array
    {
        return ['vote pairs' => [self::VOTE_PAIRS], 'vouch edges' => [self::VOUCH_EDGES]];
    }

    #[DataProvider('clusterReads')]
    public function testTheDailyCronKeepsTheLastGoodRingResultsOnAFailedRead(string $fragment): void
    {
        wp_cache_set('ring_results', self::SENTINEL, 'bcc_trust_admin', DAY_IN_SECONDS);
        $this->failOnly($fragment);

        Plugin::instance()->cronService()->dailyGraphUpdate();

        self::assertGreaterThanOrEqual(1, $GLOBALS['wpdb']->injectedFailures, 'the fault must actually fire');
        self::assertSame(self::SENTINEL, wp_cache_get('ring_results', 'bcc_trust_admin'), 'ring_results must not be overwritten with a failure');
    }

    public function testWithNoFaultTheDailyCronStillRefreshesRingResults(): void
    {
        // Control: the sentinel IS replaced by a real (here empty-world) run.
        wp_cache_set('ring_results', self::SENTINEL, 'bcc_trust_admin', DAY_IN_SECONDS);

        Plugin::instance()->cronService()->dailyGraphUpdate();

        $after = wp_cache_get('ring_results', 'bcc_trust_admin');
        self::assertIsArray($after);
        self::assertNotSame(self::SENTINEL, $after);
    }

    public function testTheWeeklySlowRingScanDoesNotLetAFailureEscapeTheCron(): void
    {
        $this->failOnly(self::VOUCH_PAIRS);

        Plugin::instance()->cronService()->weeklySlowRingScan();

        self::assertSame(1, $GLOBALS['wpdb']->injectedFailures);
        self::assertFalse(wp_cache_get('slow_endorsement_rings_w14', TrustGraph::CACHE_GROUP));
    }
}
