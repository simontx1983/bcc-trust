<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainsPage;
use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * VC-B2 TRANSPORT contract for the CosmWasm/CW-721 discovery opt-in.
 *
 * Before this batch both directions were branches inside the Verify
 * Collections dispatcher, reached after ONE page-wide nonce shared with a
 * provider-consuming backfill — so a nonce minted to opt a chain in also
 * authorised a scanner slice on any other chain. There was no PRG, so a
 * refresh re-applied the write.
 *
 * The DOMAIN half — the surgical single-column write, the cache bust, the
 * read-back, the no-op — is pinned by CosmwasmChainDiscoveryAdminTest
 * against the CosmWasm stub family. This file owns the request boundary.
 */
#[CoversClass(ChainsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChainsCwDiscoveryRouteTest extends TestCase
{
    private const CHAIN_ID       = 4;
    private const OTHER_CHAIN_ID = 9;
    private const SLUG           = 'cosmos';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/chains-cw-discovery-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        ChainRepository::reset();
        CosmwasmDiscoveryWorker::reset();
        CosmwasmDiscoveryHealthSnapshot::reset();

        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Ships DISABLED, the production default.
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, false);
        ChainRepository::seed(self::OTHER_CHAIN_ID, 'juno', false);
    }

    /** @return array<string, array{0: bool}> */
    public static function directions(): array
    {
        return ['enable' => [true], 'disable' => [false]];
    }

    private static function route(bool $enable): string
    {
        return $enable
            ? ChainsPage::ACTION_CW_DISCOVERY_ENABLE
            : ChainsPage::ACTION_CW_DISCOVERY_DISABLE;
    }

    /** Arrange a well-formed request for one direction. */
    private function arrange(bool $enable, int $chainId = self::CHAIN_ID): void
    {
        $_POST['chain_id'] = $chainId;
        \BccAdminTestState::$validNonceAction = self::route($enable) . '_' . $chainId;
    }

    private function invoke(bool $enable): \BccAdminRedirect
    {
        try {
            $enable
                ? ChainsPage::handle_cw_discovery_enable()
                : ChainsPage::handle_cw_discovery_disable();
        } catch (\BccAdminRedirect $r) {
            return $r;
        }

        $this->fail('The handler must terminate in a PRG redirect.');
    }

    private function assertNothingHappened(string $why): void
    {
        $this->assertSame([], ChainRepository::$discoveryWrites, $why . ' — no settings write');
        $this->assertSame(0, ChainRepository::$cacheBusts, $why . ' — no cache bust');
        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes, $why . ' — no scanner work');
        $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions(), $why . ' — no durable row');
    }

    // ── 1–4. The gates ──────────────────────────────────────────────────

    #[DataProvider('directions')]
    public function testMissingCapabilityIsRefusedInsideTheHandler(bool $enable): void
    {
        $this->arrange($enable);
        \BccAdminTestState::$can = false;

        try {
            $enable ? ChainsPage::handle_cw_discovery_enable() : ChainsPage::handle_cw_discovery_disable();
            $this->fail('A request without manage_options must be refused.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertNothingHappened('missing capability');
    }

    #[DataProvider('directions')]
    public function testGetIsRefusedWith405(bool $enable): void
    {
        $this->arrange($enable);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $enable ? ChainsPage::handle_cw_discovery_enable() : ChainsPage::handle_cw_discovery_disable();
            $this->fail('admin-post.php dispatches GET too; the handler must refuse it.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(405, $e->status);
        }

        $this->assertNothingHappened('GET request');
    }

    /**
     * Hostile shapes, not just wrong numbers.
     *
     * The array case matters most: `(int) []` is 1 in PHP AND raises a
     * warning, so a naive cast turns pure garbage into a valid-looking
     * chain id. The CR/LF case is log-injection — a value that, if echoed
     * into a log line, forges a second line of its own.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function badIds(): array
    {
        return [
            'zero'           => [0],
            'negative'       => [-5],
            'empty'          => [''],
            'garbage'        => ['not-a-number'],
            'array'          => [['4', '9']],
            'very long'      => [str_repeat('9', 4096)],
            'crlf injection' => ["4\r\n[bcc-trust] FORGED admin action: chain 999 enabled"],
            'sql-looking'    => ["4; DROP TABLE wp_bcc_chains; --"],
            'float'          => ['4.7'],
            'hex'            => ['0x04'],
            'leading zero'   => ['007'],
            'whitespace'     => [' 4 '],
        ];
    }

    #[DataProvider('badIds')]
    public function testMalformedChainIdIsRefusedWithARealBadRequest(mixed $id): void
    {
        $_POST['chain_id'] = $id;
        // A nonce that WOULD be valid for chain 4, to prove the shape gate
        // fires before the nonce and is not merely a nonce failure.
        \BccAdminTestState::$validNonceAction =
            ChainsPage::ACTION_CW_DISCOVERY_ENABLE . '_' . self::CHAIN_ID;

        try {
            ChainsPage::handle_cw_discovery_enable();
            $this->fail('A malformed chain id must terminate the request.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(400, $e->status, 'a malformed request gets a real 400, not a redirect');
            $this->assertStringNotContainsString('DROP TABLE', $e->getMessage());
            $this->assertStringNotContainsString('FORGED', $e->getMessage());
        } catch (\BccAdminRedirect $r) {
            $this->fail('A malformed chain id must not redirect — there is no legitimate destination.');
        }

        $this->assertNothingHappened('malformed chain id');
    }

    /** A missing key is malformed too. */
    public function testAMissingChainIdIsARealBadRequest(): void
    {
        \BccAdminTestState::$validNonceAction =
            ChainsPage::ACTION_CW_DISCOVERY_ENABLE . '_' . self::CHAIN_ID;

        try {
            ChainsPage::handle_cw_discovery_enable();
            $this->fail('A missing chain id must terminate the request.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(400, $e->status);
        }

        $this->assertNothingHappened('missing chain id');
    }

    /**
     * No raw request value reaches a log, notice, redirect or audit row —
     * and no PHP warning is emitted while establishing that.
     *
     * @param mixed $id
     */
    #[DataProvider('badIds')]
    public function testNoRawRequestValueEverEscapes(mixed $id): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;

            return true;
        });

        $_POST['chain_id'] = $id;
        \BccAdminTestState::$validNonceAction =
            ChainsPage::ACTION_CW_DISCOVERY_ENABLE . '_' . self::CHAIN_ID;

        try {
            ChainsPage::handle_cw_discovery_enable();
        } catch (\BccAdminDie | \BccAdminRedirect $e) {
            // expected
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'validating a hostile chain_id must raise no PHP warning');

        // Nothing from the request may appear anywhere durable.
        $haystack = json_encode([
            'logs'   => \BCC\Core\Log\Logger::$entries,
            'audit'  => \BCC\Trust\Core\Security\AuditLogger::$rows,
        ]);

        foreach (['DROP TABLE', 'FORGED', 'not-a-number', '0x04', str_repeat('9', 64)] as $needle) {
            $this->assertStringNotContainsString((string) $needle, (string) $haystack);
        }
    }

    /**
     * A valid-looking id WITHOUT the capability: refused first, and the
     * refusal trace carries no request-supplied target.
     */
    public function testAnUnauthorisedRequestIsRefusedBeforeTheTargetIsEvenRead(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$can = false;
        \BccAdminTestState::$validNonceAction =
            ChainsPage::ACTION_CW_DISCOVERY_ENABLE . '_' . self::CHAIN_ID;

        try {
            ChainsPage::handle_cw_discovery_enable();
            $this->fail('Expected a 403.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status, 'capability is checked before the target is processed');
        }

        $warnings = \BCC\Core\Log\Logger::ofLevel('warning');
        $this->assertCount(1, $warnings);

        $context = $warnings[0]['context'];
        $this->assertSame('cosmwasm_chain_discovery_denied', $context['action']);
        $this->assertSame('enable', $context['direction'], 'direction comes from the handler, not the request');

        // The whole point: nothing the caller supplied is in the trace.
        $this->assertArrayNotHasKey('chain_id', $context);
        $this->assertArrayNotHasKey('target', $context);
        $this->assertStringNotContainsString(
            (string) self::CHAIN_ID,
            (string) json_encode($context),
            'an unauthorised caller must not be able to confirm a chain id through our log'
        );

        $this->assertNothingHappened('unauthorised with a valid-looking id');
    }

    /** A hostile id from an unauthorised caller is refused 403, not 400. */
    public function testUnauthorisedBeatsMalformed(): void
    {
        $_POST['chain_id'] = ["4\r\nFORGED"];
        \BccAdminTestState::$can = false;

        try {
            ChainsPage::handle_cw_discovery_enable();
            $this->fail('Expected a 403.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status, 'capability first — the target is never processed');
        }

        $this->assertStringNotContainsString(
            'FORGED',
            (string) json_encode(\BCC\Core\Log\Logger::$entries)
        );
    }

    #[DataProvider('directions')]
    public function testABadNonceIsRefused(bool $enable): void
    {
        $this->arrange($enable);
        \BccAdminTestState::$validNonceAction = 'something-else-entirely';

        try {
            $enable ? ChainsPage::handle_cw_discovery_enable() : ChainsPage::handle_cw_discovery_disable();
            $this->fail('A forged nonce must be refused.');
        } catch (\BccAdminDie $e) {
            // expected
        }

        $this->assertNothingHappened('bad nonce');
    }

    // ── 5–8. Nonce isolation ────────────────────────────────────────────

    public function testAnEnableNonceCannotDisable(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction =
            ChainsPage::ACTION_CW_DISCOVERY_ENABLE . '_' . self::CHAIN_ID;

        $this->expectException(\BccAdminDie::class);
        ChainsPage::handle_cw_discovery_disable();
    }

    public function testADisableNonceCannotEnable(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction =
            ChainsPage::ACTION_CW_DISCOVERY_DISABLE . '_' . self::CHAIN_ID;

        $this->expectException(\BccAdminDie::class);
        ChainsPage::handle_cw_discovery_enable();
    }

    #[DataProvider('directions')]
    public function testANonceForAnotherChainIsRejected(bool $enable): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction = self::route($enable) . '_' . self::OTHER_CHAIN_ID;

        try {
            $enable ? ChainsPage::handle_cw_discovery_enable() : ChainsPage::handle_cw_discovery_disable();
            $this->fail('A nonce scoped to another chain must be refused.');
        } catch (\BccAdminDie $e) {
            // expected
        }

        $this->assertNothingHappened('cross-target nonce');
    }

    #[DataProvider('directions')]
    public function testTheLegacyVerifyCollectionsNonceIsRejected(bool $enable): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction = 'bcc_verify_collections_nonce';

        try {
            $enable ? ChainsPage::handle_cw_discovery_enable() : ChainsPage::handle_cw_discovery_disable();
            $this->fail('The shared Verify Collections nonce must not authorise this route.');
        } catch (\BccAdminDie $e) {
            // expected
        }

        $this->assertNothingHappened('legacy shared nonce');
    }

    // ── 10–13. Lookup, single write, and no work started ────────────────

    public function testAnUnknownChainPerformsNoWrite(): void
    {
        ChainRepository::reset();
        $this->arrange(true);

        $r = $this->invoke(true);

        $this->assertSame('unknown_chain', $r->args['bcc_cwd']);
        $this->assertNothingHappened('unknown chain');
    }

    #[DataProvider('directions')]
    public function testAtMostOneWriteAndNoScannerWorkIsStarted(bool $enable): void
    {
        // Seed the OPPOSITE of the requested direction so it is a real change.
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, !$enable);
        $this->arrange($enable);

        $this->invoke($enable);

        $this->assertCount(1, ChainRepository::$discoveryWrites, 'exactly one settings write');
        $this->assertSame(self::CHAIN_ID, ChainRepository::$discoveryWrites[0]['chain_id']);
        $this->assertSame($enable, ChainRepository::$discoveryWrites[0]['enable']);

        // Opting in permits future work; it does not perform any.
        $this->assertSame(
            0,
            CosmwasmDiscoveryWorker::$passes,
            'the opt-in must not start a scan, backfill, retry, pause or resume'
        );
    }

    public function testTheAuthoritativeRowIsUsedNotTheSubmittedId(): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, false);
        $this->arrange(true);

        $this->invoke(true);

        // The write targeted the row the repository resolved.
        $this->assertSame(self::CHAIN_ID, ChainRepository::$discoveryWrites[0]['chain_id']);
        $this->assertSame('1', (string) ChainRepository::$rows[self::CHAIN_ID]->cosmwasm_nft_discovery_enabled);
        // …and the bystander is untouched.
        $this->assertSame('0', (string) ChainRepository::$rows[self::OTHER_CHAIN_ID]->cosmwasm_nft_discovery_enabled);
    }

    // ── 14–15. No-ops ───────────────────────────────────────────────────

    #[DataProvider('directions')]
    public function testAlreadyInTheRequestedStateIsATruthfulNoOp(bool $enable): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, $enable);
        $this->arrange($enable);

        $r = $this->invoke($enable);

        $this->assertSame($enable ? 'noop_enabled' : 'noop_disabled', $r->args['bcc_cwd']);
        $this->assertSame([], ChainRepository::$discoveryWrites, 'a no-op writes nothing');
        $this->assertSame(
            [],
            \BCC\Trust\Core\Security\AuditLogger::actions(),
            'a no-op is not a state transition, so it must not audit one'
        );
    }

    // ── 16–19, 21. Outcomes and their durable rows ──────────────────────

    #[DataProvider('directions')]
    public function testConfirmedChangeAuditsExactlyOnceAgainstTheChain(bool $enable): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, !$enable);
        $this->arrange($enable);

        $r = $this->invoke($enable);

        $this->assertSame($enable ? 'enabled' : 'disabled', $r->args['bcc_cwd']);
        $this->assertSame(
            [$enable ? 'admin_chain_cw_discovery_enabled' : 'admin_chain_cw_discovery_disabled'],
            \BCC\Trust\Core\Security\AuditLogger::actions()
        );

        $row = \BCC\Trust\Core\Security\AuditLogger::$rows[0];
        $this->assertSame('chain', $row['targetType']);
        $this->assertSame(self::CHAIN_ID, $row['targetId'], 'the real chain-row id');

        // The read-back happened: the stored value now matches.
        $this->assertSame(
            $enable ? '1' : '0',
            (string) ChainRepository::$rows[self::CHAIN_ID]->cosmwasm_nft_discovery_enabled
        );
        $this->assertSame(1, ChainRepository::$cacheBusts, 'the write must bust the chains cache');
    }

    #[DataProvider('directions')]
    public function testAReturnedWriteFailureAuditsExactlyOnceAndNeverSuccess(bool $enable): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, !$enable);
        $this->arrange($enable);
        ChainRepository::$writeResult = false;

        $r = $this->invoke($enable);

        $expected = $enable
            ? 'admin_chain_cw_discovery_enable_write_failed'
            : 'admin_chain_cw_discovery_disable_write_failed';

        $this->assertSame([$expected], \BCC\Trust\Core\Security\AuditLogger::actions());
        $this->assertSame('chain', \BCC\Trust\Core\Security\AuditLogger::$rows[0]['targetType']);
        $this->assertSame(self::CHAIN_ID, \BCC\Trust\Core\Security\AuditLogger::$rows[0]['targetId']);
        $this->assertSame($enable ? 'enable_write_failed' : 'disable_write_failed', $r->args['bcc_cwd']);

        // No correlation id: no exception was captured to correlate to.
        $this->assertArrayNotHasKey('bcc_ref', $r->args);
    }

    #[DataProvider('directions')]
    public function testAReadBackMismatchAuditsUnconfirmedAndNeverSuccess(bool $enable): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, !$enable);
        $this->arrange($enable);
        // The write "succeeds" but stores the opposite.
        ChainRepository::$readBackOverride = !$enable;

        $r = $this->invoke($enable);

        $expected = $enable
            ? 'admin_chain_cw_discovery_enable_unconfirmed'
            : 'admin_chain_cw_discovery_disable_unconfirmed';

        $this->assertSame([$expected], \BCC\Trust\Core\Security\AuditLogger::actions());
        $this->assertSame('chain', \BCC\Trust\Core\Security\AuditLogger::$rows[0]['targetType']);
        $this->assertSame(self::CHAIN_ID, \BCC\Trust\Core\Security\AuditLogger::$rows[0]['targetId']);
        $this->assertSame($enable ? 'enable_unconfirmed' : 'disable_unconfirmed', $r->args['bcc_cwd']);
    }

    #[DataProvider('directions')]
    public function testAnUnreadableReadBackIsAlsoUnconfirmed(bool $enable): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, !$enable);
        $this->arrange($enable);
        ChainRepository::$readBackNull = true;

        $r = $this->invoke($enable);

        $this->assertSame($enable ? 'enable_unconfirmed' : 'disable_unconfirmed', $r->args['bcc_cwd']);
        $this->assertStringContainsString('unconfirmed', \BCC\Trust\Core\Security\AuditLogger::actions()[0]);
    }

    // ── 20. Unexpected exception ────────────────────────────────────────

    public function testAnUnexpectedExceptionIsRedactedAndCorrelated(): void
    {
        $secret = 'Discovery provider failure SECRET_INTERNAL_DETAIL';

        ChainRepository::seed(self::CHAIN_ID, self::SLUG, false);
        $this->arrange(true);
        ChainRepository::$writeThrows = new \RuntimeException($secret);

        $r = $this->invoke(true);

        $this->assertSame('error', $r->args['bcc_cwd']);
        $this->assertArrayHasKey('bcc_ref', $r->args);
        $this->assertSame(1, preg_match('/^bcc-[0-9a-f]{8}$/', (string) $r->args['bcc_ref']));

        $joined = implode(' ', array_map('strval', $r->args));
        $this->assertStringNotContainsString('SECRET_INTERNAL_DETAIL', $joined);
        $this->assertStringNotContainsString('RuntimeException', $joined);

        $this->assertSame(
            ['admin_chain_cw_discovery_enable_error'],
            \BCC\Trust\Core\Security\AuditLogger::actions()
        );
        $this->assertSame('chain', \BCC\Trust\Core\Security\AuditLogger::$rows[0]['targetType']);
        $this->assertSame(self::CHAIN_ID, \BCC\Trust\Core\Security\AuditLogger::$rows[0]['targetId']);

        $errors = \BCC\Core\Log\Logger::ofLevel('error');
        $this->assertNotSame([], $errors);
        $this->assertSame($secret, $errors[0]['context']['message']);
        $this->assertSame($r->args['bcc_ref'], $errors[0]['context']['correlation_id']);
    }

    // ── 22–25. PRG and the inert destination ────────────────────────────

    #[DataProvider('directions')]
    public function testTheRedirectCarriesOnlyTheApprovedKeys(bool $enable): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, !$enable);
        $this->arrange($enable);

        $r = $this->invoke($enable);

        $this->assertSame(
            ['page', 'subtab', 'bcc_cwd'],
            array_keys($r->args),
            'the destination allowlist is page, subtab, bcc_cwd and optionally bcc_ref — nothing else'
        );
        $this->assertSame(ChainsPage::PAGE_SLUG, $r->args['page']);
        $this->assertSame('nft-discovery', $r->args['subtab']);

        foreach (array_keys($r->args) as $key) {
            $this->assertContains($key, ChainsPage::REDIRECT_KEYS);
        }
    }

    #[DataProvider('directions')]
    public function testNoRedirectKeyOrValueLeaksTheSubmittedMutationInput(bool $enable): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, !$enable);
        $this->arrange($enable);

        $r = $this->invoke($enable);

        foreach (['chain_id', 'bcc_cwd_chain', 'action', '_wpnonce', 'direction', 'enable'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $r->args);
        }

        // The chain id must not appear as a VALUE either, under any key.
        foreach ($r->args as $key => $value) {
            $this->assertNotSame(
                (string) self::CHAIN_ID,
                (string) $value,
                "the chain id must not survive in the destination under key `{$key}`"
            );
        }

        // Nor the route name, nor the nonce action.
        $joined = implode('|', array_map('strval', $r->args));
        $this->assertStringNotContainsString(self::route($enable), $joined);
    }

    public function testRefreshingTheDestinationCannotRepeatTheMutation(): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, false);
        $this->arrange(true);

        $r = $this->invoke(true);
        $this->assertCount(1, ChainRepository::$discoveryWrites);

        // Re-entering the page with exactly the destination args is a GET
        // render, and the render path performs no write.
        $_POST = [];
        $_GET  = $r->args;

        $this->assertCount(
            1,
            ChainRepository::$discoveryWrites,
            'the destination is inert — reaching it again writes nothing'
        );
    }

    // ── Relocated from CosmwasmChainDiscoveryAdminTest ──────────────────
    // These proved transport, not domain, so they moved with the control.

    /**
     * A refused write leaves a trace. Kept verbatim in intent from the
     * control's previous home: an unauthorised attempt on a state change is
     * worth seeing in the log even though the request never got near a row.
     */
    #[DataProvider('directions')]
    public function testARefusedRequestLeavesATrace(bool $enable): void
    {
        $this->arrange($enable);
        \BccAdminTestState::$can = false;

        try {
            $enable ? ChainsPage::handle_cw_discovery_enable() : ChainsPage::handle_cw_discovery_disable();
        } catch (\BccAdminDie $e) {
            // expected
        }

        $warnings = \BCC\Core\Log\Logger::ofLevel('warning');
        $this->assertNotSame([], $warnings, 'an unauthorised attempt on a write must be recorded');
        $this->assertSame('cosmwasm_chain_discovery_denied', $warnings[0]['context']['action']);
        $this->assertSame($enable ? 'enable' : 'disable', $warnings[0]['context']['direction']);

        // The trace is built from server-known data ONLY. `chain_id` is
        // attacker-controlled and unvalidated at this point, so it is
        // deliberately absent — see testAnUnauthorisedRequestIsRefused-BeforeTheTargetIsEvenRead.
        $this->assertArrayNotHasKey('chain_id', $warnings[0]['context']);
    }

    /**
     * The stored flag does not move on any refusal — the mirror of the
     * "writes nothing" assertions the domain test used to carry.
     */
    #[DataProvider('directions')]
    public function testNoRefusalEverMovesTheStoredFlag(bool $enable): void
    {
        ChainRepository::seed(self::CHAIN_ID, self::SLUG, !$enable);
        $expected = $enable ? '0' : '1';

        // (a) no capability
        $this->arrange($enable);
        \BccAdminTestState::$can = false;
        try {
            $enable ? ChainsPage::handle_cw_discovery_enable() : ChainsPage::handle_cw_discovery_disable();
        } catch (\BccAdminDie $e) {
        }
        $this->assertSame($expected, (string) ChainRepository::$rows[self::CHAIN_ID]->cosmwasm_nft_discovery_enabled);

        // (b) capability held, nonce forged
        \BccAdminTestState::$can = true;
        \BccAdminTestState::$validNonceAction = 'not-the-nonce';
        try {
            $enable ? ChainsPage::handle_cw_discovery_enable() : ChainsPage::handle_cw_discovery_disable();
        } catch (\BccAdminDie $e) {
        }
        $this->assertSame($expected, (string) ChainRepository::$rows[self::CHAIN_ID]->cosmwasm_nft_discovery_enabled);

        $this->assertSame([], ChainRepository::$discoveryWrites, 'neither refusal reached the repository');
        $this->assertSame(0, ChainRepository::$cacheBusts);
    }

    // ── Operator copy: truthful about what each direction does ──────────

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: list<string>}>
     */
    public static function copyExpectations(): array
    {
        return [
            'enabled' => ['enabled', 'success',
                ['No scan was started by this action', 'only when the existing capability, eligibility, pause and safety gates allow it', 'arrives unverified'],
                ['scan has started', 'scanning now', 'is now scanning'],
            ],
            'disabled' => ['disabled', 'success',
                ['Future automatic passes will not select this chain', 'Existing inventory and progress were kept', 'no collection was unverified or removed'],
                ['No pass will consider', 'cancels', 'stopped the running'],
            ],
            'enable_write_failed' => ['enable_write_failed', 'error',
                ['could NOT be enabled', 'It remains disabled'], ['remains enabled'],
            ],
            'disable_write_failed' => ['disable_write_failed', 'error',
                ['could NOT be disabled', 'It remains enabled'], ['remains disabled'],
            ],
            'enable_unconfirmed' => ['enable_unconfirmed', 'error',
                ['could NOT be confirmed', 'does not read back as enabled'], ['remains', 'success'],
            ],
            'disable_unconfirmed' => ['disable_unconfirmed', 'error',
                ['could NOT be confirmed', 'does not read back as disabled'], ['remains', 'success'],
            ],
        ];
    }

    /**
     * @param list<string> $mustContain
     * @param list<string> $mustNotContain
     */
    #[DataProvider('copyExpectations')]
    public function testTheOperatorCopyIsTruthful(
        string $code,
        string $severity,
        array $mustContain,
        array $mustNotContain
    ): void {
        $_GET = ['bcc_cwd' => $code];

        $m = new \ReflectionMethod(ChainsPage::class, 'cw_discovery_notice_from_query');
        $m->setAccessible(true);
        /** @var array{type: string, message: string}|null $notice */
        $notice = $m->invoke(null);

        $this->assertNotNull($notice);
        $this->assertSame($severity, $notice['type']);

        foreach ($mustContain as $needle) {
            $this->assertStringContainsString($needle, $notice['message']);
        }
        foreach ($mustNotContain as $needle) {
            $this->assertStringNotContainsString($needle, $notice['message']);
        }

        // No internal detail, ever.
        foreach (['SQLSTATE', 'wpdb', 'Exception', 'SELECT', 'UPDATE ', 'cosmwasm_nft_discovery_enabled'] as $leak) {
            $this->assertStringNotContainsString($leak, $notice['message']);
        }
    }

    /** Neither direction claims to verify or provision anything. */
    public function testNeitherDirectionClaimsToVerifyOrProvision(): void
    {
        foreach (['enabled', 'disabled'] as $code) {
            $_GET = ['bcc_cwd' => $code];
            $m = new \ReflectionMethod(ChainsPage::class, 'cw_discovery_notice_from_query');
            $m->setAccessible(true);
            /** @var array{type: string, message: string} $notice */
            $notice = $m->invoke(null);

            $lower = strtolower($notice['message']);
            foreach (['verifies', 'verified the', 'provision', 'community created'] as $claim) {
                $this->assertStringNotContainsString($claim, $lower);
            }
        }
    }

    // ── 26. The vocabulary fits the column ──────────────────────────────

    public function testEveryVcb2AuditActionFitsTheActionColumn(): void
    {
        $actions = [
            'admin_chain_cw_discovery_enabled',
            'admin_chain_cw_discovery_disabled',
            'admin_chain_cw_discovery_enable_write_failed',
            'admin_chain_cw_discovery_disable_write_failed',
            'admin_chain_cw_discovery_enable_unconfirmed',
            'admin_chain_cw_discovery_disable_unconfirmed',
            'admin_chain_cw_discovery_enable_error',
            'admin_chain_cw_discovery_disable_error',
        ];

        foreach ($actions as $action) {
            $this->assertLessThanOrEqual(
                50,
                strlen($action),
                "`{$action}` would be truncated by the VARCHAR(50) action column, merging two distinct events."
            );
        }

        $this->assertSame($actions, array_unique($actions), 'no two outcomes may share a name');
    }
}
