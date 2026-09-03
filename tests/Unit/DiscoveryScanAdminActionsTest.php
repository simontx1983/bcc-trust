<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Admin\DiscoveryScanActions;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BccScanAdminDied;
use BccScanAdminRedirected;
use BccScanAdminState;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Stubs/onchain-admin-action-stubs.php';
require_once __DIR__ . '/../Stubs/discovery-scan-admin-stubs.php';

/**
 * PR 7 — the administrator Scan action's REFUSALS.
 *
 * ── WHY THE REFUSALS ARE THE TEST ───────────────────────────────────────
 * The happy path is one line: hand the decision to `DiscoveryRunService`,
 * which PR 7A already proves. What this handler adds is a set of gates, and a
 * gate is only worth anything if it is shown to refuse. So every test below
 * drives a request that MUST NOT create a run and asserts it did not.
 *
 * `wp_die()` and the PRG redirect both end the request in production, so both
 * are modelled as thrown control flow. That is what makes "the write never
 * happened" assertable rather than merely "the function returned".
 */
final class DiscoveryScanAdminActionsTest extends TestCase
{
    private const CHAIN = 4242;

    protected function setUp(): void
    {
        parent::setUp();
        BccScanAdminState::reset();
        $GLOBALS['__bcc_scan_current_action'] = 'admin_post_' . DiscoveryScanActions::ACTION_REQUEST;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_scan_current_action']);
        $_POST = [];
        parent::tearDown();
    }

    /** Arrange a request that would otherwise be valid. */
    private function arrangeValidRequest(): void
    {
        $_POST = ['chain_id' => (string) self::CHAIN];
        BccScanAdminState::$validNonces[] = DiscoveryScanActions::nonceAction(
            DiscoveryScanActions::ACTION_REQUEST,
            self::CHAIN
        );
    }

    /** @return BccScanAdminDied|BccScanAdminRedirected */
    private function runHandler(): \RuntimeException
    {
        try {
            DiscoveryScanActions::handle();
        } catch (BccScanAdminDied $e) {
            return $e;
        } catch (BccScanAdminRedirected $e) {
            return $e;
        }

        self::fail('the handler must terminate the request');
    }

    // ── (1) method ──────────────────────────────────────────────────────

    /**
     * ⚠ admin-post.php dispatches `admin_post_{action}` for GET as well as
     * POST — it reads the action out of `$_REQUEST`. A handler that only
     * reads `$_POST` is therefore NOT POST-only: a crafted GET reaches it,
     * finds an empty `$_POST`, and runs whatever the empty-input path does.
     */
    public function testAGetRequestCannotTriggerAnything(): void
    {
        $this->arrangeValidRequest();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $outcome = $this->runHandler();

        self::assertInstanceOf(BccScanAdminDied::class, $outcome);
        self::assertSame(405, $outcome->status);
    }

    public function testHeadAndPutAreAlsoRefused(): void
    {
        foreach (['HEAD', 'PUT', 'DELETE', 'PATCH'] as $method) {
            BccScanAdminState::reset();
            $this->arrangeValidRequest();
            $_SERVER['REQUEST_METHOD'] = $method;

            $outcome = $this->runHandler();
            self::assertInstanceOf(BccScanAdminDied::class, $outcome, $method);
            self::assertSame(405, $outcome->status, $method);
        }
    }

    // ── (2) capability ──────────────────────────────────────────────────

    public function testAnUnprivilegedSessionIsRefusedBeforeAnythingElse(): void
    {
        $this->arrangeValidRequest();
        BccScanAdminState::$sessionCan = false;

        $outcome = $this->runHandler();

        self::assertInstanceOf(BccScanAdminDied::class, $outcome);
        self::assertSame(403, $outcome->status);
    }

    /**
     * The capability gate runs BEFORE the method gate, so an unprivileged GET
     * is refused for the reason that matters most.
     */
    public function testCapabilityIsCheckedBeforeMethod(): void
    {
        $this->arrangeValidRequest();
        BccScanAdminState::$sessionCan = false;
        $_SERVER['REQUEST_METHOD']     = 'GET';

        self::assertSame(403, $this->runHandler()->status);
    }

    // ── (3) nonce ───────────────────────────────────────────────────────

    public function testAMissingNonceIsRefused(): void
    {
        $_POST = ['chain_id' => (string) self::CHAIN];
        // No nonce registered as valid.

        $outcome = $this->runHandler();

        self::assertInstanceOf(BccScanAdminDied::class, $outcome);
        self::assertSame(403, $outcome->status);
    }

