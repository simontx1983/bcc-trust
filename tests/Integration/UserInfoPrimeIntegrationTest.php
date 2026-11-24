<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Support\MemberSummaryPrefetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * UserInfoRepository::primeByUserIds(): one read fills the user_info cache
 * for a list of members, so the suspension check behind every member's
 * `flags` (getByUserIdOrThrow) is answered from the cache instead of with
 * one query per member on a cold cache.
 *
 * The cache rules it must keep are getByUserIdOrThrow()'s own: the row, or
 * '' for "no row", under the same key and TTL; only members with no entry
 * are read; and a read it cannot trust caches NOTHING.
 *
 * Real MySQL / MariaDB rows; the harness's in-memory object cache.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(UserInfoRepository::class)]
final class UserInfoPrimeIntegrationTest extends TestCase
{
    private const WITH_ROW_A = 957801;
    private const WITH_ROW_B = 957802;
    private const NO_ROW     = 957803;

    private const PRIME_READ = '/FROM\s+\S*bcc_trust_user_info\s+WHERE user_id IN \(/';

    protected function setUp(): void
    {
        global $wpdb;
        $GLOBALS['__bcc_test_object_cache'] = [];
        $wpdb->clearFaultInjection();
        $this->purge();
        foreach ([self::WITH_ROW_A, self::WITH_ROW_B] as $id) {
            self::assertNotFalse($wpdb->insert(TableRegistry::userInfo(), [
                'user_id' => $id, 'user_login' => 'prime_' . $id, 'user_email' => $id . '@example.test',
                'display_name' => 'Prime ' . $id, 'is_suspended' => $id === self::WITH_ROW_B ? 1 : 0,
            ]), $wpdb->last_error);
        }
        \BCC\Core\Log\Logger::reset();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    private static function repo(): UserInfoRepository
    {
        return new UserInfoRepository();
    }

    /** One read for the list; afterwards every member is answered from the cache. */
    public function testOneReadServesEveryMember(): void
    {
        global $wpdb;
        $repo = self::repo();

        $wpdb->queryCount = 0;
        $repo->primeByUserIds([self::WITH_ROW_A, self::WITH_ROW_B, self::NO_ROW, self::WITH_ROW_A]);
        self::assertSame(1, $wpdb->queryCount, 'one read for three members');

        $a  = $repo->getByUserIdOrThrow(self::WITH_ROW_A);
        $b  = $repo->getByUserIdOrThrow(self::WITH_ROW_B);
        $no = $repo->getByUserIdOrThrow(self::NO_ROW);
        self::assertSame(1, $wpdb->queryCount, 'every member answered from the cache');

        self::assertSame(self::WITH_ROW_A, (int) ($a->user_id ?? 0));
        self::assertSame(self::WITH_ROW_B, (int) ($b->user_id ?? 0));
        self::assertSame('1', (string) ($b->is_suspended ?? ''), 'the row the suspension check reads');
        self::assertNull($no, 'no row');
    }

    /** Exactly what getByUserIdOrThrow() would have cached: the row, or '' for no row. */
    public function testItCachesWhatTheSingleReadCaches(): void
    {
        self::repo()->primeByUserIds([self::WITH_ROW_A, self::NO_ROW]);
        $primed = [
            wp_cache_get('user_info_' . self::WITH_ROW_A, 'bcc_trust'),
            wp_cache_get('user_info_' . self::NO_ROW, 'bcc_trust'),
        ];

        $GLOBALS['__bcc_test_object_cache'] = [];
        self::repo()->getByUserIdOrThrow(self::WITH_ROW_A);
        self::repo()->getByUserIdOrThrow(self::NO_ROW);
        $single = [
            wp_cache_get('user_info_' . self::WITH_ROW_A, 'bcc_trust'),
            wp_cache_get('user_info_' . self::NO_ROW, 'bcc_trust'),
        ];

        self::assertSame('', $primed[1], 'no row is cached as an empty string');
        self::assertEquals($single, $primed);
    }

    /** A member already cached is neither read again nor overwritten. */
    public function testACachedMemberIsNotReadOrOverwritten(): void
    {
        global $wpdb;
        $sentinel = (object) ['user_id' => (string) self::WITH_ROW_A, 'is_suspended' => '0', 'marker' => 'cached'];
        wp_cache_set('user_info_' . self::WITH_ROW_A, $sentinel, 'bcc_trust');
        wp_cache_set('user_info_' . self::NO_ROW, '', 'bcc_trust');

        $wpdb->queryCount = 0;
        self::repo()->primeByUserIds([self::WITH_ROW_A, self::NO_ROW]);
        self::assertSame(0, $wpdb->queryCount, 'everyone cached: nothing to read');

        self::repo()->primeByUserIds([self::WITH_ROW_A, self::WITH_ROW_B]);
        self::assertSame(1, $wpdb->queryCount);
        self::assertSame($sentinel, wp_cache_get('user_info_' . self::WITH_ROW_A, 'bcc_trust'), 'left as it was');
        self::assertSame(self::WITH_ROW_B, (int) (wp_cache_get('user_info_' . self::WITH_ROW_B, 'bcc_trust')->user_id ?? 0));
    }

    /** @return array<string, array{string}> */
    public static function faults(): array
    {
        return ['a failed read' => ['failQueriesMatching'], 'a silent failed read' => ['failSilentlyMatching']];
    }

    /**
     * A read that cannot be trusted caches NOTHING: no row and no '' (which
     * would read as "not suspended"). Each member then gets its own read.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('faults')]
    public function testAReadThatFailedCachesNothing(string $fault): void
    {
        global $wpdb;
        $wpdb->{$fault} = self::PRIME_READ;

        self::repo()->primeByUserIds([self::WITH_ROW_A, self::WITH_ROW_B, self::NO_ROW]);

        self::assertGreaterThan(0, $fault === 'failQueriesMatching' ? $wpdb->injectedFailures : $wpdb->silentFailures, 'the fault hit the prime read');
        foreach ([self::WITH_ROW_A, self::WITH_ROW_B, self::NO_ROW] as $id) {
            self::assertFalse(wp_cache_get('user_info_' . $id, 'bcc_trust'), "member {$id}: nothing cached");
        }
        $errors = array_filter(\BCC\Core\Log\Logger::$lines, static fn (array $l): bool => $l['level'] === 'error' && str_contains($l['message'], 'prime read failed'));
        self::assertCount(1, $errors);

        $wpdb->clearFaultInjection();
        self::assertSame(self::WITH_ROW_B, (int) (self::repo()->getByUserIdOrThrow(self::WITH_ROW_B)->user_id ?? 0), 'the single read still answers');
    }

    /** A long list is read in chunks of 200, each one read. */
    public function testALongListIsReadInChunks(): void
    {
        global $wpdb;
        $ids = range(958001, 958201); // 201 members with no row

        $wpdb->queryCount = 0;
        self::repo()->primeByUserIds($ids);

        self::assertSame(2, $wpdb->queryCount);
        self::assertSame('', wp_cache_get('user_info_958201', 'bcc_trust'), 'the second chunk was cached too');
    }

    /** Every member list's prefetch primes it, so the flags read no row per member. */
    public function testTheMemberPrefetchPrimesIt(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-groups-integration-stubs.php';
        $GLOBALS['__bcc_pg'] = ['viewer' => 0, 'users' => [], 'meta' => []];
        global $wpdb;

        MemberSummaryPrefetcher::primeFor([self::WITH_ROW_A, self::WITH_ROW_B, self::NO_ROW], 0);

        $wpdb->queryCount = 0;
        $repo = Plugin::instance()->userInfoRepository();
        foreach ([self::WITH_ROW_A, self::WITH_ROW_B, self::NO_ROW] as $id) {
            $repo->getByUserIdOrThrow($id);
        }
        self::assertSame(0, $wpdb->queryCount, 'each member answered from the primed cache');
    }

    private function purge(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . TableRegistry::userInfo() . ' WHERE user_id IN (%d, %d, %d)',
            self::WITH_ROW_A,
            self::WITH_ROW_B,
            self::NO_ROW
        ));
    }
}
