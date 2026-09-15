<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Services\DiscoveryRunService;
use BCC\Trust\Onchain\Support\CosmosEndpointAuthorization;
use BCC\Trust\Onchain\Support\CosmwasmScanEligibility;
use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: `endpoint_unverified` is reached from the workflow an
 * administrator actually uses, and the live check that produces it happens
 * ONLY there.
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────
 * {@see CosmwasmScanEligibility::ENDPOINT_UNVERIFIED} shipped with a
 * carefully-written constant, a docblock explaining that it is a refusal
 * rather than a warning, and a fifth `verdict()` argument to produce it. All
 * four production callers passed four arguments. The branch was unreachable:
 * a protection that could not fire, in a file that described it firing.
 *
 * ── THE TWO HALVES, WHICH ARE EASY TO CONFUSE ───────────────────────────
 * PROVING an endpoint needs the network. CHECKING whether it was proven must
 * not. If those were one operation, the protection would put outbound
 * requests on every admin render, every status page, every five-minute
 * maintenance tick and every worker chunk — turning a safety check into
 * exactly the unattended traffic the manual-only discovery rule exists to
 * prevent.
 *
 * So: an explicit administrator action proves it once and records the proven
 * FINGERPRINT; everything else compares that record. Both halves are pinned
 * here, and the "must not verify" ones are asserted against a recording HTTP
 * client — `SafeHttpClient::$calls` staying empty is the proof, not a reading
 * of the source.
 */
