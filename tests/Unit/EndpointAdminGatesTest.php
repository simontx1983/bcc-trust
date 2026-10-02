<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainsPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The endpoint switch's two POST routes, its result copy, and its decoupling.
 *
 * ── THESE GATES HAD NO TESTS AT ALL ─────────────────────────────────────
 * The switch handler's capability, method and nonce checks were previously
 * exercised by nothing: the handler appeared in the suite only through a
 * `method_exists()` call. Both routes move provider configuration, so a request
 * that should have been refused reaching the service is the failure that matters
 * most here — which is why the service is faked and COUNTED.
 */
#[CoversClass(ChainsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EndpointAdminGatesTest extends TestCase
{
    private const CHAIN = 8;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/endpoint-admin-stubs.php';
        require_once __DIR__ . '/../Stubs/endpoint-admin-service-stub.php';

        \BccEndpointAdminState::reset();
        \BccEndpointAdminState::seedChain(self::CHAIN, 'https://rest.cosmos.directory/cosmoshub');

        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function routes(): array
    {
        return [
            'review' => ['handle_endpoint_review', 'bcc_chain_endpoint_review', 'bcc_chain_endpoint_review_nonce'],
            'switch' => ['handle_endpoint_switch', 'bcc_chain_endpoint_switch', 'bcc_chain_endpoint_nonce'],
        ];
    }

    private function reached(): int
    {
        return count(\BccEndpointAdminState::$reviewCalls) + count(\BccEndpointAdminState::$executeCalls);
    }

    private function invokeHandler(string $handler): \Throwable
    {
        try {
            ChainsPage::$handler();
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('the handler must terminate in a die or a redirect');
    }

    // ── Capability ──────────────────────────────────────────────────────

    #[DataProvider('routes')]
    public function testWithoutCapabilityNothingIsReached(string $handler, string $action, string $nonceField): void
    {
        \BccEndpointAdminState::$can = false;

        $e = $this->invokeHandler($handler);

        self::assertInstanceOf(\BccAdminDie::class, $e);
        self::assertSame(403, $e->status);
        self::assertSame(0, $this->reached());
        self::assertSame([], \BccEndpointAdminState::$nonceChecks, 'capability is checked first');
    }

    // ── Method ──────────────────────────────────────────────────────────

    /**
     * ⚠ A GET IS REFUSED ON ITS METHOD, BEFORE THE NONCE.
     *
     * `admin-post.php` dispatches for GET too and `check_admin_referer()` reads
     * `$_REQUEST`, so a valid nonce in a URL would otherwise be enough. The
     * nonce-check list being empty is what proves the ordering.
     */
    #[DataProvider('routes')]
    public function testAGetIsRefusedOnItsMethodBeforeTheNonce(string $handler, string $action, string $nonceField): void
    {
        \BccEndpointAdminState::$validNonceAction = $action;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST['chain_id'] = self::CHAIN;

        $e = $this->invokeHandler($handler);

        self::assertInstanceOf(\BccAdminDie::class, $e);
        self::assertSame(405, $e->status);
        self::assertSame(0, $this->reached());
        self::assertSame([], \BccEndpointAdminState::$nonceChecks);
    }

    // ── Nonce ───────────────────────────────────────────────────────────

    #[DataProvider('routes')]
    public function testABadNonceReachesNothing(string $handler, string $action, string $nonceField): void
    {
        \BccEndpointAdminState::$validNonceAction = 'something_else';
        $_POST['chain_id'] = self::CHAIN;

        $e = $this->invokeHandler($handler);

        self::assertInstanceOf(\BccAdminDie::class, $e);
        self::assertSame(403, $e->status);
        self::assertSame(0, $this->reached());
    }

    #[DataProvider('routes')]
    public function testTheTwoRoutesDoNotShareANonce(string $handler, string $action, string $nonceField): void
    {
        // Offer the OTHER route's action as the only valid one.
        $other = $action === 'bcc_chain_endpoint_review'
            ? 'bcc_chain_endpoint_switch'
            : 'bcc_chain_endpoint_review';
        \BccEndpointAdminState::$validNonceAction = $other;
        $_POST['chain_id'] = self::CHAIN;

        $e = $this->invokeHandler($handler);

        self::assertInstanceOf(\BccAdminDie::class, $e);
        self::assertSame(403, $e->status);
        self::assertSame(0, $this->reached());
    }

    // ── Arguments ───────────────────────────────────────────────────────

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: string}> */
    public static function argumentCases(): array
    {
        return [
            'review: no chain'      => ['handle_endpoint_review', 'bcc_chain_endpoint_review', [], 'invalid_chain'],
            'review: unknown chain' => ['handle_endpoint_review', 'bcc_chain_endpoint_review', ['chain_id' => 999], 'invalid_chain'],
            'review: no target'     => ['handle_endpoint_review', 'bcc_chain_endpoint_review', ['chain_id' => self::CHAIN], 'missing_input'],
            'switch: no chain'      => ['handle_endpoint_switch', 'bcc_chain_endpoint_switch', [], 'invalid_chain'],
            'switch: unknown chain' => ['handle_endpoint_switch', 'bcc_chain_endpoint_switch', ['chain_id' => 999], 'invalid_chain'],
            'switch: no review id'  => ['handle_endpoint_switch', 'bcc_chain_endpoint_switch', ['chain_id' => self::CHAIN], 'missing_input'],
        ];
    }

    /** @param array<string, mixed> $post */
    #[DataProvider('argumentCases')]
    public function testBadArgumentsRedirectWithoutReachingTheService(
        string $handler,
        string $action,
        array $post,
        string $expected
    ): void {
        \BccEndpointAdminState::$validNonceAction = $action;
        foreach ($post as $k => $v) {
            $_POST[$k] = $v;
        }

        $e = $this->invokeHandler($handler);

        self::assertInstanceOf(\BccAdminRedirect::class, $e);
        self::assertSame($expected, $e->args['bcc_endpoint'] ?? null);
        self::assertSame(0, $this->reached());
    }

    /**
     * ⚠ THE HARD SWITCH, PINNED. The retired `plan_digest` operand must buy
     * nothing: a POST carrying it and no review id is incomplete.
     */
    public function testARetiredPlanDigestIsNotAcceptedAsConfirmation(): void
    {
        \BccEndpointAdminState::$validNonceAction = 'bcc_chain_endpoint_switch';
        $_POST['chain_id']    = self::CHAIN;
        $_POST['plan_digest'] = 'deadbeefdeadbeefdeadbeefdeadbeef';

        $e = $this->invokeHandler('handle_endpoint_switch');

        self::assertInstanceOf(\BccAdminRedirect::class, $e);
        self::assertSame('missing_input', $e->args['bcc_endpoint'] ?? null);
        self::assertSame(0, $this->reached(), 'a digest is not a confirmation any more');
    }

    /** The switch does NOT take its target from the request. */
    public function testTheSwitchIgnoresASubmittedTarget(): void
    {
        \BccEndpointAdminState::$validNonceAction = 'bcc_chain_endpoint_switch';
        $_POST['chain_id']   = self::CHAIN;
        $_POST['review_id']  = 'stub-review-id';
        $_POST['target_url'] = 'https://attacker.test';

        try {
            ChainsPage::handle_endpoint_switch();
        } catch (\Throwable) {
            // redirect
        }

        self::assertCount(1, \BccEndpointAdminState::$executeCalls);
        self::assertSame(
            ['chain' => self::CHAIN, 'review' => 'stub-review-id'],
            \BccEndpointAdminState::$executeCalls[0],
            'only the chain and the review id are passed — the target comes from the review'
        );
    }

    // ── Result copy ─────────────────────────────────────────────────────

    /**
     * @return list<array{0: string}>
     */
    public static function everyResultCode(): array
    {
        return array_map(static fn(string $c): array => [$c], [
            'review_ready', 'switched', 'switched_followups_failed', 'switched_unconfirmed',
            'switched_then_superseded', 'write_unconfirmed', 'from_mismatch', 'identity_changed',
            'review_token_invalid', 'review_chain_mismatch', 'review_consume_failed',
            'lock_contended', 'already_current', 'not_governed', 'chain_inactive',
            'target_malformed', 'target_not_approved', 'target_unreachable',
            'target_identity_unreadable', 'target_network_mismatch', 'invalid_chain', 'missing_input',
            'unexpected_error',
        ]);
    }

    #[DataProvider('everyResultCode')]
    public function testEveryResultCodeHasOperatorCopy(string $code): void
    {
        $_GET['bcc_endpoint'] = $code;

        $m = new ReflectionMethod(ChainsPage::class, 'endpoint_notice_from_query');
        $m->setAccessible(true);
        /** @var array{type: string, message: string}|null $notice */
        $notice = $m->invoke(null);

        self::assertIsArray($notice, "the code '{$code}' has no notice copy");
        self::assertNotSame('', trim($notice['message']));
        self::assertContains($notice['type'], ['success', 'info', 'warning', 'error']);
    }

    /**
     * ⚠⚠⚠ NO POST-WRITE CODE MAY SAY NOTHING HAPPENED.
     *
     * The copy is where the operator actually learns this, so the invariant is
     * asserted on the words, not only on the reason string.
     */
    public function testPostWriteCopyAlwaysSaysTheEndpointChanged(): void
    {
        $m = new ReflectionMethod(ChainsPage::class, 'endpoint_notice_from_query');
        $m->setAccessible(true);

        foreach ([
            'switched', 'switched_followups_failed', 'switched_unconfirmed', 'switched_then_superseded',
        ] as $code) {
            $_GET['bcc_endpoint'] = $code;
            /** @var array{type: string, message: string} $notice */
            $notice = $m->invoke(null);

            self::assertStringNotContainsStringIgnoringCase(
                'nothing changed',
                $notice['message'],
                "'{$code}' happens AFTER the write — it must never read as no change"
            );
        }
    }

    /** And the pre-write refusals must say the opposite, or the above is vacuous. */
    public function testPreWriteCopySaysNothingChanged(): void
    {
        $m = new ReflectionMethod(ChainsPage::class, 'endpoint_notice_from_query');
        $m->setAccessible(true);

        foreach ([
            'from_mismatch', 'identity_changed', 'review_token_invalid', 'review_consume_failed',
            'target_network_mismatch', 'target_not_approved',
        ] as $code) {
            $_GET['bcc_endpoint'] = $code;
            /** @var array{type: string, message: string} $notice */
            $notice = $m->invoke(null);

            self::assertStringContainsStringIgnoringCase(
                'nothing changed',
                $notice['message'],
                "'{$code}' refused before any write and should say so"
            );
        }
    }

    public function testTheFailedFollowupListIsABoundedEnum(): void
    {
        $_GET['bcc_endpoint']  = 'switched_followups_failed';
        $_GET['bcc_ep_failed'] = 'breaker,audit,<script>alert(1)</script>,nonsense';

        $m = new ReflectionMethod(ChainsPage::class, 'endpoint_notice_from_query');
        $m->setAccessible(true);
        /** @var array{type: string, message: string} $notice */
        $notice = $m->invoke(null);

        self::assertStringContainsString('circuit breaker', $notice['message']);
        self::assertStringContainsString('audit row', $notice['message']);
        self::assertStringNotContainsString('script', $notice['message']);
        self::assertStringNotContainsString('nonsense', $notice['message']);
    }

    // ── Decoupling, with planted positives ──────────────────────────────

    private static function source(string $relative): string
    {
        return (string) file_get_contents(__DIR__ . '/../../' . $relative);
    }

    /**
     * ⚠ The switch service must name no scanner class and no scanner constant.
     * The planted positive below is what makes this assertion mean something.
     */
    public function testTheSwitchServiceNamesNoScannerCode(): void
    {
        $src = self::source('app/Domain/Onchain/Services/CosmosEndpointTransition.php');

        foreach (['Cosmwasm', 'cosmwasm', 'ADVISORY_LOCK_PREFIX', 'CosmosEndpointAuthorization'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $src,
                "the switch is decoupled from the scanner; '{$needle}' must not appear"
            );
        }
    }

    /** ANTI-VACUITY for the test above: the scan must be able to find things. */
    public function testTheScannerScanWouldDetectAPlantedReference(): void
    {
        $src = self::source('app/Domain/Onchain/Services/CosmosEndpointTransition.php');
        $planted = $src . "\n// CosmwasmDiscoveryWorker::ADVISORY_LOCK_PREFIX\n";

        self::assertStringContainsString('Cosmwasm', $planted);
        self::assertStringContainsString('ADVISORY_LOCK_PREFIX', $planted);
        self::assertNotSame('', $src, 'anti-vacuity: the file was actually read');
    }

    public function testTheSwitchTakesItsOwnLockName(): void
    {
        $src = self::source('app/Domain/Onchain/Services/CosmosEndpointTransition.php');

        self::assertStringContainsString("'bcc_cosmos_endpoint_'", $src);
        self::assertStringNotContainsString('bcc_cosmwasm_chain_', $src);
    }

    /** The CAS must be a binary comparison, not a collation. */
    public function testTheWriteComparesAsBinaryAndNotByCollation(): void
    {
        $src = self::source('app/Domain/Onchain/Repositories/ChainRepository.php');

        self::assertStringContainsString('CAST(rest_url AS BINARY)', $src);
        self::assertStringContainsString('CAST(slug AS BINARY)', $src);
        self::assertStringContainsString('rest_url IS NULL', $src, 'the NULL branch must survive');
        self::assertStringNotContainsString(
            'rest_url COLLATE utf8mb4_bin',
            $src,
            'utf8mb4_bin is PAD SPACE and would ignore trailing spaces'
        );
    }

    // ══ An unexpected throw must not become a fatal page ═══════════

    /**
     * ⚠⚠ THE WORST CASE IS A THROW AFTER THE ROW MOVED. Uncaught, the operator
     * gets "There has been a critical error": no notice, no redirect, and no way
     * to tell whether the endpoint changed. The house pattern turns it into a
     * durable failure row plus a reference the operator can quote.
     */
    public function testAnUnexpectedThrowRedirectsWithAReferenceInsteadOfFataling(): void
    {
        \BccEndpointAdminState::$validNonceAction = ChainsPage::ACTION_ENDPOINT_SWITCH;
        \BccEndpointAdminState::seedChain(8, 'https://rest.cosmos.directory/cosmoshub');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['chain_id' => '8', 'review_id' => 'whatever'];

        \BCC\Trust\Onchain\Services\CosmosEndpointTransition::$throws = true;

        $e = $this->invokeHandler('handle_endpoint_switch');

        self::assertInstanceOf(
            \BccAdminRedirect::class,
            $e,
            'a throw must become a redirect, never an escaping exception'
        );
        self::assertSame(
            'unexpected_error',
            $e->args['bcc_endpoint'] ?? null,
            'the operator gets a result code'
        );
        self::assertNotSame(
            '',
            (string) ($e->args['bcc_ep_ref'] ?? ''),
            'and a reference that joins the notice to the log'
        );
    }

    /** The durable record of the failure is written, not merely logged. */
    public function testAnUnexpectedThrowLeavesADurableFailureRow(): void
    {
        \BccEndpointAdminState::$validNonceAction = ChainsPage::ACTION_ENDPOINT_SWITCH;
        \BccEndpointAdminState::seedChain(8, 'https://rest.cosmos.directory/cosmoshub');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['chain_id' => '8', 'review_id' => 'whatever'];

        \BCC\Trust\Onchain\Services\CosmosEndpointTransition::$throws = true;

        $this->invokeHandler('handle_endpoint_switch');

        self::assertCount(1, \BccEndpointAdminState::$auditRows);
        self::assertSame(
            'failed',
            \BccEndpointAdminState::$auditRows[0]['meta']['outcome'] ?? null,
            'the row says the operation failed'
        );
    }

    /**
     * ANTI-VACUITY: with the service NOT throwing, the same request yields the
     * ordinary code and no failure row — so the two tests above measure the
     * throw and not the fixture.
     */
    public function testWithoutAThrowTheSameRequestSucceedsAndWritesNoFailureRow(): void
    {
        \BccEndpointAdminState::$validNonceAction = ChainsPage::ACTION_ENDPOINT_SWITCH;
        \BccEndpointAdminState::seedChain(8, 'https://rest.cosmos.directory/cosmoshub');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['chain_id' => '8', 'review_id' => 'whatever'];

        $e = $this->invokeHandler('handle_endpoint_switch');

        self::assertInstanceOf(\BccAdminRedirect::class, $e);
        self::assertSame('switched', $e->args['bcc_endpoint'] ?? null);
        self::assertSame([], \BccEndpointAdminState::$auditRows);
    }
}
