<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\REST\CardReviewsEndpoint;
use BCC\Trust\Core\REST\EntityAttestationsEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * §F-02 on a member's RECEIVED vouches and reviews, end to end through the
 * two REST handlers on real rows:
 *
 *   - GET /entities/user_profile/{id}/attestations  (EntityAttestationsEndpoint::list)
 *   - GET /entities/user_profile/{id}/reviews       (CardReviewsEndpoint::list)
 *
 * Both were public with no profile gate on the TARGET. F-356 gated each
 * attestor row; nothing asked whether the viewer may open the member the
 * roster is about, so an "only me" member's vouches and reviews were
 * readable by anyone. Now a profile the viewer may not open answers
 * ProfileAccess::forbidden() (403, `reason: profile_private`), as every
 * per-user route does. Card targets are pages and are not gated.
 *
 * Real attestation and vote tables, real ProfileAccess, the real
 * TrustReadService for the suspension half of the gate; PeepSo's ladder is
 * doubled per viewer (tests/Stubs/attestor-visibility-stubs.php).
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(EntityAttestationsEndpoint::class)]
#[CoversClass(CardReviewsEndpoint::class)]
final class ProfileTargetGateIntegrationTest extends TestCase
{
    private const PUBLIC_A  = 937011; // public profile (10)
    private const MEMBERS_B = 937012; // members-only (20): hidden from anonymous viewers
    private const ONLY_ME_C = 937013; // "only me" (40): hidden from all but themself and admins
    private const SIGNED_IN = 937020; // a signed-in viewer, no relation
    private const ADMIN     = 937021;

    private const TARGETS = [self::PUBLIC_A, self::MEMBERS_B, self::ONLY_ME_C];
    private const ALL     = [self::PUBLIC_A, self::MEMBERS_B, self::ONLY_ME_C, self::SIGNED_IN, self::ADMIN];

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
            $GLOBALS['__bcc_pg']['users'][$id] = ['login' => 'ptg_' . $id, 'handle' => 'ptg' . $id, 'name' => 'PTG ' . $id];
            $GLOBALS['__bcc_pg']['meta'][$id]  = ['bcc_handle' => 'ptg' . $id];
        }
        $GLOBALS['__bcc_test_caps'] = [];

        // The real TrustReadService answers the suspension half of the gate
        // (the harness's pass-through apply_filters would otherwise resolve
        // bcc-core's null service, which reports everyone as suspended).
        $cache    = new \ReflectionProperty(\BCC\Core\ServiceLocator::class, 'cache');
        $resolved = (array) $cache->getValue();
        $resolved['bcc.resolve.trust_read_service'] = \BCC\Trust\Core\Plugin::instance()->trustReadService();
        $cache->setValue(null, $resolved);

        $this->ensureAttestationsTable();
        $this->purge();
        // Every member target holds one vouch, from the public member, so
        // an allowed read carries a real row.
        foreach (self::TARGETS as $n => $target) {
            $this->vouch(self::PUBLIC_A === $target ? self::SIGNED_IN : self::PUBLIC_A, $target, $n + 1);
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

    public function testAnAnonymousVisitorReadsOnlyAPublicMembersVouchesAndReviews(): void
    {
        $this->actAs(0);

        $this->assertBoth(self::PUBLIC_A, 200);
        $this->assertBoth(self::MEMBERS_B, 403);
        $this->assertBoth(self::ONLY_ME_C, 403);
    }

    public function testASignedInMemberAlsoReadsAMembersOnlyProfile(): void
    {
        $this->actAs(self::SIGNED_IN);

        $this->assertBoth(self::MEMBERS_B, 200);
        $this->assertBoth(self::ONLY_ME_C, 403);
    }

    public function testAPrivateMemberReadsTheirOwn(): void
    {
        $this->actAs(self::ONLY_ME_C);

        $this->assertBoth(self::ONLY_ME_C, 200);
    }

    public function testAnAdminReadsEveryProfile(): void
    {
        $this->actAs(self::ADMIN, true);

        $this->assertBoth(self::ONLY_ME_C, 200);
    }

    public function testTheRefusalIsTheProfilePrivateShapeAndCarriesNoRows(): void
    {
        $this->actAs(0);

        foreach (['attestations', 'reviews'] as $route) {
            $body = $this->get($route, 'user_profile', self::ONLY_ME_C)->get_data();
            self::assertIsArray($body);
            self::assertSame('bcc_forbidden', $body['error']['code'] ?? null, $route);
            self::assertSame('profile_private', $body['error']['data']['reason'] ?? null, $route);
            self::assertArrayNotHasKey('data', $body, "{$route}: nothing about the member leaves the server");
            self::assertSame(
                'private, no-store',
                $this->get($route, 'user_profile', self::ONLY_ME_C)->get_headers()['Cache-Control'] ?? null,
                "{$route}: a refusal is never shared-cached"
            );
        }
    }

    public function testAnAllowedReadStillCarriesTheRoster(): void
    {
        $this->actAs(0);

        $body = $this->get('attestations', 'user_profile', self::PUBLIC_A)->get_data();

        self::assertIsArray($body);
        self::assertCount(1, $body['data']['items'] ?? []);
    }

    public function testACardTargetIsNotGatedByAMembersProfile(): void
    {
        // The same number as the private member, as a card (page) id.
        $this->actAs(0);

        foreach (['attestations', 'reviews'] as $route) {
            $r = $this->get($route, 'validator_card', self::ONLY_ME_C);
            self::assertSame(200, $r->get_status(), $route . ': ' . (string) json_encode($r->get_data()));
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function actAs(int $viewer, bool $admin = false): void
    {
        $GLOBALS['__bcc_pg']['viewer'] = $viewer;
        $GLOBALS['__bcc_test_caps']    = $admin ? ['manage_options'] : [];
    }

    private function assertBoth(int $target, int $status): void
    {
        foreach (['attestations', 'reviews'] as $route) {
            $r = $this->get($route, 'user_profile', $target);
            self::assertSame(
                $status,
                $r->get_status(),
                "{$route} for {$target} as viewer {$GLOBALS['__bcc_pg']['viewer']}: " . (string) json_encode($r->get_data())
            );
        }
    }

    private function get(string $route, string $kind, int $targetId): \WP_REST_Response
    {
        $request = new \WP_REST_Request(['target_kind' => $kind, 'target_id' => $targetId]);

        return $route === 'attestations'
            ? (new EntityAttestationsEndpoint())->list($request)
            : (new CardReviewsEndpoint())->list($request);
    }

    private function vouch(int $attestor, int $target, int $n): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(\BCC\Trust\Core\Database\TableRegistry::trustAttestations(), [
            'attestor_user_id'            => $attestor,
            'target_kind'                 => 'user_profile',
            'target_id'                   => $target,
            'kind'                        => 'vouch',
            'weight_at_time'              => 1.0,
            'attestation_order_in_target' => 1,
            'created_at'                  => sprintf('2026-11-17 00:%02d:00', $n),
        ]), $wpdb->last_error);
    }

    private function purge(): void
    {
        global $wpdb;
        $wpdb->query(
            'DELETE FROM `' . \BCC\Trust\Core\Database\TableRegistry::trustAttestations() . "` WHERE target_kind = 'user_profile' AND target_id IN ("
            . implode(',', array_map('intval', self::TARGETS)) . ')'
        );
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
