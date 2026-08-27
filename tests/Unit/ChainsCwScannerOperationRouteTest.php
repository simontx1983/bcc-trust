<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
use BCC\Trust\Onchain\Services\NftDiscoveryControlPlaneSnapshot;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * VC-B3b TRANSPORT contract for Pause, Resume, Backfill and Retry.
 *
 * ── WHAT THESE FOUR USED TO BE ──────────────────────────────────────────
 * Branches inside the Verify Collections dispatcher, executed during
 * `render_page()`, reached after ONE page-wide nonce that also covered a
 * hard delete and a bulk save. There was no PRG, so a browser refresh
 * re-ran them — including Backfill, the one control here that spends
 * provider budget. None wrote a durable audit row.
 *
 * ── WHAT THIS FILE OWNS ─────────────────────────────────────────────────
 * The request boundary only: capability, method, the shape of the chain
 * id, the direction-and-chain-scoped nonce, the PRG destination, and the
 * proof that a refused request reaches no repository, no worker and no
 * audit. The DOMAIN half — what each operation does once it is allowed to
 * run — is ChainsCwScannerOperationDomainTest.
 *
 * ── THE ORDER IS ITSELF THE CONTRACT ────────────────────────────────────
 * capability → POST-only → chain-id shape → scoped nonce → lookup. Each
 * gate is proven with every LATER gate satisfied, so a test cannot pass
 * because two checks happened to fail at once.
 */