    /**
     * ⚠ A nonce minted for ONE chain must not start a scan on another. The
     * operator authorized that chain, not any chain.
     */
    public function testANonceForAnotherChainIsRefused(): void
    {
        $_POST = ['chain_id' => (string) self::CHAIN];
        BccScanAdminState::$validNonces[] = DiscoveryScanActions::nonceAction(
            DiscoveryScanActions::ACTION_REQUEST,
            9999
        );

        self::assertSame(403, $this->runHandler()->status);
    }

    /** And a nonce for a different ACTION on the same chain is refused too. */
    public function testANonceForAnotherActionIsRefused(): void
    {
        $_POST = ['chain_id' => (string) self::CHAIN];
        BccScanAdminState::$validNonces[] = DiscoveryScanActions::nonceAction(
            DiscoveryScanActions::ACTION_CANCEL,
            self::CHAIN
        );

        self::assertSame(403, $this->runHandler()->status);
    }

    public function testTheNonceActionIsChainScoped(): void
    {
        self::assertNotSame(
            DiscoveryScanActions::nonceAction(DiscoveryScanActions::ACTION_REQUEST, 1),
            DiscoveryScanActions::nonceAction(DiscoveryScanActions::ACTION_REQUEST, 2)
        );
    }

    // ── (4) the EXPLICIT operator ───────────────────────────────────────

    /**
     * ⚠ NOT redundant with the capability check.
     *
     * `current_user_can()` answers "is the session privileged". The ledger
     * records WHO asked. A run attributed to an implicit session is a run
     * with no accountable author, so the id is resolved explicitly and
     * re-checked by the service.
     */
    public function testUserIdZeroIsRefused(): void
    {
        $this->arrangeValidRequest();
        BccScanAdminState::$currentUserId = 0;

        $outcome = $this->runHandler();

        self::assertInstanceOf(BccScanAdminRedirected::class, $outcome);
        self::assertSame('refused', $outcome->args['bcc_scan'] ?? null);
        self::assertSame(DiscoveryRunError::OPERATOR_UNRESOLVED, $outcome->args['bcc_reason'] ?? null);
    }

    public function testANonexistentUserIsRefusedByTheService(): void
    {
        $this->arrangeValidRequest();
        BccScanAdminState::$currentUserId = 4242;
        BccScanAdminState::$users         = []; // nobody exists

        $outcome = $this->runHandler();

        self::assertInstanceOf(BccScanAdminRedirected::class, $outcome);
        self::assertSame(DiscoveryRunError::OPERATOR_UNRESOLVED, $outcome->args['bcc_reason'] ?? null);
    }

    /**
     * A session that passes `current_user_can()` but whose USER does not hold
     * the capability — the exact gap an implicit check would miss.
     */
    public function testAUserWithoutManageOptionsIsRefusedByTheService(): void
    {
        $this->arrangeValidRequest();
        BccScanAdminState::$currentUserId = 11;
        BccScanAdminState::$users         = [11 => false];

        $outcome = $this->runHandler();

        self::assertInstanceOf(BccScanAdminRedirected::class, $outcome);
        self::assertSame(DiscoveryRunError::OPERATOR_UNRESOLVED, $outcome->args['bcc_reason'] ?? null);
    }

    // ── bounded output ──────────────────────────────────────────────────

    /**
     * ⚠ Only CLOSED vocabulary reaches the browser.
     *
     * A provider body, an exception message, a credentialed URL or a token
     * must never reach the address bar. Every reason emitted is checked
     * against `DiscoveryRunError`, and anything else is replaced.
     */
    public function testOnlyBoundedReasonCodesReachTheBrowser(): void
    {
        $this->arrangeValidRequest();
        BccScanAdminState::$currentUserId = 0;

        $outcome = $this->runHandler();
        self::assertInstanceOf(BccScanAdminRedirected::class, $outcome);

        $reason = (string) ($outcome->args['bcc_reason'] ?? '');
        self::assertTrue(
            DiscoveryRunError::isValid($reason),
            "'{$reason}' is not in the closed error vocabulary"
        );

        foreach ($outcome->args as $key => $value) {
            $text = (string) $value;
            foreach (['http://', 'https://', 'Bearer ', 'api_key', 'password', 'Exception'] as $leak) {
                self::assertStringNotContainsStringIgnoringCase(
                    $leak,
                    $text,
                    "query arg {$key} must not carry '{$leak}'"
                );
            }
        }
    }

