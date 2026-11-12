<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security {
    // The account-age step reads WordPress users. Scoped to FraudDetector's
    // namespace (same pattern as UserSyncPreservesTrustStateIntegrationTest)
    // so no other suite sees it: an old account, so the age signal is quiet.
    if (!function_exists(__NAMESPACE__ . '\\get_userdata')) {
        function get_userdata(int $id): object|false
        {
            return (object) ['ID' => $id, 'user_registered' => '2020-01-01 00:00:00'];
        }
    }
}

namespace BCC\Trust\Tests\Integration {

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\AuditLogRepository;
use BCC\Trust\Core\Repositories\DeviceFingerprintRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\FraudDetector;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-208 PR 2: a FAILED signal read must not score as "no signal".
 *
 * analyzeFraud()'s scoring reads were raw get_var / get_results, so a
 * failed read answered 0 / [] / null — "not rapid voting", "no
 * flip-flopping", "no IP on record", "no other accounts on this network",
 * "no device evidence", "profile not provisioned". getEnhancedFraudScore()
 * then STORED the resulting score and analyzeFraud() cached it for the
 * very window the post-vote retry waits. Each read now throws before
 * anything is stored or cached.
 *
 * The vote-request gates read the SAME queries through fail-open variants,
 * deliberately: a database blip must never block a vote. Those are pinned
 * here too.
 *
 * ⚠ Faults come from failQueriesMatching, never a renamed table (a renamed
 * table is re-created EMPTY by the next schema pass). Every failure case
 * asserts that exactly its own read failed.
 */
#[Group('integration')]
#[CoversClass(FraudDetector::class)]
#[CoversClass(VoteRepository::class)]
#[CoversClass(AuditLogRepository::class)]
#[CoversClass(DeviceFingerprintRepository::class)]
final class FraudSignalReadFailureIntegrationTest extends TestCase
{
    private const UID          = 970501;
    private const STORED_SCORE = 40;
    /** A public address, so detectMultiAccountVoting() does not skip it as private/reserved. */
    private const PUBLIC_IP    = '8.8.4.4';

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        $GLOBALS['__bcc_test_object_cache'] = [];

        foreach ([TableRegistry::userInfo(), TableRegistry::activity(), TableRegistry::fraudAnalysis(), TableRegistry::fingerprints()] as $t) {
            $wpdb->query($wpdb->prepare("DELETE FROM `{$t}` WHERE user_id = %d", self::UID));
        }
        $wpdb->query($wpdb->prepare('DELETE FROM `' . TableRegistry::votes() . '` WHERE voter_user_id = %d', self::UID));

        self::assertNotFalse($wpdb->insert(TableRegistry::userInfo(), [
            'user_id'          => self::UID,
            'user_login'       => 'fixture' . self::UID,
            'user_email'       => 'fixture' . self::UID . '@example.test',
            'display_name'     => 'Fixture ' . self::UID,
            'trust_rank'       => 0.5,
            'fraud_score'      => self::STORED_SCORE,
            'peak_fraud_score' => self::STORED_SCORE,
        ]), (string) $wpdb->last_error);

        // A public IP on record, so the multi-account branch reaches its
        // second read (countDistinctUsersForIp).
        self::assertNotFalse($wpdb->insert(TableRegistry::activity(), [
            'user_id'     => self::UID,
            'action'      => 'fixture_login',
            'target_type' => 'user',
            'target_id'   => self::UID,
            'ip_address'  => inet_pton(self::PUBLIC_IP),
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]), (string) $wpdb->last_error);
    }

    protected function tearDown(): void
    {
        // A failed test must not leave a fault armed for the next one.
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['__bcc_test_object_cache'] = [];
    }

