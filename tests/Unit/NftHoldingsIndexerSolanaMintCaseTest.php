<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\NftHoldingsIndexer;
use BCC\Trust\Onchain\Support\NftCollectionIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: the holdings write path stores a Solana mint byte-exact.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 * `planBatch()` did `strtolower($e['contract_address'])`. On EVM and Cosmos
 * that agrees with the canonical rule, so nobody noticed. On SOLANA it does
 * not: `contract_address` carries the MINT, base58 is case-sensitive, and a
 * folded mint is a DIFFERENT key that no longer names the asset.
 *
 * `NftCollectionIdentifier` already said so in as many words — "On Solana it
 * does not [agree] … a folded comparison can never match what Solana DAS
 * actually returns". The write path simply was not using it.
 *
 * ⚠ WHAT THIS WAS AND WAS NOT. The fold was applied on BOTH read and write,
 * so holdings lookups stayed internally consistent and nothing was observably
 * broken. The damage is information loss in the canonical column: the stored
 * value is not the mint, so anything comparing byte-exact (a binary collation,
 * an export, a provider round-trip) cannot match it.
 *
 * ── THE RECOVERY EVIDENCE, WHICH THIS TEST ALSO PINS ────────────────────
 * `SolanaFetcher::normalizeWebhookPayload()` puts the mint in BOTH
 * `contract_address` and `token_id`, and only the former was folded. So
 * `token_id` holds the original case on the very same row. That is why
 * recovery needs no provider call, and it is asserted here so a future change
 * cannot quietly start folding `token_id` too and destroy the evidence.
 */
#[CoversClass(NftHoldingsIndexer::class)]
final class NftHoldingsIndexerSolanaMintCaseTest extends TestCase
{
    private const CHAIN = 20;

    /** A real-shaped base58 mint with BOTH cases — folding it changes it. */
    private const MINT = 'So11111111111111111111111111111111111111112';

    private const WALLET = 'HN7cABqLq46Es1jh92dQQpjvZRjJhVTrfL1bHXQ3kmYu';
    private const LINK   = 10;

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function ev(array $overrides = []): array
    {
        return array_merge([
            'chain_id'         => self::CHAIN,
            'contract_address' => self::MINT,
            'token_id'         => self::MINT,
            'token_standard'   => 'SPL-NFT',
            'from_address'     => '',
            'to_address'       => self::WALLET,
            'amount'           => 1,
            'block_number'     => 100,
            'confirmed_at'     => '2026-01-01 00:00:00',
            'collection_name'  => null,
        ], $overrides);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{upserts: list<mixed>, deletes: list<mixed>, deltas: list<mixed>, skipped: int, spam_filtered: int}
     */
    private function plan(array $events, string $family = 'solana'): array
    {
        return NftHoldingsIndexer::planBatch(
            self::CHAIN,
            $events,
            [strtolower(self::WALLET) => self::LINK],
            static fn (string $c, ?string $n): bool => false,
            $family
        );
    }

    // ── 0. anti-vacuity ─────────────────────────────────────────────────

    public function testTheFixtureMintWouldActuallyBeChangedByFolding(): void
    {
        self::assertNotSame(
            self::MINT,
            strtolower(self::MINT),
            'a fixture mint that is already lowercase would make every assertion vacuous'
        );
    }

    public function testTheCanonicalRuleForSolanaIsByteExact(): void
    {
        $identity = NftCollectionIdentifier::canonicalize('solana', self::MINT);

        self::assertTrue($identity->isAccepted());
        self::assertSame(
            self::MINT,
            $identity->canonical(),
            'the canonical form of a Solana mint is the encoded text, byte for byte'
        );
    }

    // ── 1. the write path keeps the case ────────────────────────────────

    public function testAnInflowStoresTheMintByteExact(): void
    {
        $out = $this->plan([$this->ev()]);

        self::assertCount(1, $out['upserts'], 'the event was planned, not skipped');
        self::assertSame(self::MINT, $out['upserts'][0]['contract_address']);
        self::assertNotSame(
            strtolower(self::MINT),
            $out['upserts'][0]['contract_address'],
            'the folded form must NOT be what gets stored'
        );
    }

    public function testTheTokenIdStillCarriesTheOriginalCase(): void
    {
        $out = $this->plan([$this->ev()]);

        self::assertSame(
            self::MINT,
            $out['upserts'][0]['token_id'],
            'token_id is the recovery evidence for rows already written folded — '
            . 'if this ever folds, that evidence is destroyed'
        );
    }

    public function testAnOutflowDeletesUnderTheByteExactMint(): void
    {
        $out = $this->plan([
            $this->ev(['from_address' => self::WALLET, 'to_address' => '']),
        ]);

        self::assertCount(1, $out['deletes']);
        // ⚠ The delete row's key is `contract`, not `contract_address` —
        // the two plan shapes genuinely differ (see the DeleteRow and
        // UpsertRow phpstan-types).
        self::assertSame(self::MINT, $out['deletes'][0]['contract']);
    }

    // ── 2. EVM and Cosmos are unchanged ─────────────────────────────────

    public function testAnEvmContractIsStillLowercased(): void
    {
        $mixed = '0xAbCdEf0123456789AbCdEf0123456789AbCdEf01';

        $out = $this->plan([
            $this->ev([
                'contract_address' => $mixed,
                'token_id'         => '1',
                'token_standard'   => 'ERC-721',
            ]),
        ], 'evm');

        self::assertCount(1, $out['upserts']);
        self::assertSame(
            strtolower($mixed),
            $out['upserts'][0]['contract_address'],
            'EVM canonical form is lowercase — this change must not alter that'
        );
    }

    // ── 3. fail closed, never under a guessed key ───────────────────────

    public function testAnUnknownFamilyIsSkippedRatherThanStoredFolded(): void
    {
        $out = $this->plan([$this->ev()], 'dogecoin');

        self::assertSame([], $out['upserts'], 'nothing may be written under a guessed identity');
        self::assertSame([], $out['deltas']);
        self::assertSame(1, $out['skipped'], 'and the event is accounted for as skipped');
    }

    public function testAnUnreadableChainFamilyIsSkipped(): void
    {
        $out = $this->plan([$this->ev()], '');

        self::assertSame([], $out['upserts']);
        self::assertSame(1, $out['skipped']);
    }

    public function testAMalformedMintIsSkipped(): void
    {
        // Contains '0', 'O', 'I' and 'l' — excluded from the base58 alphabet.
        $out = $this->plan([$this->ev(['contract_address' => '0OIl0OIl0OIl0OIl0OIl0OIl0OIl0OIl'])]);

        self::assertSame([], $out['upserts']);
        self::assertSame(1, $out['skipped']);
    }
}
