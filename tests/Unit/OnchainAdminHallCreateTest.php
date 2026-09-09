<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainsPage;
use BCC\Trust\Onchain\Services\HallProvisioningService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The request boundary for "Create Chain Hall".
 *
 * Hall creation publishes a public group, so the gates are the product: an
 * unauthorized caller, a forged or borrowed nonce, and a GET must all be
 * refused BEFORE the service is reached. Each case therefore asserts not only
 * the refusal but that `provisionOne()` was never called — a 403 that still
 * created a Hall would satisfy a weaker test.
 *
 * The per-chain nonce case is the one worth naming: the nonce is bound to the
 * chain id, so a nonce legitimately minted for chain 7 cannot be replayed to
 * create chain 8's Hall.
 */
#[CoversClass(ChainsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OnchainAdminHallCreateTest extends TestCase
{
    private const CHAIN_ID = 7;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../Stubs/onchain-admin-action-stubs.php';
        require_once __DIR__ . '/../Stubs/hall-admin-action-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\HallRepository::reset();
        \BCC\Trust\Onchain\Services\HallProvisioningService::reset();
        \BCC\Trust\Onchain\OnchainPlugin::reset();

        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN_ID, 'cosmos');

        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    private function seedPost(int $chainId = self::CHAIN_ID): void
    {
        $_POST = ['action' => ChainsPage::ACTION_HALL_CREATE, 'chain_id' => (string) $chainId];
    }

    private function armNonceFor(int $chainId): void
    {
        \BccAdminTestState::$validNonceAction = ChainsPage::ACTION_HALL_CREATE . '_' . $chainId;
    }

    // ── Registration ─────────────────────────────────────────────────────

    public function testRegistersTheHallCreateHandlerOnAdminPost(): void
    {
        $GLOBALS['bcc_test_registered_actions'] = [];

        ChainsPage::register_actions();

        self::assertContains(
            'admin_post_' . ChainsPage::ACTION_HALL_CREATE,
            $GLOBALS['bcc_test_registered_actions']
        );
    }

    public function testThereIsNoNoPrivHallRoute(): void
    {
        $GLOBALS['bcc_test_registered_actions'] = [];

        ChainsPage::register_actions();

        foreach ($GLOBALS['bcc_test_registered_actions'] as $action) {
            self::assertStringNotContainsString(
                'admin_post_nopriv_',
                (string) $action,
                'Hall creation must never be reachable by a logged-out caller'
            );
        }
    }

    // ── Authorization ────────────────────────────────────────────────────

    public function testUnauthorizedCallerIsHaltedAndCreatesNothing(): void
    {
        \BccAdminTestState::$can = false;
        $this->seedPost();
        $this->armNonceFor(self::CHAIN_ID);

        try {
            ChainsPage::handle_hall_create();
            self::fail('Expected a 403 halt.');
        } catch (\BccAdminDie $e) {
            self::assertSame(403, $e->status);
        }

        self::assertSame([], \BCC\Trust\Onchain\Services\HallProvisioningService::$calls);
    }

    // ── Method ───────────────────────────────────────────────────────────

    /** @return list<list<string>> */
    public static function nonPostMethodProvider(): array
    {
        return [['GET'], ['HEAD'], ['PUT'], ['DELETE']];
    }

    #[DataProvider('nonPostMethodProvider')]
    public function testNonPostRequestCannotCreateAHall(string $method): void
    {
        // admin-post.php dispatches admin_post_{action} out of $_REQUEST, so
        // without the method gate a crafted GET reaches the handler.
        $_SERVER['REQUEST_METHOD'] = $method;
        $_REQUEST = ['action' => ChainsPage::ACTION_HALL_CREATE, 'chain_id' => (string) self::CHAIN_ID];
        $this->seedPost();
        $this->armNonceFor(self::CHAIN_ID);

        try {
            ChainsPage::handle_hall_create();
            self::fail('Expected a 405 halt for ' . $method);
        } catch (\BccAdminDie $e) {
            self::assertSame(405, $e->status);
        }

        self::assertSame([], \BCC\Trust\Onchain\Services\HallProvisioningService::$calls);
    }

    public function testCapabilityIsCheckedBeforeTheMethod(): void
    {
        // Order matters: an unauthorized caller learns nothing about which
        // methods the route accepts.
        \BccAdminTestState::$can = false;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->seedPost();

        try {
            ChainsPage::handle_hall_create();
            self::fail('Expected a halt.');
        } catch (\BccAdminDie $e) {
            self::assertSame(403, $e->status);
        }
    }

    // ── CSRF ─────────────────────────────────────────────────────────────

    public function testMissingNonceIsRejected(): void
    {
        $this->seedPost();
        // No valid nonce armed.

        try {
            ChainsPage::handle_hall_create();
            self::fail('Expected a nonce rejection.');
        } catch (\BccAdminDie $e) {
            self::assertSame(403, $e->status);
        }

        self::assertSame([], \BCC\Trust\Onchain\Services\HallProvisioningService::$calls);
    }

    public function testANonceForAnotherChainCannotCreateThisChainsHall(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(8, 'osmosis');

        $this->seedPost(8);
        // A nonce legitimately minted for chain 7.
        $this->armNonceFor(self::CHAIN_ID);

        try {
            ChainsPage::handle_hall_create();
            self::fail('Expected the cross-chain nonce to be rejected.');
        } catch (\BccAdminDie $e) {
            self::assertSame(403, $e->status);
        }

        self::assertSame([], \BCC\Trust\Onchain\Services\HallProvisioningService::$calls);
    }

    public function testTheNonceIsScopedToTheChainId(): void
    {
        $this->seedPost();
        $this->armNonceFor(self::CHAIN_ID);

        try {
            ChainsPage::handle_hall_create();
        } catch (\BccAdminRedirect $r) {
            // expected
        }

        self::assertSame(
            [['action' => ChainsPage::ACTION_HALL_CREATE . '_' . self::CHAIN_ID, 'arg' => '_wpnonce']],
            \BccAdminTestState::$nonceChecks
        );
    }

    // ── Validation runs before the nonce ─────────────────────────────────

    public function testAMissingChainIdIsRefusedBeforeANonceIsEvenChecked(): void
    {
        $_POST = ['action' => ChainsPage::ACTION_HALL_CREATE];

        try {
            ChainsPage::handle_hall_create();
            self::fail('Expected PRG.');
        } catch (\BccAdminRedirect $r) {
            self::assertSame('invalid_chain', $r->args['bcc_hall']);
            self::assertSame('halls', $r->args['subtab']);
        }

        self::assertSame([], \BccAdminTestState::$nonceChecks, 'no nonce action is built from junk input');
        self::assertSame([], \BCC\Trust\Onchain\Services\HallProvisioningService::$calls);
    }

    /**
     * Values that cast to a non-positive int, so they are refused before any
     * nonce action is built.
     *
     * ⚠ `'1e3'` is deliberately NOT here: `(int) '1e3'` is 1000, a perfectly
     * well-formed id. It is refused one gate later, by the repository lookup —
     * see testAChainThatDoesNotResolveIsRefused. Listing it here would have
     * asserted the right outcome for the wrong reason.
     *
     * @return list<list<string>>
     */
    public static function malformedChainIdProvider(): array
    {
        return [['0'], ['-4'], ['abc'], ['']];
    }

    #[DataProvider('malformedChainIdProvider')]
    public function testMalformedChainIdIsRefused(string $raw): void
    {
        $_POST = ['action' => ChainsPage::ACTION_HALL_CREATE, 'chain_id' => $raw];

        try {
            ChainsPage::handle_hall_create();
            self::fail('Expected PRG for chain_id=' . var_export($raw, true));
        } catch (\BccAdminRedirect $r) {
            self::assertSame('invalid_chain', $r->args['bcc_hall']);
        }

        self::assertSame([], \BCC\Trust\Onchain\Services\HallProvisioningService::$calls);
    }

    public function testAChainThatDoesNotResolveIsRefused(): void
    {
        // A positive integer is not by itself a valid target.
        $this->seedPost(4242);
        $this->armNonceFor(4242);

        try {
            ChainsPage::handle_hall_create();
            self::fail('Expected PRG.');
        } catch (\BccAdminRedirect $r) {
            self::assertSame('invalid_chain', $r->args['bcc_hall']);
        }

        self::assertSame([], \BCC\Trust\Onchain\Services\HallProvisioningService::$calls);
    }

    // ── The happy path, and PRG ──────────────────────────────────────────

    public function testAValidRequestCreatesExactlyOneHallAndRedirects(): void
    {
        $this->seedPost();
        $this->armNonceFor(self::CHAIN_ID);

        try {
            ChainsPage::handle_hall_create();
            self::fail('Expected a PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            self::assertSame('created', $r->args['bcc_hall']);
            self::assertSame('halls', $r->args['subtab']);
            self::assertSame((string) self::CHAIN_ID, (string) $r->args['bcc_chain']);
        }

        self::assertSame([self::CHAIN_ID], \BCC\Trust\Onchain\Services\HallProvisioningService::$calls);
    }

    /**
     * A refresh re-issues the redirect target as a GET. The mutation is not in
     * it, so nothing is replayed — that is the whole point of PRG.
     */
    public function testTheRedirectTargetCarriesNoMutation(): void
    {
        $this->seedPost();
        $this->armNonceFor(self::CHAIN_ID);

        try {
            ChainsPage::handle_hall_create();
        } catch (\BccAdminRedirect $r) {
            self::assertArrayNotHasKey('action', $r->args);
            self::assertArrayNotHasKey('_wpnonce', $r->args);
            self::assertArrayNotHasKey('chain_id', $r->args);
        }
    }

    /** @return list<array{0: string, 1: string}> */
    public static function serviceStatusProvider(): array
    {
        return [
            'created'    => ['created', 'created'],
            'exists'     => ['exists', 'exists'],
            'incomplete' => ['incomplete', 'incomplete'],
            'skipped'    => ['skipped', 'refused'],
            'error'      => ['error', 'failed'],
        ];
    }

    #[DataProvider('serviceStatusProvider')]
    public function testEveryServiceStatusMapsToItsOwnResultKey(string $status, string $expected): void
    {
        \BCC\Trust\Onchain\Services\HallProvisioningService::$result['status'] = $status;

        $this->seedPost();
        $this->armNonceFor(self::CHAIN_ID);

        try {
            ChainsPage::handle_hall_create();
            self::fail('Expected PRG.');
        } catch (\BccAdminRedirect $r) {
            self::assertSame($expected, $r->args['bcc_hall']);
        }
    }

    /**
     * `incomplete` must never be laundered into a success notice — a public
     * Hall with unresolved ownership is not a completed provision.
     */
    public function testIncompleteIsNotReportedAsCreated(): void
    {
        \BCC\Trust\Onchain\Services\HallProvisioningService::$result['status'] = 'incomplete';

        $this->seedPost();
        $this->armNonceFor(self::CHAIN_ID);

        try {
            ChainsPage::handle_hall_create();
        } catch (\BccAdminRedirect $r) {
            self::assertNotSame('created', $r->args['bcc_hall']);
            self::assertSame('incomplete', $r->args['bcc_hall']);
        }
    }

    // ── Exceptions are bounded ───────────────────────────────────────────

    public function testAThrownExceptionNeverReachesTheRedirectUrl(): void
    {
        \BCC\Trust\Onchain\Services\HallProvisioningService::$throws = new \RuntimeException(
            "SELECT * FROM wp_posts WHERE x=1 -- /var/www/secret/path.php https://user:pw@rpc.example/key"
        );

        $this->seedPost();
        $this->armNonceFor(self::CHAIN_ID);

        try {
            ChainsPage::handle_hall_create();
            self::fail('Expected PRG.');
        } catch (\BccAdminRedirect $r) {
            self::assertSame('exception', $r->args['bcc_hall']);

            $url = $r->url;
            self::assertStringNotContainsString('SELECT', $url);
            self::assertStringNotContainsString('wp_posts', $url);
            self::assertStringNotContainsString('/var/www', $url);
            self::assertStringNotContainsString('rpc.example', $url);
            self::assertStringNotContainsString('pw@', $url);

            // Only a short, non-secret correlation id travels.
            self::assertMatchesRegularExpression('/^bcc-[0-9a-f]{8}$/', (string) $r->args['bcc_ref']);
        }
    }

    public function testAThrownExceptionIsDurablyRecorded(): void
    {
        \BCC\Trust\Onchain\Services\HallProvisioningService::$throws = new \RuntimeException('boom');

        $this->seedPost();
        $this->armNonceFor(self::CHAIN_ID);

        try {
            ChainsPage::handle_hall_create();
        } catch (\BccAdminRedirect $r) {
            // expected
        }

        self::assertContains(
            HallProvisioningService::AUDIT_FAILED,
            \BCC\Trust\Core\Security\AuditLogger::actions()
        );
    }

    // ── The audit action names fit the column ────────────────────────────

    public function testHallAuditActionsFitTheActionColumn(): void
    {
        foreach ([
            HallProvisioningService::AUDIT_CREATED,
            HallProvisioningService::AUDIT_FAILED,
            HallProvisioningService::AUDIT_INCOMPLETE,
            HallProvisioningService::AUDIT_COMPENSATED,
        ] as $action) {
            self::assertLessThanOrEqual(50, strlen($action), $action);
        }
    }
}