    private function failOnly(string $sqlFragment): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote($sqlFragment, '/') . '/';
    }

    private function storedFraudScore(): int
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT fraud_score FROM `' . TableRegistry::userInfo() . '` WHERE user_id = %d',
            self::UID
        ));
    }

    private function analysisRows(): int
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM `' . TableRegistry::fraudAnalysis() . '` WHERE user_id = %d',
            self::UID
        ));
    }

    /** @return array<string, array{string}> one fragment per scoring read */
    public static function scoringReads(): array
    {
        return [
            'user_info (row 1)'            => ['FROM ' . TableRegistry::userInfo() . ' WHERE user_id'],
            'rapid voting (row 4)'         => ['COUNT(*) AS n FROM ' . TableRegistry::votes()],
            'vote changing (row 5)'        => ['AND v1.vote_type != v2.vote_type'],
            'last IP (row 6)'              => ['AND ip_address IS NOT NULL'],
            'accounts on network (row 6)'  => ['COUNT(DISTINCT user_id) AS n'],
            'device fingerprints (row 7)'  => ['SELECT id, user_id, fingerprint, automation_score, risk_level, last_seen'],
        ];
    }

    #[DataProvider('scoringReads')]
    public function testAFailedScoringReadStoresAndCachesNothing(string $fragment): void
    {
        $before = $this->analysisRows();
        $this->failOnly($fragment);

        try {
            FraudDetector::getEnhancedFraudScore(self::UID);
            self::fail('a failed read must throw, not score as "no signal"');
        } catch (\RuntimeException) {
        }

        self::assertSame(1, $GLOBALS['wpdb']->injectedFailures, 'exactly this read must have failed');
        self::assertSame(self::STORED_SCORE, $this->storedFraudScore(), 'the stored fraud score was rewritten');
        self::assertSame($before, $this->analysisRows(), 'no fraud_analysis row may be written');
        self::assertFalse(wp_cache_get('fraud_analysis_' . self::UID, FraudDetector::CACHE_GROUP), 'the analysis must not be cached for the retry to reuse');
    }

    public function testWithNoFaultTheAnalysisStillStoresItsResult(): void
    {
        // Control: the fixture reaches every read above and completes.
        $before = $this->analysisRows();

        FraudDetector::getEnhancedFraudScore(self::UID);

        self::assertSame($before + 1, $this->analysisRows());
        self::assertNotFalse(wp_cache_get('fraud_analysis_' . self::UID, FraudDetector::CACHE_GROUP));
    }

    // ── the vote-request gates stay fail-OPEN on purpose ─────────────────

    public function testTheRapidVotingVoteGateStaysFailOpen(): void
    {
        $this->failOnly('COUNT(*) AS n FROM ' . TableRegistry::votes());

        self::assertFalse(FraudDetector::detectRapidVoting(self::UID), 'a blip must never block a vote');
        self::assertSame(1, $GLOBALS['wpdb']->injectedFailures);
    }

    public function testTheNetworkCountHasAFailOpenAndAFailClosedVariant(): void
    {
        $repo = new AuditLogRepository();
        $ip   = (string) inet_pton(self::PUBLIC_IP);
        $this->failOnly('COUNT(DISTINCT user_id) AS n');

        self::assertSame(0, $repo->countDistinctUsersForIp($ip, self::UID, 7), 'the new-account vote gate stays fail-open');

        $this->expectException(RepositoryReadFailure::class);
        $repo->countDistinctUsersForIpOrFail($ip, self::UID, 7);
    }

    public function testTheFingerprintListHasAFailOpenAndAFailClosedVariant(): void
    {
        $repo = new DeviceFingerprintRepository();
        $this->failOnly('SELECT id, user_id, fingerprint, automation_score, risk_level, last_seen');

        self::assertSame([], $repo->getUserFingerprints(self::UID), 'the admin user view stays fail-open');

        $this->expectException(RepositoryReadFailure::class);
        $repo->getUserFingerprintsOrFail(self::UID);
    }

    public function testTheVoterCountStillAnswersWhenTheReadSucceeds(): void
    {
        // The refactor onto one query kept both status filters intact.
        $wpdb = $GLOBALS['wpdb'];
        $now  = gmdate('Y-m-d H:i:s');
        foreach ([[970510, 1], [970511, 0]] as [$pageId, $status]) {
            self::assertNotFalse($wpdb->insert(TableRegistry::votes(), [
                'voter_user_id' => self::UID, 'page_id' => $pageId, 'category_id' => 0, 'vote_type' => 1,
                'weight' => 1.0, 'vested_weight' => 1.0, 'status' => $status, 'created_at' => $now,
            ]), (string) $wpdb->last_error);
        }
        $repo  = new VoteRepository();
        $since = gmdate('Y-m-d H:i:s', time() - 3600);

        self::assertSame(1, $repo->countByVoterSince(self::UID, $since), 'active only');
        self::assertSame(2, $repo->countByVoterSince(self::UID, $since, false), 'every status');
        self::assertSame(2, $repo->countByVoterSinceOrFail(self::UID, $since, false));
    }
}
}
