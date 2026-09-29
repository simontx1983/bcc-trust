<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\Validation\SolanaContractProbe;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\ContractValidationVerdict;
use BCC\Trust\Onchain\ValueObjects\IntakeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Solana targeted validation through DAS `getAsset`.
 *
 * ── THE THREE THINGS THAT MUST NOT HAPPEN ───────────────────────────────
 *  1. An UNVERIFIED collection grouping accepted. Anyone can write a
 *     blue-chip collection's address into their own asset's metadata; only
 *     the authority's `verified` flag makes the claim mean anything. Accepting
 *     an unverified grouping lets anyone gate a community on someone else's
 *     collection.
 *  2. The MINT ADDRESS stored as the collection name. `grouping[].group_value`
 *     is base58, not a name — and the existing `fetchMetadataForMint()`
 *     returns it under the key `collection_name`, which is exactly the trap.
 *  3. A COMPRESSED NFT accepted. A cNFT lives in a Merkle tree, so the balance
 *     reads holder-gating uses cannot prove ownership (DECISION 8).
 */
#[CoversClass(SolanaContractProbe::class)]
final class SolanaContractProbeTest extends TestCase
{
    private const MINT = '4fKR1UC2UA5R5m3ZGJwisZD4tkqQ2ZEPgGeZn51bB8uy';

    private function budget(int $n = 5): ProviderRequestBudget
    {
        return new ProviderRequestBudget($n, 30);
    }

    // ── Happy path ──────────────────────────────────────────────────────

