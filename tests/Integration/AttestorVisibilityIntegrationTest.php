<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Services\AttestationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The public attestation roster (GET /entities/:kind/:id/attestations →
 * AttestationService::getForTarget) omits attestors the VIEWER may not see,
 * by the profile gate (ProfileAccess::canViewProfile: F-02 profile ladder +
 * F-182 suspension, owner and admin exemptions).
 *
 * Before, the roster built every attestor that still existed, so an
 * anonymous visitor got the handle, name, avatar and score of a member
 * whose profile is private to signed-out viewers.
 *
 * Display only: the attestation rows stay, and `summary` (the vouch /
 * stand-behind totals) is unchanged. Real attestation rows, real
 * user_info, the real TrustReadService for the suspension check.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(AttestationService::class)]
final class AttestorVisibilityIntegrationTest extends TestCase
{
    private const OWNER      = 935001;
    private const PUBLIC_A   = 935011; // public profile (10)
    private const MEMBERS_B  = 935012; // members-only profile (20): hidden from anonymous viewers
    private const ONLY_ME_C  = 935013; // "only me" (40): hidden from everyone but themself and admins
    private const SIGNED_IN  = 935020; // a signed-in viewer, no relation
    private const ADMIN      = 935021;

    private const ALL = [self::OWNER, self::PUBLIC_A, self::MEMBERS_B, self::ONLY_ME_C, self::SIGNED_IN, self::ADMIN];

    /** @var list<string> */
    private array $createdTables = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/attestor-visibility-stubs.php';

        $GLOBALS['__bcc_pg'] = ['viewer' => 0, 'users' => [], 'meta' => [], 'profile_acc' => [
            self::MEMBERS_B => 20,
            self::ONLY_ME_C => 40,
        ]];
        foreach (self::ALL as $id) {
            $GLOBALS['__bcc_pg']['users'][$id] = ['login' => 'rav_' . $id, 'handle' => 'rav' . $id, 'name' => 'RAV ' . $id];
            $GLOBALS['__bcc_pg']['meta'][$id]  = ['bcc_handle' => 'rav' . $id];
        }
        $GLOBALS['__bcc_test_caps'] = [];

        // The real TrustReadService answers the suspension check (the
        // harness's pass-through apply_filters would otherwise resolve
        // bcc-core's null service, which reports everyone as suspended).
        $cache    = new \ReflectionProperty(\BCC\Core\ServiceLocator::class, 'cache');
        $resolved = (array) $cache->getValue();
        $resolved['bcc.resolve.trust_read_service'] = \BCC\Trust\Core\Plugin::instance()->trustReadService();
        $cache->setValue(null, $resolved);

