<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\REST\UserEndorsementsEndpoint;
use BCC\Trust\Core\REST\UsersEndpoint;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-421: the three routes that read the vouches a member has GIVEN answer a
 * retryable 503 when that read did not run, never an answer that looks like
 * "none":
 *
 *   - GET /users/:handle/endorsements  (the profile's vouches-given tab)
 *   - GET /endorsements/mine
 *   - GET /endorsements/mine/stats
 *
 * All three read AttestationRepository::listActiveVouchesByAttestor(). wpdb's
 * get_results() hands back [] on a failed query, so before F-421 a failed
 * read answered 200 `items: []` ("hasn't vouched for any pages yet") or
 * zeroed stats ("0 given").
 *
 * The real repository, service and REST handlers run over a real
 * attestations table, with ONE statement broken by the harness: the vouch
 * read. The control first proves the same calls return the seeded vouch
 * when nothing is broken, so a 503 below cannot come from a broken setup.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(AttestationRepository::class)]
#[CoversClass(UserEndorsementsEndpoint::class)]
#[CoversClass(UsersEndpoint::class)]
final class EndorsementsGivenReadFailureIntegrationTest extends TestCase
{
    private const VOUCHER = 942101;
    private const TARGET  = 942102;

    private const MESSAGE = 'Vouches are temporarily unavailable. Please try again.';

    /** The given-vouches read, and no other statement. */
    private const VOUCH_READ = '/SELECT id, target_kind, target_id, weight_at_time, context_note, created_at\s+FROM\s+`\S*bcc_trust_attestations`/';

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-groups-integration-stubs.php';
        require_once dirname(__DIR__, 2) . '/includes/database/schema-trust-attestations.php';

        $GLOBALS['__bcc_pg'] = ['viewer' => self::VOUCHER, 'users' => [], 'meta' => []];
        foreach ([self::VOUCHER => 'voucher', self::TARGET => 'target'] as $id => $name) {
            $GLOBALS['__bcc_pg']['users'][$id] = ['login' => 'f421_' . $name, 'handle' => 'f421' . $name, 'name' => 'F421 ' . $name];
            $GLOBALS['__bcc_pg']['meta'][$id]  = ['bcc_handle' => 'f421' . $name];
        }
        $GLOBALS['__bcc_test_caps'] = [];

        // As in ProfileGroupsVisibilityIntegrationTest: the REAL bcc-trust
        // TrustReadService answers bcc-core's suspension check, or the
        // profile gate fails closed and the tab answers 403 before it reads.
        $locatorCache = new \ReflectionProperty(\BCC\Core\ServiceLocator::class, 'cache');
        $resolved     = (array) $locatorCache->getValue();
        $resolved['bcc.resolve.trust_read_service'] = \BCC\Trust\Core\Plugin::instance()->trustReadService();
        $locatorCache->setValue(null, $resolved);

        bcc_trust_create_trust_attestations_table();
        $this->purge();

        global $wpdb;
        self::assertNotFalse($wpdb->insert(TableRegistry::trustAttestations(), [
            'attestor_user_id' => self::VOUCHER,
            'target_kind'      => 'user_profile',
            'target_id'        => self::TARGET,
            'kind'             => 'vouch',
            'weight_at_time'   => 1.0,
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
        unset($GLOBALS['__bcc_pg'], $GLOBALS['__bcc_test_caps']);
    }

    /** @return array<string, array{string}> */
    public static function routes(): array
    {
        return [
            'the profile tab, /users/:handle/endorsements' => ['tab'],
            '/endorsements/mine'                           => ['mine'],
            '/endorsements/mine/stats'                     => ['stats'],
        ];
    }

    /** The control: with nothing broken, each route answers the seeded vouch. */
    #[DataProvider('routes')]
    public function testWithNoFaultTheRouteAnswersTheSeededVouch(string $route): void
    {
        $response = $this->call($route);

        self::assertSame(200, $response->get_status());
        self::assertSame(1, self::vouchCount($route, $response), 'the seeded vouch, read for real');
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures);
    }

    #[DataProvider('routes')]
    public function testAFailedVouchReadIsA503NotAnEmptyAnswer(string $route): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = self::VOUCH_READ;

        self::assertUnavailable($this->call($route));
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault really hit the vouch read');
    }

    /**
     * ⚠ wpdb's SILENT failure paths return false with no `last_error` and
     * leave the previous query's rows in `last_result`. runRead() checks
     * query()'s own return, so this is still a 503.
     */
    #[DataProvider('routes')]
    public function testASilentFailedVouchReadIsA503Too(string $route): void
    {
        $GLOBALS['wpdb']->failSilentlyMatching = self::VOUCH_READ;

        self::assertUnavailable($this->call($route));
        self::assertGreaterThan(0, $GLOBALS['wpdb']->silentFailures, 'the fault really hit the vouch read');
    }

    /** Any later caller gets the same verdict: the repository throws, it never answers []. */
    public function testTheRepositoryThrowsRatherThanAnsweringEmpty(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = self::VOUCH_READ;

        $this->expectException(RepositoryReadFailure::class);
        (new AttestationRepository())->listActiveVouchesByAttestor(self::VOUCHER, 20);
    }

    private function call(string $route): \WP_REST_Response
    {
        return match ($route) {
            'tab'   => (new UsersEndpoint())->endorsements(new \WP_REST_Request(['handle' => 'f421voucher', 'limit' => 20])),
            'mine'  => UserEndorsementsEndpoint::handleList(new \WP_REST_Request(['limit' => 20])),
            'stats' => UserEndorsementsEndpoint::handleStats(new \WP_REST_Request([])),
        };
    }

    /** How many vouches the route reported: `total` on the lists, `total_endorsements_given` on the stats. */
    private static function vouchCount(string $route, \WP_REST_Response $response): int
    {
        $data = $response->get_data();

        return (int) match ($route) {
            'tab'   => $data['data']['total'],
            'mine'  => $data['total'],
            'stats' => $data['total_endorsements_given'],
        };
    }

    /** The retryable refusal, with nothing cached and no page in it. */
    private static function assertUnavailable(\WP_REST_Response $response): void
    {
        self::assertSame(503, $response->get_status());
        self::assertSame(
            ['code' => 'bcc_unavailable', 'message' => self::MESSAGE, 'status' => 503],
            $response->get_data()['error']
        );
        self::assertArrayNotHasKey('data', $response->get_data(), 'no list and no stats, not even empty ones');
        self::assertSame(['Cache-Control' => 'private, no-store'], $response->get_headers());
    }

    private function purge(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `' . TableRegistry::trustAttestations() . '` WHERE attestor_user_id = %d',
            self::VOUCHER
        ));
    }
}
