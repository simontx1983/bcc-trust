<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainsPage;
use BCC\Trust\Onchain\Services\CosmosEndpointTransition;
use BCC\Trust\Onchain\Support\CosmwasmScanEligibility;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;
use BCC\Trust\Onchain\ValueObjects\ProviderFailureKind;
use BCC\Trust\Onchain\ValueObjects\ProviderRequestClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: a new provider never inherits the previous one's story.
 *
 * ── WHY THIS IS NOT COSMETIC ────────────────────────────────────────────
 * `recordFailure()` deliberately CARRIES FORWARD the last attribution when a
 * charge cannot name itself — eight of the twelve charge sites are domain
 * judgements with no HTTP status, and without the carry-forward one of them
 * arriving late would erase the only explanation an operator had. That is
 * right within one endpoint and WRONG across a switch: the first failure
 * after a provider change would otherwise be described using the old
 * provider's kind and request class, and the admin page would render
 * "Provider server error" against a host that had never served a request.
 *
 * The fingerprint is what tells those two situations apart.
 */
#[CoversClass(OnchainCircuitBreaker::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EndpointScopedBreakerTest extends TestCase
{
    private const CHAIN = 94;

    private string $oldFp = '';
    private string $newFp = '';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/enumeration-real-transport-stubs.php';
        \BccBreakerStore::reset();

        $this->oldFp = (string) CosmosEndpointPolicy::fingerprint('cosmos', 'https://rest.cosmos.directory/cosmoshub');
        $this->newFp = (string) CosmosEndpointPolicy::fingerprint('cosmos', 'https://cosmos-api.polkachu.com');
        self::assertNotSame('', $this->oldFp);
        self::assertNotSame($this->oldFp, $this->newFp);
    }

    /** Within ONE endpoint, an unnamed charge still inherits the story. */
    public function testAttributionIsCarriedForwardWithinTheSameEndpoint(): void
    {
        OnchainCircuitBreaker::recordFailure(
            self::CHAIN,
            ProviderFailureKind::HTTP_5XX,
            ProviderRequestClass::STANDARD_REQUEST,
            $this->oldFp
        );
        // A domain-level charge: no kind, no class, same endpoint.
        OnchainCircuitBreaker::recordFailure(self::CHAIN, null, null, $this->oldFp);

        $a = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertSame(ProviderFailureKind::HTTP_5XX, $a['kind']);
        self::assertSame(ProviderRequestClass::STANDARD_REQUEST, $a['request_class']);
    }

    /** Across a switch, it is NOT. */
    public function testAForeignEndpointDoesNotInheritTheOldStory(): void
    {
        OnchainCircuitBreaker::recordFailure(
            self::CHAIN,
            ProviderFailureKind::HTTP_5XX,
            ProviderRequestClass::STANDARD_REQUEST,
            $this->oldFp
        );
        // First charge on the NEW endpoint, unable to name itself.
        OnchainCircuitBreaker::recordFailure(self::CHAIN, null, null, $this->newFp);

        $a = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertNull($a['kind'], 'the new provider inherited the old one\'s failure kind');
        self::assertNull($a['request_class']);
        self::assertSame($this->newFp, $a['endpoint_fp']);
    }

    /** A reader that knows the current endpoint is told the state is stale. */
    public function testAttributionIsWithheldWhenTheStoredEndpointDiffers(): void
    {
        OnchainCircuitBreaker::recordFailure(
            self::CHAIN,
            ProviderFailureKind::TRANSPORT,
            ProviderRequestClass::STANDARD_REQUEST,
            $this->oldFp
        );

        $sameEndpoint = OnchainCircuitBreaker::attribution(self::CHAIN, $this->oldFp);
        self::assertSame(ProviderFailureKind::TRANSPORT, $sameEndpoint['kind']);
        self::assertFalse($sameEndpoint['stale']);

        $afterSwitch = OnchainCircuitBreaker::attribution(self::CHAIN, $this->newFp);
        self::assertNull($afterSwitch['kind'], 'stale attribution was rendered against the new endpoint');
        self::assertNull($afterSwitch['request_class']);
        self::assertTrue($afterSwitch['stale']);
    }

    /** No expectation supplied ⇒ previous behaviour, unchanged. */
    public function testLegacyCallersSeeExactlyWhatTheyDidBefore(): void
    {
        OnchainCircuitBreaker::recordFailure(self::CHAIN, ProviderFailureKind::RATE_LIMITED, ProviderRequestClass::SMART_QUERY);

        $a = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertSame(ProviderFailureKind::RATE_LIMITED, $a['kind']);
        self::assertSame(ProviderRequestClass::SMART_QUERY, $a['request_class']);
        self::assertNull($a['endpoint_fp'], 'a charge with no endpoint must not invent one');
        self::assertFalse($a['stale']);
    }

    /** A junk fingerprint is dropped, never stored and never matched. */
    public function testJunkFingerprintsAreRefused(): void
    {
        OnchainCircuitBreaker::recordFailure(
            self::CHAIN,
            ProviderFailureKind::HTTP_5XX,
            ProviderRequestClass::STANDARD_REQUEST,
            'not-a-fingerprint'
        );

        self::assertNull(OnchainCircuitBreaker::attribution(self::CHAIN)['endpoint_fp']);
    }

    /** Success clears the marker with everything else. */
    public function testRecordSuccessClearsTheFingerprint(): void
    {
        OnchainCircuitBreaker::recordFailure(
            self::CHAIN,
            ProviderFailureKind::HTTP_5XX,
            ProviderRequestClass::STANDARD_REQUEST,
            $this->oldFp
        );
        OnchainCircuitBreaker::recordSuccess(self::CHAIN);

        $a = OnchainCircuitBreaker::attribution(self::CHAIN);
        self::assertNull($a['kind']);
        self::assertNull($a['endpoint_fp']);
    }

    // ── the transition plan digest ──────────────────────────────────────

    /**
     * ⚠ THE CODE IDS ARE IN THE DIGEST, NOT JUST THEIR COUNT. Two families
     * finishing while two others stall leaves the count at two, so a
     * confirmation that only re-checked the count would clear rows nobody
     * reviewed.
     */
    public function testTheDigestIsSensitiveToWhichRowsNotJustHowMany(): void
    {
        $base = CosmosEndpointTransition::digest(8, 'https://a.test', 'https://b.test', [52, 77], false);

        self::assertSame($base, CosmosEndpointTransition::digest(8, 'https://a.test', 'https://b.test', [77, 52], false), 'order must not matter');
        self::assertNotSame($base, CosmosEndpointTransition::digest(8, 'https://a.test', 'https://b.test', [52, 78], false), 'a different family must change the digest');
        self::assertNotSame($base, CosmosEndpointTransition::digest(8, 'https://a.test', 'https://b.test', [52], false));
        self::assertNotSame($base, CosmosEndpointTransition::digest(8, 'https://a.test', 'https://b.test', [52, 77], true), 'the code cursor is part of the plan');
        self::assertNotSame($base, CosmosEndpointTransition::digest(9, 'https://a.test', 'https://b.test', [52, 77], false));
        self::assertNotSame($base, CosmosEndpointTransition::digest(8, 'https://a.test', 'https://c.test', [52, 77], false));
    }

    // ── the refusal verdict ─────────────────────────────────────────────

    /** @return array<string, array{bool|null, string}> */
    public static function verifications(): array
    {
        return [
            'verified'      => [true,  CosmwasmScanEligibility::ELIGIBLE],
            'not governed'  => [null,  CosmwasmScanEligibility::ELIGIBLE],
            'unverified'    => [false, CosmwasmScanEligibility::ENDPOINT_UNVERIFIED],
        ];
    }

    #[DataProvider('verifications')]
    public function testAnUnverifiedEndpointRefusesTheScan(?bool $verified, string $expected): void
    {
        self::assertSame(
            $expected,
            CosmwasmScanEligibility::verdict(8, 'backfilled', true, null, $verified)
        );
    }

    public function testEndpointUnverifiedIsNotScannable(): void
    {
        self::assertFalse(CosmwasmScanEligibility::isScannable(CosmwasmScanEligibility::ENDPOINT_UNVERIFIED));
        self::assertTrue(CosmwasmScanEligibility::isScannable(CosmwasmScanEligibility::ELIGIBLE));
    }

    /**
     * More actionable answers win. An operator who has not opted in should be
     * told that, not sent chasing an endpoint.
     */
    public function testMoreActionableRefusalsTakePrecedence(): void
    {
        self::assertSame(
            CosmwasmScanEligibility::NOT_OPTED_IN,
            CosmwasmScanEligibility::verdict(8, 'backfilled', false, null, false)
        );
        self::assertSame(
            CosmwasmScanEligibility::ALLOWLIST_EXCLUDED,
            CosmwasmScanEligibility::verdict(8, 'backfilled', true, [99], false)
        );
    }

    // ── the switch is a button, not a migration ─────────────────────────

    /**
     * ⚠ PINS THE ABSENCE OF AN AUTOMATIC PATH. The endpoint may move only
     * through an admin-post action; if anyone later adds a migration or cron
     * that repoints a chain, this is the test that should stop them.
     */
    public function testTheOnlySwitchPathIsAnAdminPostAction(): void
    {
        self::assertSame('bcc_chain_endpoint_switch', ChainsPage::ACTION_ENDPOINT_SWITCH);
        self::assertTrue(method_exists(ChainsPage::class, 'handle_endpoint_switch'));

        $tree = dirname(__DIR__, 2) . '/app';
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tree)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            if (strpos($body, 'updateRestUrl') !== false) {
                $hits[] = basename($file->getPathname());
            }
        }
        sort($hits);

        // The repository that defines it, and the one service allowed to call
        // it. Anything else appearing here is a new way to move an endpoint.
        self::assertSame(
            ['ChainRepository.php', 'CosmosEndpointTransition.php'],
            $hits,
            'a new caller of updateRestUrl() appeared — is it audited and operator-driven?'
        );
    }
}