    public function testAVerifiedCollectionGroupingIsValid(): void
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->asset = [
            'grouping' => [
                ['group_key' => 'collection', 'group_value' => 'SomeOtherMint111111111111111111111111111111', 'verified' => true],
            ],
            'content' => [
                'metadata' => ['name' => 'Degen Apes'],
                'links'    => ['image' => 'https://example.test/d.png'],
            ],
        ];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::VALID, $v->state());
        self::assertSame('SPL-Metaplex', $v->standard());
        self::assertSame('Degen Apes', $v->metadata()?->valueOf('name'));
        self::assertSame('https://example.test/d.png', $v->metadata()?->valueOf('image_url'));
    }

    public function testACollectionParentPointingAtItselfIsValid(): void
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->asset = [
            'grouping' => [['group_key' => 'collection', 'group_value' => self::MINT]],
            'content'  => ['metadata' => ['name' => 'Parent Collection']],
        ];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::VALID, $v->state());
    }

    // ── ⚠⚠ Unverified grouping ──────────────────────────────────────────

    public function testAnUnverifiedGroupingIsRefused(): void
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->asset = [
            'grouping' => [
                ['group_key' => 'collection', 'group_value' => 'BlueChipMint11111111111111111111111111111111', 'verified' => false],
            ],
            'content' => ['metadata' => ['name' => 'Totally Legit Apes']],
        ];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::INVALID, $v->state());
        self::assertFalse($v->mayPersist());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_GROUPING_UNVERIFIED));
    }

    public function testAnAbsentVerifiedFlagIsNotTreatedAsVerified(): void
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->asset = [
            'grouping' => [
                ['group_key' => 'collection', 'group_value' => 'BlueChipMint11111111111111111111111111111111'],
            ],
            'content' => ['metadata' => ['name' => 'Unproven']],
        ];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::INVALID, $v->state(), 'absent is unproven, and unproven is refused');
    }

    public function testNoCollectionGroupingAtAllIsRefused(): void
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->asset = ['grouping' => [], 'content' => ['metadata' => ['name' => 'Loose NFT']]];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::INVALID, $v->state());
    }

    // ── ⚠⚠ Compressed NFTs (DECISION 8) ─────────────────────────────────

    public function testACompressedNftIsUnsupportedNotValid(): void
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->asset = [
            'compression' => ['compressed' => true],
            'grouping'    => [['group_key' => 'collection', 'group_value' => 'X', 'verified' => true]],
            'content'     => ['metadata' => ['name' => 'Compressed Collection']],
        ];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNSUPPORTED, $v->state());
        self::assertFalse($v->isValid());
        self::assertFalse($v->mayPersist());
        self::assertSame('compressed-nft', $v->standard());
    }

    public function testCompressionIsCheckedBeforeGrouping(): void
    {
        // A compressed asset with a perfectly verified grouping must still be
        // refused — otherwise the grouping check would let it through.
        $f = new FakeSolanaFetcherForValidation();
        $f->asset = [
            'compression' => ['compressed' => true],
            'grouping'    => [['group_key' => 'collection', 'group_value' => self::MINT, 'verified' => true]],
        ];

        self::assertSame(
            ContractValidationVerdict::UNSUPPORTED,
            (new SolanaContractProbe($f))->validate(self::MINT, $this->budget())->state()
        );
    }

    // ── ⚠⚠ The mint-as-name trap ────────────────────────────────────────

    public function testTheCollectionMintAddressIsNeverStoredAsTheName(): void
    {
        $groupValue = 'DRiP2Pn2K6fuMLKQmt5rZWyHiUZ6WK3GChEySUpHSS4x';

        $f = new FakeSolanaFetcherForValidation();
        $f->asset = [
            // The asset HAS a verified grouping, but carries no readable name.
            'grouping' => [['group_key' => 'collection', 'group_value' => $groupValue, 'verified' => true]],
            'content'  => ['metadata' => []],
        ];

        $m = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget())->metadata();

        self::assertInstanceOf(IntakeMetadata::class, $m);
        self::assertNotSame($groupValue, $m->valueOf('name'), 'the mint address is not a name');
        self::assertNotSame(self::MINT, $m->valueOf('name'));
        self::assertSame(
            IntakeMetadata::ABSENT,
            $m->stateOf('name'),
            'DAS answered and there is no name — absent, and certainly not the address'
        );
    }

    public function testTheNameComesFromContentMetadataName(): void
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->asset = [
            'grouping' => [['group_key' => 'collection', 'group_value' => 'Mint2222222222222222222222222222222222222222', 'verified' => true]],
            'content'  => ['metadata' => ['name' => 'Okay Bears']],
        ];

        $m = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget())->metadata();

        self::assertSame('Okay Bears', $m?->valueOf('name'));
    }

    // ── Fail closed ─────────────────────────────────────────────────────

    public function testMissingHeliusCredentialsAreUnavailableNotInvalid(): void
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->kind = 'credentials_missing';

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertFalse($v->isDecided());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_CREDENTIALS_MISSING));
    }

    public function testATimeoutIsUnavailableNotInvalid(): void
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->kind = 'transport';

        self::assertSame(
            ContractValidationVerdict::UNAVAILABLE,
            (new SolanaContractProbe($f))->validate(self::MINT, $this->budget())->state()
        );
    }

    public function testAMalformedResponseIsUnavailableNotInvalid(): void
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->kind = 'malformed';

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_MALFORMED_RESPONSE));
    }

    /**
     * `not_found` is the one DAS failure that IS an answer about the address.
     */
    public function testAnUnknownMintIsInvalidBecauseDasAnswered(): void
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->kind = 'not_found';

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::INVALID, $v->state());
        self::assertTrue($v->isDecided());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_NO_CODE_AT_ADDRESS));
    }

    // ── Bounded cost ────────────────────────────────────────────────────

    public function testValidationCostsExactlyOneDasCall(): void
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->asset = [
            'grouping' => [['group_key' => 'collection', 'group_value' => self::MINT, 'verified' => true]],
            'content'  => ['metadata' => ['name' => 'One Call']],
        ];

        (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(1, $f->calls, 'validation and metadata share one response');
    }

    public function testAnExhaustedBudgetMakesNoRequest(): void
    {
        $f = new FakeSolanaFetcherForValidation();

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(0, 30));

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertSame(0, $f->calls);
    }
}

class FakeSolanaFetcherForValidation extends \BCC\Trust\Onchain\Fetchers\SolanaFetcher
{
    public int $calls = 0;
    public string $kind = 'none';

    /** @var array<string, mixed>|null */
    public ?array $asset = null;

    public function __construct()
    {
        // No transport in these tests.
    }

    public function assetResult(string $mint): array
    {
        $this->calls++;

        if ($this->kind !== 'none') {
            return ['ok' => false, 'result' => null, 'kind' => $this->kind];
        }

        return ['ok' => true, 'result' => $this->asset ?? [], 'kind' => 'none'];
    }
}
