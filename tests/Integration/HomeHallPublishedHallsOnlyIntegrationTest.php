<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Onchain\Repositories\HallRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A home Hall accepts only published Halls, on a real server: the write
 * (HallsService::setPrimaryHall) and the list reader
 * (HallRepository::primaryHallsForUsers) both go through
 * HallRepository::publishedHallIds().
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(HallRepository::class)]
final class HomeHallPublishedHallsOnlyIntegrationTest extends TestCase
{
    private const MEMBER = 996501;
    private const POINTER = 'bcc_primary_hall_group_id';

    private const HALL        = 996101;
    private const HALL_TWO    = 996106;
    private const DRAFT_HALL  = 996102;
    private const USER_GROUP  = 996103;   // no kind row: a member-made group
    private const SECRET_HALL = 996104;
    private const MIXED_KIND  = 996105;   // a 'hall' row AND a 'holders' row

    /** @var array<int, array{string, list<string>, string|null}> group => [status, kind rows, privacy] */
    private const GROUPS = [
        self::HALL        => ['publish', ['hall'], '0'],
        self::HALL_TWO    => ['publish', ['hall'], null],
        self::DRAFT_HALL  => ['draft', ['hall'], '0'],
        self::USER_GROUP  => ['publish', [], '0'],
        self::SECRET_HALL => ['publish', ['hall'], '2'],
        self::MIXED_KIND  => ['publish', ['hall', 'holders'], '0'],
    ];