    /**
     * The bounded-reason filter, tested directly.
     *
     * ⚠ A mutation control that deleted the `isValid()` check SURVIVED,
     * because the service only ever emits bounded codes and the defensive
     * branch is unreachable through `handle()`. An unreachable guard is one
     * nobody notices breaking, so the filter is now a pure public helper and
     * this exercises it head-on.
     */
    public function testAnUnboundedReasonIsReplacedNotEchoed(): void
    {
        foreach ([
            'Fatal error: SQLSTATE[HY000] at /var/www/secret.php',
            'https://api.example.test/?api_key=SUPERSECRET',
            'Bearer eyJhbGciOi',
            'some_made_up_code',
        ] as $unbounded) {
            $out = DiscoveryScanActions::boundedReason($unbounded);

            self::assertSame(DiscoveryRunError::UNSUPPORTED_REQUEST, $out);
            self::assertNotSame($unbounded, $out);
            self::assertTrue(DiscoveryRunError::isValid($out));
        }
    }

    public function testABoundedReasonPassesThroughUnchanged(): void
    {
        self::assertSame(
            DiscoveryRunError::DISCOVERY_DISABLED,
            DiscoveryScanActions::boundedReason(DiscoveryRunError::DISCOVERY_DISABLED)
        );
        self::assertSame('', DiscoveryScanActions::boundedReason(''));
    }

    // ── ⚠ no provider call from an admin POST ───────────────────────────

    public function testTheRequestHandlerContactsNoProvider(): void
    {
        $this->arrangeValidRequest();
        BccScanAdminState::$currentUserId = 0; // refuse early, but still assert

        $this->runHandler();

        self::assertSame([], BccScanAdminState::$httpCalls, 'an admin POST must not call a provider');
    }

    /**
     * Structural, because a behavioural test only shows it did not happen
     * this time. The handler must contain no transport call at all — the
     * executor does the scanning, later and out of band.
     */
    public function testTheHandlerHasNoTransportCode(): void
    {
        $reflection = new \ReflectionClass(DiscoveryScanActions::class);
        $source     = (string) file_get_contents((string) $reflection->getFileName());

        $codeOnly = '';
        foreach (token_get_all($source) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= is_array($tok) ? $tok[1] : $tok;
        }

        foreach ([
            'wp_remote_get', 'wp_remote_post', 'curl_', 'file_get_contents(',
            'fsockopen', 'CosmosFetcher', 'EvmFetcher', 'SolanaFetcher',
            'CosmwasmDiscoveryService', 'DiscoveryRunExecutor',
        ] as $banned) {
            self::assertStringNotContainsString(
                $banned,
                $codeOnly,
                "the admin handler must not reference '{$banned}'"
            );
        }
    }

    // ── ⚠ the server chooses the scan mode ──────────────────────────────

    /**
     * The browser has no say. A `scan_mode` form field would make a full
     * historical backfill reachable from a POST body, which is exactly what
     * PR 7A's CLI documents at length that it must not be.
     */
    public function testTheHandlerNeverReadsAScanModeFromTheRequest(): void
    {
        $reflection = new \ReflectionClass(DiscoveryScanActions::class);
        $source     = (string) file_get_contents((string) $reflection->getFileName());

        $codeOnly = '';
        foreach (token_get_all($source) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= is_array($tok) ? $tok[1] : $tok;
        }

        foreach (['scan_mode', 'HISTORICAL', 'INCREMENTAL', 'forceScanMode'] as $banned) {
            self::assertStringNotContainsString(
                $banned,
                $codeOnly,
                "the server picks the mode; '{$banned}' must not appear in the handler"
            );
        }
    }

    // ── registration ────────────────────────────────────────────────────

    public function testAllThreeActionsAreDistinct(): void
    {
        $actions = [
            DiscoveryScanActions::ACTION_REQUEST,
            DiscoveryScanActions::ACTION_RETRY,
            DiscoveryScanActions::ACTION_CANCEL,
        ];

        self::assertSame($actions, array_values(array_unique($actions)));
    }

    /**
     * The actions must be reachable from the plugin bootstrap, or the buttons
     * post into nothing.
     */
    public function testTheActionsAreRegisteredFromTheBootstrap(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');

        self::assertStringContainsString('DiscoveryScanActions::register()', $bootstrap);
    }
}
