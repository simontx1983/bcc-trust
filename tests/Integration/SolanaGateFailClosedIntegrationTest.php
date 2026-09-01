<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Log\Logger;
use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Repair\SolanaGateIdentityManifest;
use BCC\Trust\Onchain\ValueObjects\EligibilityVerdict;
use PHPUnit\Framework\TestCase;

/**
 * The defect itself, and the discrimination that fixes it.
 *
 * ── WHY THE PAIR OF TESTS IS THE POINT ──────────────────────────────────
 * "Returns null" is not evidence of anything on its own: a refused
 * identity and a dead provider both produce null. What matters is that the
 * system can TELL THEM APART, because they need opposite operator
 * responses — one clears itself, the other never will.
 *
 * So every test here runs against an RPC endpoint on loopback and asserts
 * on WHICH branch was taken, using the recording Logger. Same input shape,
 * same return value, different diagnosis.
 *
 * The unreachable endpoint is doing real work: it guarantees that a test
 * claiming "the identity was refused before any call" cannot be passing
 * merely because the network happened to be up, and — in the other
 * direction — that the valid-identity case really does reach the HTTP
 * layer rather than being refused earlier for some unrelated reason.
 */
final class SolanaGateFailClosedIntegrationTest extends TestCase
{
    /**
     * A loopback endpoint. bcc-core's SafeHttpClient refuses private and
     * reserved IPs in its SSRF guard, so a call that reaches the HTTP layer
     * fails immediately and deterministically — no socket, no timeout, no
     * dependence on the network being down. What matters for these tests is
     * only that a REAL call attempt is distinguishable from a refusal that
     * happened before any call.
     */
    private const DEAD_RPC = 'http://127.0.0.1:1';

    private const WALLET = 'DRiP2Pn2K6fuMLKQmt5rZWyHiUZ6WK3GChEySUpezyBS';

    protected function setUp(): void
    {
        parent::setUp();
        Logger::reset();
    }

    private function fetcher(): SolanaFetcher
    {
        // A chain row shaped like `wp_bcc_chains`, with its RPC pointed at a
        // closed port. NOTE the id/slug: nothing here hardcodes a numeric
        // chain id as "solana" — this is a local fixture, not a lookup.
        $chain = (object) [
            'id'           => 999,
            'slug'         => 'solana',
            'name'         => 'Solana',
            'chain_type'   => 'solana',
            'rpc_url'      => self::DEAD_RPC,
            'rest_url'     => null,
            'explorer_url' => null,
            'native_token' => 'SOL',
            'is_active'    => 1,
        ];

        return new SolanaFetcher($chain);
    }

    /** @return list<string> messages logged at any level */
    private function loggedMessages(): array
    {
        return array_map(
            static fn(array $line): string => (string) $line['message'],
            Logger::$lines
        );
    }

    private function refusalWasLogged(): bool
    {
        foreach ($this->loggedMessages() as $message) {
            if (str_contains($message, 'refused a non-canonical collection identifier')) {
                return true;
            }
        }

        return false;
    }

    // ── the original defect ─────────────────────────────────────────────

    /**
     * THE regression test.
     *
     * Before PR 5b, `count_holdings($wallet, 'bozosgroup')` walked the
     * wallet, compared a lower-cased alias against DAS collection mints,
     * matched nothing, and returned a REAL `0`. A real 0 is not "we could
     * not check" — `HoldingsService::eligibilityVerdict` reads it as
     * certainty and returns INELIGIBLE, and the revoke sweep treats
     * INELIGIBLE as grounds to evict a member.
     *
     * It must now return null (UNKNOWN) and say why.
     */
    public function testAnAliasNoLongerProducesARealZero(): void
    {
        $count = $this->fetcher()->count_holdings(self::WALLET, 'bozosgroup');

        self::assertNotSame(0, $count, 'an alias must never produce a real zero — that is the whole defect');
        self::assertNull($count, 'an unanswerable question must report UNKNOWN');
        self::assertTrue($this->refusalWasLogged(), 'the refusal must be diagnosable, not silent');
    }

    /**
     * Every one of the eight production aliases behaves the same way. The
     * defect was not specific to `bozosgroup`.
     */
    public function testEveryProductionAliasIsRefusedRatherThanCounted(): void
    {
        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            Logger::reset();

            $count = $this->fetcher()->count_holdings(self::WALLET, $entry['alias']);

            self::assertNull($count, "alias '{$entry['alias']}' produced a count");
            self::assertTrue($this->refusalWasLogged(), "alias '{$entry['alias']}' was not refused");
        }
    }

    /**
     * A case-folded mint is a DIFFERENT key, and folding was exactly what
     * the old code did to every identifier it touched.
     */
    public function testACaseFoldedMintIsRefusedRatherThanCounted(): void
    {
        $mint   = SolanaGateIdentityManifest::entries()[0]['new_canonical_identifier'];
        $folded = strtolower($mint);

        self::assertNotSame($mint, $folded, 'fixture must contain upper-case characters');

        Logger::reset();
        $count = $this->fetcher()->count_holdings(self::WALLET, $folded);

        // Folding either destroys the key outright, or yields a different
        // valid key. Both are wrong answers to the question that was asked;
        // neither may be a confident zero.
        self::assertNotSame(0, $count);
    }

    // ── the discrimination ──────────────────────────────────────────────

    /**
     * The other half of the pair: a VALID identity against a dead provider
     * must also return null, but WITHOUT the refusal — that is a genuine
     * provider failure, and it stays diagnosable as one.
     *
     * Without this test, the one above would pass just as well if the code
     * refused everything unconditionally.
     */
    public function testAValidMintAgainstADeadProviderIsAProviderFailureNotARefusal(): void
    {
        $mint = SolanaGateIdentityManifest::entries()[0]['new_canonical_identifier'];

        Logger::reset();
        $count = $this->fetcher()->count_holdings(self::WALLET, $mint);

        self::assertNull($count, 'an unreachable provider must report UNKNOWN');
        self::assertFalse(
            $this->refusalWasLogged(),
            'a valid identity must reach the provider — it must NOT be refused as non-canonical'
        );
    }

    // ── verdict semantics ───────────────────────────────────────────────

    /**
     * A genuine zero from a canonical query is still INELIGIBLE. The fix
     * must not turn every negative answer into UNKNOWN — that would break
     * the revoke sweep in the other direction, leaving non-holders in
     * gated communities forever.
     */
    public function testAGenuineZeroRemainsIneligible(): void
    {
        $verdict = EligibilityVerdict::ineligible(1, 0);

        self::assertTrue($verdict->isIneligible());
        self::assertFalse($verdict->isUnknown());
        self::assertSame('', $verdict->reason);
    }

    /**
     * The two UNKNOWN causes are distinguishable on the verdict itself.
     */
    public function testTheTwoUnknownCausesAreDistinguishable(): void
    {
        $providerDown = EligibilityVerdict::unknown(1, null);
        $unresolved   = EligibilityVerdict::identityUnresolved(1);

        self::assertTrue($providerDown->isUnknown());
        self::assertTrue($unresolved->isUnknown());

        self::assertSame('provider_unavailable', $providerDown->reason);
        self::assertSame('collection_identity_unresolved', $unresolved->reason);

        self::assertFalse($providerDown->isIdentityUnresolved());
        self::assertTrue($unresolved->isIdentityUnresolved());

        // An unresolved identity observed NOTHING, so it must not report a
        // balance — a 0 here would be the same lie in a new place.
        self::assertNull($unresolved->bestKnownBalance);
    }
}