#[CoversClass(CosmosEndpointAuthorization::class)]
#[CoversClass(DiscoveryRunService::class)]
#[CoversClass(CosmwasmScanEligibility::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EndpointVerificationWorkflowTest extends TestCase
{
    private const CHAIN    = 8;
    private const OPERATOR = 7;

    private const GOVERNED  = 'cosmos';
    private const APPROVED  = 'https://cosmos-api.polkachu.com';
    private const INCUMBENT = 'https://rest.cosmos.directory/cosmoshub';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/discovery-run-stubs.php';

        \BccDiscoveryTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Core\Security\TransactionManager::reset();
        ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::reset();
        DiscoveryRunRepository::reset();
        \BccTestOptionStore::reset();
        \BccTestEndpointProof::reset();

        \BccDiscoveryTestState::seedAdmin(self::OPERATOR);

        // Both master switches on, so nothing else can be the blocker.
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function seedGoverned(string $restUrl): void
    {
        ChainRepository::seed(self::CHAIN, self::GOVERNED, 'cosmos', 1, 1, 1, $restUrl);
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return (new DiscoveryRunService())->request(self::CHAIN, self::OPERATOR);
    }

    private function httpCalls(): int
    {
        return count(\BCC\Core\Http\SafeHttpClient::$calls);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. THE REFUSAL IS REACHABLE — AND NAMED
    // ═══════════════════════════════════════════════════════════════════

    /**
     * THE HEADLINE. A governed chain whose endpoint cannot be proven is
     * refused with its OWN reason, and no run row is created.
     *
     * ⚠ `endpoint_unverified`, not `discovery_disabled`. The readiness
     * composer maps unrecognised verdicts to the latter, which is a sentence
     * about a global switch — it would send an operator to wp-config to fix
     * an endpoint. Naming the actual blocker is the whole purpose of the
     * vocabulary.
     */
    public function testAnUnprovenEndpointRefusesTheScanRequestByName(): void
    {
        $this->seedGoverned(self::APPROVED);
        \BccTestEndpointProof::scriptUnreachable();

        $result = $this->request();

        self::assertFalse($result['ok']);
        self::assertSame(CosmwasmScanEligibility::ENDPOINT_UNVERIFIED, $result['reason']);
        self::assertSame([], DiscoveryRunRepository::$rows, 'a refusal creates no run');
        self::assertSame([], \BccDiscoveryTestState::$dispatched, 'and dispatches nothing');
    }

    /** An endpoint the policy does not approve is refused without a probe. */
    public function testAnUnapprovedEndpointRefusesWithoutContactingAnything(): void
    {
        $this->seedGoverned('https://lcd.example');
        \BccTestEndpointProof::scriptNodeInfo();

        $result = $this->request();

        self::assertSame(CosmwasmScanEligibility::ENDPOINT_UNVERIFIED, $result['reason']);
        self::assertSame(0, $this->httpCalls(), 'the policy answers this without asking the host');
    }

    /** A governed chain with NO endpoint configured is refused too. */
    public function testAnAbsentEndpointRefusesTheScanRequest(): void
    {
        $this->seedGoverned('');

        $result = $this->request();

        self::assertSame(CosmwasmScanEligibility::ENDPOINT_UNVERIFIED, $result['reason']);
        self::assertSame([], DiscoveryRunRepository::$rows);
    }

    /** The endpoint reports a different network: refused. */
    public function testTheWrongNetworkRefusesTheScanRequest(): void
    {
        $this->seedGoverned(self::APPROVED);
        \BccTestEndpointProof::scriptNodeInfo('osmosis-1');

        $result = $this->request();

        self::assertSame(CosmwasmScanEligibility::ENDPOINT_UNVERIFIED, $result['reason']);
        self::assertSame([], \BccTestOptionStore::$options, 'a failed proof records nothing');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. A PROVEN ENDPOINT PROCEEDS — AND IS RECORDED
    // ═══════════════════════════════════════════════════════════════════
    //
    //  Anti-vacuity: a gate that refused everything would satisfy §1 in full.

    public function testAProvenEndpointLetsTheRequestThroughAndRecordsTheFingerprint(): void
    {
        $this->seedGoverned(self::APPROVED);
        \BccTestEndpointProof::scriptNodeInfo();

        $result = $this->request();

        self::assertTrue($result['ok'], 'a proven endpoint is not a blocker');
        self::assertNotSame([], DiscoveryRunRepository::$rows, 'the run an administrator asked for exists');

        self::assertSame(
            CosmosEndpointPolicy::fingerprint(self::GOVERNED, self::APPROVED),
            CosmosEndpointAuthorization::storedFingerprint(self::CHAIN),
            'the proven identity is what was recorded'
        );
    }

    /** Production's incumbent endpoint proves out the same way. */
    public function testTheIncumbentEndpointAlsoProvesOut(): void
    {
        $this->seedGoverned(self::INCUMBENT);
        \BccTestEndpointProof::scriptNodeInfo();

        self::assertTrue($this->request()['ok']);
        self::assertSame(
            CosmosEndpointPolicy::fingerprint(self::GOVERNED, self::INCUMBENT),
            CosmosEndpointAuthorization::storedFingerprint(self::CHAIN)
        );
    }

    /**
     * ⚠ THE PROBE IS ASKED SECOND. A chain blocked for any OTHER reason is
     * refused before anything is contacted — proving the endpoint of a chain
     * that cannot scan anyway spends a provider request to learn nothing.
     */
    public function testAChainBlockedForAnotherReasonIsNeverProbed(): void
    {
        // Opted OUT. Everything else is fine, including the endpoint.
        ChainRepository::seed(self::CHAIN, self::GOVERNED, 'cosmos', 1, 0, 1, self::APPROVED);
        \BccTestEndpointProof::scriptNodeInfo();

        $result = $this->request();

        self::assertSame(CosmwasmScanEligibility::NOT_OPTED_IN, $result['reason']);
        self::assertSame(0, $this->httpCalls(), 'no request for a chain that cannot scan anyway');
    }

    /**
     * And an ALREADY-PROVEN endpoint costs nothing either: the record still
     * matches the configured endpoint, so readiness never asks for a probe.
     */
    public function testAnAlreadyProvenEndpointIsNotReProbed(): void
    {
        $this->seedGoverned(self::APPROVED);
        \BccTestEndpointProof::approve(self::CHAIN, self::GOVERNED, self::APPROVED);

        self::assertTrue($this->request()['ok']);
        self::assertSame(0, $this->httpCalls(), 'the record answered; nothing was contacted');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. THE RECORD IS BOUND TO THE ENDPOINT, NOT TO THE CHAIN
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠ THE PROPERTY THAT MAKES THE RECORD SAFE TO KEEP WITHOUT AN EXPIRY.
     * Repointing the chain — by the audited switch, by a migration, or by
     * hand in the database — changes the configured fingerprint. The stored
     * one no longer matches it, so the chain is unverified again by
     * construction rather than because somebody remembered to invalidate a
     * flag.
     */
    public function testRepointingTheChainInvalidatesTheProof(): void
    {
        $this->seedGoverned(self::APPROVED);
        \BccTestEndpointProof::approve(self::CHAIN, self::GOVERNED, self::APPROVED);

        self::assertTrue(CosmosEndpointAuthorization::isAuthorized(ChainRepository::getById(self::CHAIN)));

        // Repointed to the OTHER approved endpoint. Still approved, still
        // governed — and not the one anybody proved.
        $this->seedGoverned(self::INCUMBENT);

        self::assertFalse(
            CosmosEndpointAuthorization::isAuthorized(ChainRepository::getById(self::CHAIN)),
            'a proof of one endpoint says nothing about another'
        );
    }

    /**
     * A recorded proof cannot rescue an endpoint the policy refuses.
     * `isAuthorized()` re-asks the policy on every read, so a record written
     * before an endpoint fell out of the allowlist stops counting at once.
     */
    public function testARecordedProofCannotAuthorizeAnUnapprovedEndpoint(): void
    {
        $this->seedGoverned(self::APPROVED);
        \BccTestEndpointProof::approve(self::CHAIN, self::GOVERNED, self::APPROVED);

        // The chain is repointed at a host nobody approved, while the record
        // sits there from before.
        $this->seedGoverned('https://cosmos-rest.publicnode.com');

        self::assertFalse(CosmosEndpointAuthorization::isAuthorized(ChainRepository::getById(self::CHAIN)));
    }

    /** A corrupt or hand-edited record is treated as absent, never as a match. */
    public function testAMalformedRecordIsNotAMatch(): void
    {
        $this->seedGoverned(self::APPROVED);

        foreach ([
            'not a fingerprint',
            '',
            'ZZZZZZZZZZZZZZZZ',
            '5ffe78dbfc84417',      // 15 chars
            '5ffe78dbfc84417bb',    // 17 chars
        ] as $junk) {
            \BccTestOptionStore::$options['bcc_cosmos_endpoint_authz_' . self::CHAIN] = [
                'fingerprint' => $junk,
            ];

            self::assertNull(CosmosEndpointAuthorization::storedFingerprint(self::CHAIN), $junk);
            self::assertFalse(CosmosEndpointAuthorization::isAuthorized(ChainRepository::getById(self::CHAIN)));
        }
    }

    /** A record for one chain is not a record for another. */
    public function testARecordIsScopedToItsChain(): void
    {
        $this->seedGoverned(self::APPROVED);
        \BccTestEndpointProof::approve(self::CHAIN, self::GOVERNED, self::APPROVED);

        $otherChain = (object) [
            'id'       => 99,
            'slug'     => self::GOVERNED,
            'rest_url' => self::APPROVED,
        ];

        self::assertFalse(CosmosEndpointAuthorization::isAuthorized($otherChain));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. UNGOVERNED CHAINS ARE OUTSIDE ALL OF THIS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * `null`, not `false`. The tri-state is the whole reason eight other
     * Cosmos chains keep working: "not governed" leaves `verdict()` exactly
     * where it was, whereas `false` would refuse every one of them.
     */
    public function testAnUngovernedChainIsNeitherAuthorizedNorRefused(): void
    {
        $chain = (object) ['id' => 12, 'slug' => 'osmosis', 'rest_url' => 'https://lcd.example'];

        self::assertNull(CosmosEndpointAuthorization::isAuthorized($chain));
    }

    public function testAnUngovernedChainScansWithoutAnyProof(): void
    {
        ChainRepository::seed(self::CHAIN, 'dungeon', 'cosmos', 1, 1, 1, 'https://lcd.example');

        self::assertTrue($this->request()['ok']);
        self::assertSame(0, $this->httpCalls(), 'nothing to prove, so nothing contacted');
        self::assertSame([], \BccTestOptionStore::$options, 'and nothing recorded');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  5. THE CHECK ITSELF NEVER CONTACTS ANYTHING
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠ THE PROPERTY EVERY NON-ADMIN SURFACE DEPENDS ON. `isAuthorized()` is
     * called from page renders, the health snapshot, the worker's chain
     * filter and the executor's per-chunk re-check. If it could reach the
     * network, each of those would become an unattended outbound request —
     * on a timer, in the maintenance sweep's case.
     *
     * Asserted over every branch, including the failing ones, because it is
     * the FAILURE path that would be tempted to "just check and see".
     */
    public function testCheckingAProofNeverMakesARequestOrAWrite(): void
    {
        foreach ([self::APPROVED, self::INCUMBENT, 'https://lcd.example', ''] as $url) {
            \BccTestOptionStore::reset();
            \BCC\Core\Http\SafeHttpClient::$calls = [];
            $this->seedGoverned($url);

            CosmosEndpointAuthorization::isAuthorized(ChainRepository::getById(self::CHAIN));
            CosmosEndpointAuthorization::storedFingerprint(self::CHAIN);

            self::assertSame(0, $this->httpCalls(), 'no request for ' . ($url ?: '(empty)'));
            self::assertSame([], \BccTestOptionStore::$options, 'and no write for ' . ($url ?: '(empty)'));
        }
    }

    /**
     * ⚠ A FAILED PROOF IS NEVER CACHED AS A SUCCESS, and never clears an
     * existing one either. A momentary outage must not be able to revoke an
     * authorization and strand a run that is legitimately in flight.
     */
    public function testAFailedProofNeitherRecordsNorRevokes(): void
    {
        $this->seedGoverned(self::APPROVED);
        \BccTestEndpointProof::approve(self::CHAIN, self::GOVERNED, self::APPROVED);
        $before = CosmosEndpointAuthorization::storedFingerprint(self::CHAIN);

        \BccTestEndpointProof::scriptUnreachable();
        CosmosEndpointAuthorization::authorize(ChainRepository::getById(self::CHAIN), self::OPERATOR);

        self::assertSame(
            $before,
            CosmosEndpointAuthorization::storedFingerprint(self::CHAIN),
            'an outage does not rewrite a proof in either direction'
        );
    }

    /** Withdrawing the chain's opt-in is what withdraws the proof. */
    public function testForgetDropsTheRecord(): void
    {
        $this->seedGoverned(self::APPROVED);
        \BccTestEndpointProof::approve(self::CHAIN, self::GOVERNED, self::APPROVED);

        CosmosEndpointAuthorization::forget(self::CHAIN);

        self::assertNull(CosmosEndpointAuthorization::storedFingerprint(self::CHAIN));
        self::assertFalse(CosmosEndpointAuthorization::isAuthorized(ChainRepository::getById(self::CHAIN)));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  6. THE PROBE ITSELF IS BOUNDED
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ONE request, to the node_info path, with redirects left at the client's
     * default of zero.
     *
     * ⚠ NOT THROUGH ApiRetry. Routing a GATE through the retry stack would
     * retry it three times and charge the breaker four times per attempt —
     * so merely asking whether we may proceed could open the breaker and
     * block the traffic it was protecting.
     */
    public function testTheProofIsExactlyOneUnretriedRequest(): void
    {
        $this->seedGoverned(self::APPROVED);
        \BccTestEndpointProof::scriptNodeInfo();

        $this->request();

        self::assertSame(1, $this->httpCalls(), 'one request, not a retry loop');

        $call = \BCC\Core\Http\SafeHttpClient::$calls[0];
        self::assertStringStartsWith(self::APPROVED, $call['url']);
        self::assertStringEndsWith('/cosmos/base/tendermint/v1beta1/node_info', $call['url']);
        self::assertArrayNotHasKey(
            'redirection',
            $call['args'],
            'the client defaults redirects to zero and this caller must not override it'
        );
    }
}