    protected function setUp(): void
    {
        global $wpdb;
        self::defineUserMetaDoubles();
        self::assertNotFalse($wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->usermeta}` (
                umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) NULL DEFAULT NULL,
                meta_value LONGTEXT NULL,
                PRIMARY KEY (umeta_id),
                KEY user_id (user_id)
            )"
        ), $wpdb->last_error);
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
        $wpdb->clearFaultInjection();
        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    private static function sorted(array $ids): array
    {
        sort($ids);
        return $ids;
    }

    // ── the one read ───────────────────────────────────────────────────

    public function testOnlyPublishedNonSecretHallsQualify(): void
    {
        self::assertSame(
            [self::HALL, self::HALL_TWO],
            self::sorted(HallRepository::publishedHallIds(array_keys(self::GROUPS)))
        );
        self::assertSame([], HallRepository::publishedHallIds([]));
    }

    // ── the list reader ────────────────────────────────────────────────

    /** Each user points at one fixture group; only those on a published Hall keep a home Hall. */
    public function testTheListReaderKeepsOnlyPointersToPublishedHalls(): void
    {
        $users = [];
        foreach (array_keys(self::GROUPS) as $i => $groupId) {
            $userId          = 996601 + $i;
            $users[$groupId] = $userId;
            $this->pointer($userId, $groupId);
        }

        $kept = HallRepository::primaryHallsForUsers(array_values($users));

        self::assertSame(self::sorted([$users[self::HALL], $users[self::HALL_TWO]]), self::sorted(array_keys($kept)));
        self::assertSame(self::HALL, (int) $kept[$users[self::HALL]]->id);
    }

    // ── the write ──────────────────────────────────────────────────────

    public function testAMemberCanSetAPublishedHall(): void
    {
        $result = Plugin::instance()->hallsService()->setPrimaryHall(self::MEMBER, self::HALL);

        self::assertArrayNotHasKey('error', $result);
        self::assertSame((string) self::HALL, (string) get_user_meta(self::MEMBER, self::POINTER, true));
    }

    /** @return array<string, array{int}> */
    public static function notPublishedHalls(): array
    {
        return [
            'a member-made group'        => [self::USER_GROUP],
            'an unpublished Hall'        => [self::DRAFT_HALL],
            'a secret Hall'              => [self::SECRET_HALL],
            'a group of two kinds'       => [self::MIXED_KIND],
        ];
    }

    /** ⭐ A member of the group still cannot make it their home Hall; the answer is the non-member one, and nothing is written. */
    #[DataProvider('notPublishedHalls')]
    public function testAMemberCannotSetAnythingElse(int $groupId): void
    {
        $result = Plugin::instance()->hallsService()->setPrimaryHall(self::MEMBER, $groupId);

        self::assertSame('bcc_forbidden', $result['error'] ?? null);
        self::assertSame('Join the Hall before setting it as your home Hall.', $result['message'] ?? null);
        self::assertSame('', (string) get_user_meta(self::MEMBER, self::POINTER, true), 'no pointer written');
    }

    // ── a read that cannot be verified ─────────────────────────────────

    /** @return array<string, array{string}> */
    public static function unreadable(): array
    {
        return ['the read errors' => ['failQueriesMatching'], 'the read bails before flush()' => ['failSilentlyMatching']];
    }

    #[DataProvider('unreadable')]
    public function testAnUnreadableCheckRefusesTheWriteAndHidesEveryHomeHall(string $fault): void
    {
        global $wpdb;
        $this->pointer(996701, self::HALL);
        // Rows another read left behind, as wpdb's pre-flush bail would return them.
        self::assertNotEmpty($wpdb->get_results('SELECT 1 AS left_behind'));

        $wpdb->{$fault} = '/k\.meta_value = /';
        try {
            $threw = false;
            try {
                HallRepository::publishedHallIds([self::HALL]);
            } catch (RepositoryReadFailure) {
                $threw = true;
            }
            $write = Plugin::instance()->hallsService()->setPrimaryHall(self::MEMBER, self::HALL);
            $list  = HallRepository::primaryHallsForUsers([996701]);
            $fired = $wpdb->injectedFailures + $wpdb->silentFailures;
        } finally {
            $wpdb->clearFaultInjection();
        }

        self::assertGreaterThan(0, $fired, 'the fault must actually have fired');
        self::assertTrue($threw, 'an unverified read is refused, not answered');
        self::assertSame('bcc_unavailable', $write['error'] ?? null, 'the write is retryable, never a yes');
        self::assertSame('', (string) get_user_meta(self::MEMBER, self::POINTER, true), 'no pointer written');
        self::assertSame([], $list, 'no home Hall shown rather than an unchecked one');
    }

    // ── fixture ─────────────────────────────────────────────────────────

    private function seed(): void
    {
        global $wpdb;
        foreach (self::GROUPS as $id => [$status, $kinds, $privacy]) {
            self::assertNotFalse($wpdb->insert($wpdb->posts, [
                'ID' => $id, 'post_title' => 'Home Hall fixture ' . $id, 'post_name' => 'home-hall-' . $id,
                'post_type' => 'peepso-group', 'post_status' => $status,
            ]), $wpdb->last_error);
            foreach ($kinds as $kind) {
                self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => '_bcc_group_kind', 'meta_value' => $kind]), $wpdb->last_error);
            }
            if ($privacy !== null) {
                self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => 'peepso_group_privacy', 'meta_value' => $privacy]), $wpdb->last_error);
            }
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_group_members', [
                'gm_user_id' => self::MEMBER, 'gm_group_id' => $id, 'gm_user_status' => 'member',
            ]), $wpdb->last_error);
        }
    }

    private function pointer(int $userId, int $groupId): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => self::POINTER, 'meta_value' => (string) $groupId]), $wpdb->last_error);
    }

    private function purge(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
        $wpdb->query("DELETE FROM {$wpdb->posts}");
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}peepso_group_members` WHERE gm_user_id = %d", self::MEMBER));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->usermeta}` WHERE meta_key = %s AND user_id BETWEEN %d AND %d", self::POINTER, 996500, 996799));
    }

    /** The user-meta functions over the real table (as LoginHandleRotationIntegrationTest does). */
    private static function defineUserMetaDoubles(): void
    {
        if (\function_exists('get_user_meta')) {
            return;
        }
        eval(<<<'PHP'
            function get_user_meta(int $userId, string $key = '', bool $single = false) {
                global $wpdb;
                $v = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id LIMIT 1", $userId, $key));
                return $single ? (string) ($v ?? '') : ($v === null ? [] : [$v]);
            }
            function update_user_meta(int $userId, string $key, $value): bool {
                global $wpdb;
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $userId, $key));
                return $wpdb->insert($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $key, 'meta_value' => (string) $value]) !== false;
            }
            function delete_user_meta(int $userId, string $key, $value = ''): bool {
                global $wpdb;
                return $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $userId, $key)) !== false;
            }
            PHP);
    }
}