        $this->ensureAttestationsTable();
        $this->purge();
        global $wpdb;
        foreach (self::ALL as $id) {
            // The vouch-line query joins wp_users (the attestor must exist).
            self::assertNotFalse($wpdb->insert($wpdb->users, ['ID' => $id, 'user_login' => 'rav_' . $id]), $wpdb->last_error);
        }
        foreach ([self::PUBLIC_A, self::MEMBERS_B, self::ONLY_ME_C] as $n => $attestor) {
            $this->vouch($attestor, $n + 1);
        }
    }

    protected function tearDown(): void
    {
        $this->purge();
        global $wpdb;
        foreach ($this->createdTables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
        unset($GLOBALS['__bcc_pg'], $GLOBALS['__bcc_test_caps']);
    }

    public function testAnAnonymousVisitorSeesOnlyPublicAttestors(): void
    {
        $this->actAs(0);

        self::assertSame([self::PUBLIC_A], $this->rosterIds());
    }

    public function testASignedInMemberAlsoSeesMembersOnlyAttestors(): void
    {
        $this->actAs(self::SIGNED_IN);

        self::assertSame([self::PUBLIC_A, self::MEMBERS_B], $this->rosterIds());
    }

    public function testThePrivateAttestorSeesThemselves(): void
    {
        $this->actAs(self::ONLY_ME_C);

        self::assertContains(self::ONLY_ME_C, $this->rosterIds());
    }

    public function testAnAdminSeesEveryAttestor(): void
    {
        $this->actAs(self::ADMIN, true);

        self::assertSame([self::PUBLIC_A, self::MEMBERS_B, self::ONLY_ME_C], $this->rosterIds());
    }

    public function testTheTotalsAreUnchangedThisIsDisplayOnly(): void
    {
        $this->actAs(0);
        $anon = $this->roster()['summary'];

        $this->actAs(self::ADMIN, true);
        $admin = $this->roster()['summary'];

        self::assertSame($admin, $anon, 'summary counts every vouch, whoever is looking');
        self::assertSame(3, $anon['vouch_count']);
    }

    // ── the profile vouch line's "Top vouched by" uses the same rule ────

    public function testTopVouchersNeverShowAnAttestorPrivateToTheAnonymousViewer(): void
    {
        // No score rows, so the newest vouch ranks first: ONLY_ME_C, then
        // MEMBERS_B, then PUBLIC_A. The two private ones are skipped and the
        // public one still makes the line.
        $this->actAs(0);

        $line = (new \BCC\Trust\Core\Services\ProfileVouchLineResolver(new \BCC\Trust\Core\Repositories\AttestationRepository()))
            ->forProfile(self::OWNER, 0);

        self::assertSame(['rav' . self::PUBLIC_A], array_column($line['top_vouchers'], 'handle'));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function actAs(int $viewer, bool $admin = false): void
    {
        $GLOBALS['__bcc_pg']['viewer'] = $viewer;
        $GLOBALS['__bcc_test_caps']    = $admin ? ['manage_options'] : [];
    }

    /** @return array{items: list<array<string, mixed>>, summary: array{vouch_count: int, stand_behind_count: int}} */
    private function roster(): array
    {
        /** @var array{items: list<array<string, mixed>>, summary: array{vouch_count: int, stand_behind_count: int}} $out */
        $out = \BCC\Trust\Core\Plugin::instance()->attestationService()->getForTarget('user_profile', self::OWNER, ['per_page' => 50]);

        return $out;
    }

    /** @return list<int> */
    private function rosterIds(): array
    {
        $ids = [];
        foreach ($this->roster()['items'] as $item) {
            $attestor = is_array($item['attestor'] ?? null) ? $item['attestor'] : [];
            $ids[]    = (int) ($attestor['id'] ?? 0);
        }
        sort($ids);

        return $ids;
    }

    private function vouch(int $attestor, int $n): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(\BCC\Trust\Core\Database\TableRegistry::trustAttestations(), [
            'attestor_user_id'            => $attestor,
            'target_kind'                 => 'user_profile',
            'target_id'                   => self::OWNER,
            'kind'                        => 'vouch',
            'weight_at_time'              => 1.0,
            'attestation_order_in_target' => $n,
            'created_at'                  => sprintf('2026-11-01 00:%02d:00', $n),
        ]), $wpdb->last_error);
    }

    private function purge(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `' . \BCC\Trust\Core\Database\TableRegistry::trustAttestations() . "` WHERE target_kind = 'user_profile' AND target_id = %d",
            self::OWNER
        ));
        $wpdb->query("DELETE FROM {$wpdb->users} WHERE ID IN (" . implode(',', array_map('intval', self::ALL)) . ')');
    }

    private function ensureAttestationsTable(): void
    {
        global $wpdb;
        $att = \BCC\Trust\Core\Database\TableRegistry::trustAttestations();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $att)) !== $att) {
            $this->createdTables[] = $att;
            require_once dirname(__DIR__, 2) . '/includes/database/schema-trust-attestations.php';
            \bcc_trust_create_trust_attestations_table();
        }
    }
}