#[CoversClass(NftDiscoveryPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChainsCwScannerOperationRouteTest extends TestCase
{
    private const CHAIN_ID       = 4;
    private const OTHER_CHAIN_ID = 9;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/chains-cw-operations-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        ChainRepository::reset();
        ChainCheckpointRepository::reset();
        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        CosmwasmDiscoveryWorker::reset();
        CosmwasmTickBudget::reset();
        CosmwasmDiscoveryGate::reset();

        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ChainRepository::seed(self::CHAIN_ID, 'cosmos', true);
        ChainRepository::seed(self::OTHER_CHAIN_ID, 'juno', true);
        ChainCheckpointRepository::seed(self::CHAIN_ID);
        ChainCheckpointRepository::seed(self::OTHER_CHAIN_ID);
    }

    /** @return array<string, array{0: string}> */
    public static function routes(): array
    {
        return [
            'pause'    => [NftDiscoveryPage::ACTION_CW_PAUSE],
            'resume'   => [NftDiscoveryPage::ACTION_CW_RESUME],
            'backfill' => [NftDiscoveryPage::ACTION_CW_BACKFILL],
            'retry'    => [NftDiscoveryPage::ACTION_CW_RETRY],
        ];
    }

    private static function handler(string $route): string
    {
        return [
            NftDiscoveryPage::ACTION_CW_PAUSE    => 'handle_cw_pause',
            NftDiscoveryPage::ACTION_CW_RESUME   => 'handle_cw_resume',
            NftDiscoveryPage::ACTION_CW_BACKFILL => 'handle_cw_backfill',
            NftDiscoveryPage::ACTION_CW_RETRY    => 'handle_cw_retry',
        ][$route];
    }

    /** A well-formed request for one route. */
    private function arrange(string $route, int $chainId = self::CHAIN_ID): void
    {
        $_POST['chain_id'] = $chainId;
        \BccAdminTestState::$validNonceAction = $route . '_' . $chainId;
    }

    private function call(string $route): void
    {
        NftDiscoveryPage::{self::handler($route)}();
    }

    private function invoke(string $route): \BccAdminRedirect
    {
        try {
            $this->call($route);
        } catch (\BccAdminRedirect $r) {
            return $r;
        }

        $this->fail('The handler must terminate in a PRG redirect.');
    }

    /**
     * Nothing reached a repository, the worker, a provider budget or the
     * audit log. Asserted as one block so every refusal test makes the
     * same complete claim.
     */
    private function assertNothingHappened(string $why): void
    {
        $this->assertSame(0, ChainCheckpointRepository::$pauseCalls, $why . ' — no pause write');
        $this->assertSame(0, ChainCheckpointRepository::$resumeCalls, $why . ' — no resume write');
        $this->assertSame([], CosmwasmCodeFamilyRepository::$retryCalls, $why . ' — no family requeue');
        $this->assertSame([], CosmwasmContractRepository::$retryCalls, $why . ' — no contract requeue');
        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes, $why . ' — no scanner work');
        $this->assertSame([], CosmwasmTickBudget::$constructions, $why . ' — no provider budget taken');
        $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions(), $why . ' — no durable row');
    }

    // ── 1. Capability ───────────────────────────────────────────────────

    #[DataProvider('routes')]
    public function testMissingCapabilityIsRefusedWith403(string $route): void
    {
        $this->arrange($route);
        \BccAdminTestState::$can = false;

        try {
            $this->call($route);
            $this->fail('A request without manage_options must be refused.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertNothingHappened('missing capability');
    }

    /**
     * The refusal trace carries NOTHING the caller sent.
     *
     * `chain_id` is attacker-controlled and, at this point, unvalidated:
     * echoing it into the log would let an unauthenticated caller write
     * our log and probe which chain ids exist. The operation name comes
     * from the REGISTERED HANDLER, not the request.
     */
    #[DataProvider('routes')]
    public function testTheRefusalTraceCarriesNoRequestInput(string $route): void
    {
        $_POST['chain_id'] = "1 OR 1=1\ninjected=yes";
        $_POST['action']   = 'spoofed';
        \BccAdminTestState::$can = false;

        try {
            $this->call($route);
        } catch (\BccAdminDie) {
            // expected
        }

        $encoded = json_encode(\BCC\Core\Log\Logger::$entries) ?: '';

        $this->assertStringNotContainsString('OR 1=1', $encoded);
        $this->assertStringNotContainsString('injected', $encoded);
        $this->assertStringNotContainsString('spoofed', $encoded);

        // What it DOES carry: a fixed event name and the operation derived
        // from the route, so the trace is still useful.
        $this->assertStringContainsString('cosmwasm_scanner_operation_denied', $encoded);
    }

    // ── 2. Method ───────────────────────────────────────────────────────

    #[DataProvider('routes')]
    public function testAGetRequestIsRefusedWith405(string $route): void
    {
        $this->arrange($route);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $this->call($route);
            $this->fail('admin-post.php dispatches GET too — the handler must refuse it.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(405, $e->status);
        }

        $this->assertNothingHappened('GET request');
    }

    // ── 3. Chain-id shape — a REAL 400, not a redirect wearing one ──────

    /** @return array<string, array{0: mixed}> */
    public static function malformedChainIds(): array
    {
        return [
            'missing'      => [null],
            'empty'        => [''],
            'zero'         => ['0'],
            'negative'     => ['-4'],
            'leading zero' => ['04'],
            'decimal'      => ['4.0'],
            'hexadecimal'  => ['0x04'],
            'whitespace'   => [' 4 '],
            'oversized'    => ['99999999999999999999'],
            'array'        => [['4']],
            'log injection' => ["4\nchain_id=9"],
            'sql-ish'      => ['4 OR 1=1'],
        ];
    }

    #[DataProvider('malformedChainIds')]
    public function testAMalformedChainIdIsRefusedWith400(mixed $chainId): void
    {
        if ($chainId !== null) {
            $_POST['chain_id'] = $chainId;
        }
        \BccAdminTestState::$validNonceAction = NftDiscoveryPage::ACTION_CW_PAUSE . '_' . self::CHAIN_ID;

        try {
            NftDiscoveryPage::handle_cw_pause();
            $this->fail('A malformed chain id must be refused with a real 400.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(400, $e->status, 'a 302 carrying a notice is not a rejection');
        }

        $this->assertNothingHappened('malformed chain id');
    }

    /**
     * ORDER: authorization is decided BEFORE the request is parsed.
     *
     * Both are wrong here. If the shape check ran first the answer would
     * be 400, which tells an unauthenticated caller their input was the
     * only problem — and that the endpoint exists.
     */
    public function testCapabilityIsCheckedBeforeMalformedInputIsProcessed(): void
    {
        $_POST['chain_id'] = 'not-a-chain';
        \BccAdminTestState::$can = false;

        try {
            NftDiscoveryPage::handle_cw_pause();
            $this->fail('expected a refusal');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status, 'authorization outranks input validation');
        }
    }

    /** And the method check outranks it too, for the same reason. */
    public function testMethodIsCheckedBeforeMalformedInputIsProcessed(): void
    {
        $_POST['chain_id'] = 'not-a-chain';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            NftDiscoveryPage::handle_cw_pause();
            $this->fail('expected a refusal');
        } catch (\BccAdminDie $e) {
            $this->assertSame(405, $e->status);
        }
    }

    // ── 4. Nonce — bound to the operation AND the chain ─────────────────

    #[DataProvider('routes')]
    public function testAMissingOrWrongNonceIsRefusedWith403(string $route): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction = 'something-else';

        try {
            $this->call($route);
            $this->fail('expected a nonce refusal');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertNothingHappened('bad nonce');
    }

    /**
     * A nonce minted for one operation cannot drive another — which is the
     * whole reason these are four routes and not one `op=` parameter.
     * Pause and Resume are the pair that matters most: they are opposites.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function crossRoutePairs(): array
    {
        return [
            'pause nonce on resume'     => [NftDiscoveryPage::ACTION_CW_PAUSE, NftDiscoveryPage::ACTION_CW_RESUME],
            'resume nonce on pause'     => [NftDiscoveryPage::ACTION_CW_RESUME, NftDiscoveryPage::ACTION_CW_PAUSE],
            'retry nonce on backfill'   => [NftDiscoveryPage::ACTION_CW_RETRY, NftDiscoveryPage::ACTION_CW_BACKFILL],
            'backfill nonce on retry'   => [NftDiscoveryPage::ACTION_CW_BACKFILL, NftDiscoveryPage::ACTION_CW_RETRY],
            'pause nonce on backfill'   => [NftDiscoveryPage::ACTION_CW_PAUSE, NftDiscoveryPage::ACTION_CW_BACKFILL],
            'discovery nonce on pause'  => [NftDiscoveryPage::ACTION_CW_DISCOVERY_ENABLE, NftDiscoveryPage::ACTION_CW_PAUSE],
        ];
    }

    #[DataProvider('crossRoutePairs')]
    public function testANonceForOneOperationCannotDriveAnother(string $minted, string $used): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction = $minted . '_' . self::CHAIN_ID;

        try {
            $this->call($used);
            $this->fail("a {$minted} nonce must not authorise {$used}");
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertNothingHappened('cross-operation nonce');
    }

    #[DataProvider('routes')]
    public function testANonceForOneChainCannotDriveAnother(string $route): void
    {
        $_POST['chain_id'] = self::OTHER_CHAIN_ID;
        \BccAdminTestState::$validNonceAction = $route . '_' . self::CHAIN_ID;

        try {
            $this->call($route);
            $this->fail('a nonce scoped to chain 4 must not authorise chain 9');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertNothingHappened('cross-chain nonce');
    }

    /**
     * The old page-wide token is refused. It is named here — and only
     * here, in tests — because that is the entire point: VC-B3b removed
     * the last thing that verified it.
     */
    #[DataProvider('routes')]
    public function testTheLegacySharedNonceIsRejected(string $route): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction = 'bcc_verify_collections_nonce';

        try {
            $this->call($route);
            $this->fail('the retired page-wide nonce must authorise nothing');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertNothingHappened('retired shared nonce');
    }

    // ── 5. Unknown chain ────────────────────────────────────────────────

    #[DataProvider('routes')]
    public function testAnUnknownChainIsRefusedBeforeAnyWork(string $route): void
    {
        ChainRepository::reset();
        $this->arrange($route);

        $redirect = $this->invoke($route);

        $this->assertSame('unknown_chain', $redirect->args['bcc_cwo'] ?? null);
        $this->assertNothingHappened('unknown chain');
    }

    // ── 6. PRG destination ──────────────────────────────────────────────

    #[DataProvider('routes')]
    public function testTheRedirectCarriesOnlyTheAllowlistedKeys(string $route): void
    {
        $this->arrange($route);

        $redirect = $this->invoke($route);

        $this->assertSame(
            [],
            array_diff(array_keys($redirect->args), NftDiscoveryPage::OPERATION_REDIRECT_KEYS),
            'the destination must carry no key outside the declared allowlist'
        );
        $this->assertSame(NftDiscoveryPage::PAGE_SLUG, $redirect->args['page'] ?? null);

        // The destination used to be a sub-tab of the Chains page and is now
        // a family tab on the page that owns these routes. The KEY changed
        // with it; the discipline did not — a bounded, fixed value, and
        // still no chain id, direction or submitted value anywhere near it.
        $this->assertSame(
            NftDiscoveryControlPlaneSnapshot::FAMILY_COSMOS,
            $redirect->args['family'] ?? null
        );
        $this->assertArrayNotHasKey('subtab', $redirect->args);
    }

    /**
     * And no VALUE leaks either. A key allowlist stops `chain_id=4`; it
     * does nothing about `bcc_cwo=paused_chain_4`, which would put the
     * target back in the URL by another name.
     */
    #[DataProvider('routes')]
    public function testTheRedirectNamesNoChainActionOrNonce(string $route): void
    {
        $this->arrange($route);

        $args = $this->invoke($route)->args;

        // ── `family` IS A CONSTANT, AND IT COLLIDES WITH A CHAIN SLUG ───
        //
        // The destination carries `family=cosmos`. The Cosmos Hub's slug is
        // also `cosmos`, so a naive substring search for the slug matches
        // the navigation value and reports a leak that is not one.
        //
        // It is not one because the value is FIXED: every one of these four
        // routes redirects to the same family regardless of which chain was
        // acted on, so it distinguishes nothing. Asserted as an exact
        // identity first — which is the stronger claim, since a `family`
        // that ever varied with the target WOULD be a leak and would fail
        // here — and then removed before the substring sweep, so the sweep
        // keeps meaning what it was written to mean.
        $this->assertSame(
            NftDiscoveryControlPlaneSnapshot::FAMILY_COSMOS,
            $args['family'] ?? null,
            'family must be a fixed constant, never derived from the target chain'
        );
        unset($args['family']);

        $encoded = json_encode($args) ?: '';

        $this->assertStringNotContainsString((string) self::CHAIN_ID, $encoded, 'no chain id under any key');
        $this->assertStringNotContainsString($route, $encoded, 'no route name');
        $this->assertStringNotContainsString('nonce', $encoded);
        $this->assertStringNotContainsString('cosmos', $encoded, 'no chain slug either');
        $this->assertStringNotContainsString('juno', $encoded, 'nor any other chain slug');
    }

    /**
     * PRG is what stops a refresh replaying the operation. The old
     * dispatcher ran inside render_page(), so F5 re-ran a backfill slice.
     */
    #[DataProvider('routes')]
    public function testTheHandlerAlwaysTerminatesInARedirect(string $route): void
    {
        $this->arrange($route);

        $redirect = $this->invoke($route);

        $this->assertNotSame('', (string) ($redirect->args['bcc_cwo'] ?? ''));
    }

    /**
     * Replaying the REDIRECT — which is what a refresh actually repeats —
     * performs no work at all. It is a GET of an inert page.
     */
    public function testReplayingTheRedirectDestinationDoesNothing(): void
    {
        $this->arrange(NftDiscoveryPage::ACTION_CW_PAUSE);
        $redirect = $this->invoke(NftDiscoveryPage::ACTION_CW_PAUSE);

        $pausesAfterFirst = ChainCheckpointRepository::$pauseCalls;
        $this->assertSame(1, $pausesAfterFirst);

        // The browser now GETs the destination. No POST, no action.
        $_GET  = $redirect->args;
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $notice = new \ReflectionMethod(NftDiscoveryPage::class, 'cw_operation_notice_from_query');
        $notice->setAccessible(true);
        $notice->invoke(null);

        $this->assertSame(1, ChainCheckpointRepository::$pauseCalls, 'the refresh must not re-pause');
    }

    // ── 7. Exceptions are correlated, never echoed ──────────────────────

    public function testAnExceptionIsRedactedAndCorrelated(): void
    {
        $this->arrange(NftDiscoveryPage::ACTION_CW_PAUSE);
        ChainCheckpointRepository::$pauseThrows = new \RuntimeException(
            'SQLSTATE[HY000]: SELECT * FROM wp_bcc_chain_checkpoints at C:\\Users\\deploy\\wp-config.php'
        );

        $redirect = $this->invoke(NftDiscoveryPage::ACTION_CW_PAUSE);

        $encoded = json_encode($redirect->args) ?: '';
        $this->assertStringNotContainsString('SELECT', $encoded);
        $this->assertStringNotContainsString('wp_bcc_chain_checkpoints', $encoded);
        $this->assertStringNotContainsString('wp-config', $encoded);

        // A correlation id instead, so the operator and the log can be
        // joined without the operator being shown the exception.
        $this->assertSame('error', $redirect->args['bcc_cwo'] ?? null);
        $this->assertMatchesRegularExpression('/^bcc-[0-9a-f]{8}$/', (string) ($redirect->args['bcc_ref'] ?? ''));
    }

    public function testAnExceptionWritesExactlyOneErrorAuditRow(): void
    {
        $this->arrange(NftDiscoveryPage::ACTION_CW_PAUSE);
        ChainCheckpointRepository::$pauseThrows = new \RuntimeException('boom');

        $this->invoke(NftDiscoveryPage::ACTION_CW_PAUSE);

        $this->assertSame(
            ['admin_chain_cw_pause_error'],
            \BCC\Trust\Core\Security\AuditLogger::actions()
        );
    }

    // ── 8. Registration ─────────────────────────────────────────────────

    #[DataProvider('routes')]
    public function testEachRouteIsRegisteredAsItsOwnAdminPostAction(string $route): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../app/Domain/Onchain/Admin/NftDiscoveryPage.php'
        );

        $this->assertStringContainsString("add_action('admin_post_' . self::", $source);
        $this->assertStringContainsString($route, $source);
        $this->assertTrue(method_exists(NftDiscoveryPage::class, self::handler($route)));
    }
}
