<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Rank\Services\RankStateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-539: a member's own findings (the /me/progression `findings` block)
 * never read as "no findings" when they could not be loaded. Either read
 * failing (the active findings, or the recently reversed ones) gives
 * `findings_unavailable: true` with an empty list, all or nothing; a
 * genuinely clean member is `[]` with `findings_unavailable: false`.
 *
 * Real findings rows; the real repository reads and the service's block.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(RankStateService::class)]
final class ProgressionFindingsUnavailableIntegrationTest extends TestCase
{
    private const MEMBER = 959801;
    private const CLEAN  = 959802;

    private const ACTIVE_READ   = "/FROM\\s+\\S*bcc_trust_findings\\s+WHERE subject_user_id = \\d+ AND status = 'active'/";
    private const REVERSED_READ = "/FROM\\s+\\S*bcc_trust_findings\\s+WHERE subject_user_id = \\d+ AND status = 'reversed'/";

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', TableRegistry::findings())) !== TableRegistry::findings()) {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-findings.php';
            bcc_trust_create_findings_table();
        }
        $this->purge();

        $now = gmdate('Y-m-d H:i:s');
        foreach ([['active', null], ['reversed', gmdate('Y-m-d H:i:s', time() - 86400)]] as [$status, $reversedAt]) {
            self::assertNotFalse($wpdb->insert(TableRegistry::findings(), [
                'subject_user_id' => self::MEMBER, 'finding_type' => 'f539-' . $status, 'severity' => 'serious', 'source' => 'admin',
                'issued_by' => 1, 'created_at' => $now, 'effective_at' => $now, 'score_penalty' => 25,
                'ceiling_rank_slug' => 'journeyman', 'ceiling_expires_at' => '2027-06-01 00:00:00',
                'penalty_expires_at' => '2027-06-01 00:00:00', 'status' => $status, 'reversed_at' => $reversedAt,
            ]), $wpdb->last_error);
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    /** @return array{items: list<array<string, mixed>>, unavailable: bool} */
    private static function block(int $userId): array
    {
        $method = new \ReflectionMethod(RankStateService::class, 'findingsBlock');
        /** @var array{items: list<array<string, mixed>>, unavailable: bool} */
        return $method->invoke(Plugin::instance()->rankStateService(), $userId);
    }

    public function testActiveFindingsAndRecentReversalsAreListed(): void
    {
        $block = self::block(self::MEMBER);

        self::assertFalse($block['unavailable']);
        self::assertSame(['f539-active', 'f539-reversed'], array_column($block['items'], 'type'));
        self::assertSame('journeyman', $block['items'][0]['ceiling_rank'], 'the active ceiling is shown');
    }

    public function testAMemberWithNoFindingsIsAnEmptyListThatIsAvailable(): void
    {
        self::assertSame(['items' => [], 'unavailable' => false], self::block(self::CLEAN));
    }

    /** @return array<string, array{string, string}> */
    public static function failures(): array
    {
        return [
            'the active read fails'                  => [self::ACTIVE_READ, 'failQueriesMatching'],
            'the active read fails silently'         => [self::ACTIVE_READ, 'failSilentlyMatching'],
            'the recently-reversed read fails'       => [self::REVERSED_READ, 'failQueriesMatching'],
            'the recently-reversed read fails silently' => [self::REVERSED_READ, 'failSilentlyMatching'],
        ];
    }

    /**
     * A read that fails is "could not be loaded", never "no findings", and
     * the list is empty rather than partial (it could leave out an active
     * ceiling).
     */
    #[DataProvider('failures')]
    public function testAFailedReadIsUnavailableNeverEmpty(string $read, string $fault): void
    {
        global $wpdb;
        $wpdb->{$fault} = $read;

        $block = self::block(self::MEMBER);

        self::assertGreaterThan(0, $fault === 'failQueriesMatching' ? $wpdb->injectedFailures : $wpdb->silentFailures, 'the fault hit the read');
        self::assertSame(['items' => [], 'unavailable' => true], $block);
    }

    /**
     * The /me/progression block carries the flag beside the list: through
     * the real progressionFor(), with only the rank assessment fixed (its
     * own reads are covered elsewhere).
     */
    public function testTheProgressionCarriesTheFlagBesideTheList(): void
    {
        // The session and user lookups progressionFor() asks (get_userdata etc.).
        require_once dirname(__DIR__) . '/Stubs/profile-groups-integration-stubs.php';
        $GLOBALS['__bcc_pg'] = [
            'viewer' => self::MEMBER,
            'users'  => [self::MEMBER => ['login' => 'f539', 'handle' => 'f539', 'name' => 'F539']],
            'meta'   => [self::MEMBER => ['bcc_handle' => 'f539']],
        ];
        global $wpdb;
        $engine  = new class extends \BCC\Trust\Rank\Services\RankPromotionEngine {
            public function __construct()
            {
            }

            public function assess(int $userId): ?array
            {
                unset($userId);
                return [
                    'row'                => (object) ['rank_slug' => 'journeyman', 'recovery_status' => null, 'recovery_deadline' => null],
                    'categories'         => [],
                    'total'              => 40.0,
                    'contribution_types' => 3,
                    'recognizers'        => 3,
                    'outcomes'           => 1,
                    'outcome_types'      => 1,
                    'windows'            => [],
                    'decay'              => 0.0,
                ];
            }
        };
        $plugin  = Plugin::instance();
        $service = new RankStateService(
            $plugin->rankStateRepository(),
            $engine,
            $plugin->apprenticeReadinessService(),
            $plugin->rankScoringConfig(),
            $plugin->findingsRepository()
        );

        $ok = $service->progressionFor(self::MEMBER);
        self::assertFalse($ok['findings_unavailable']);
        self::assertCount(2, $ok['findings']);

        $wpdb->failQueriesMatching = self::ACTIVE_READ;
        $failed = $service->progressionFor(self::MEMBER);
        self::assertTrue($failed['findings_unavailable'], 'a failed read is flagged in the progression');
        self::assertSame([], $failed['findings']);
        self::assertSame('ranked', $failed['member_state'], 'the rest of the progression still renders');
    }

    private function purge(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM `' . TableRegistry::findings() . '` WHERE subject_user_id IN (%d, %d)', self::MEMBER, self::CLEAN));
    }
}
