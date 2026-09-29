<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Services\Validation\SolanaContractProbe;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\ContractValidationVerdict;
use BCC\Trust\Onchain\ValueObjects\IntakeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Solana validation against the DOCUMENTED Helius DAS contract.
 *
 * ── ⚠⚠⚠ THE FIXTURES HERE ARE THE DOCUMENTED SHAPES, NOT INVENTED ONES ──
 * An earlier version of this suite asserted a `grouping[].verified` boolean.
 * **No such field exists.** Per the Helius `getAsset` reference and the
 * Metaplex DAS specification, a grouping entry contains exactly `group_key`
 * and `group_value`:
 *
 *     {"group_key": "collection", "group_value": "J1S9H3QjnRtBbbuD4HjPV6..."}
 *
 * Verification is expressed by OMISSION instead:
 * `options.showUnverifiedCollections` defaults to false, and an unverified
 * grouping is then left out of the results rather than returned marked false.
 * So the question is asked with `getAssetsByGroup` — the documented method for
 * taking a collection address as `groupValue` — and answered by whether any
 * members come back.
 *
 * Error fixtures use the documented Helius codes: `-32004` not found, `-32001`
 * authentication, `-32003` permission, `-32029` rate limit, `-32000` generic
 * server error. The previously invented `-32401` / `-32429` are gone.
 */
#[CoversClass(SolanaContractProbe::class)]
final class SolanaContractProbeTest extends TestCase
{
    private const MINT = '4fKR1UC2UA5R5m3ZGJwisZD4tkqQ2ZEPgGeZn51bB8uy';

    private function budget(int $n = 5): ProviderRequestBudget
    {
        return new ProviderRequestBudget($n, 30);
    }

    /**
     * One documented `getAssetsByGroup` item, trimmed to the fields this probe
     * reads. `compression.compressed` and `grouping[]` are verbatim shapes.
     *
     * @return array<string, mixed>
     */
    private static function memberItem(bool $compressed = false): array
    {
        return [
            'interface'   => 'V1_NFT',
            'id'          => '9ZmY3nZrRMcnZWb8CyBqnUBKtVJTgdbGJKy6MmtDtBaT',
            'compression' => [
                'eligible'     => false,
                'compressed'   => $compressed,
                'data_hash'    => '',
                'creator_hash' => '',
                'asset_hash'   => '',
                'tree'         => '',
                'seq'          => 0,
                'leaf_id'      => 0,
            ],
            // ⚠ Exactly two documented keys. No `verified`.
            'grouping' => [
                ['group_key' => 'collection', 'group_value' => self::MINT],
            ],
            'content' => [
                'metadata' => ['name' => 'Mad Lads #8420', 'symbol' => 'MAD'],
                'links'    => ['image' => 'https://example.test/member.png'],
            ],
            'supply'  => ['print_max_supply' => 0, 'print_current_supply' => 0, 'edition_nonce' => 254],
            'mutable' => true,
            'burnt'   => false,
        ];
    }

    /**
     * The documented `getAsset` response for the COLLECTION NFT itself, which
     * is where the collection's own name and image come from.
     *
     * @return array<string, mixed>
     */
    private static function collectionAsset(?string $name = 'Mad Lads', ?string $image = 'https://example.test/coll.png'): array
    {
        $meta = [];
        if ($name !== null) {
            $meta['name'] = $name;
        }
        $links = [];
        if ($image !== null) {
            $links['image'] = $image;
        }

        return [
            'interface'   => 'V1_NFT',
            'id'          => self::MINT,
            'compression' => ['compressed' => false],
            'content'     => ['metadata' => $meta, 'links' => $links],
            'supply'      => ['print_max_supply' => 0, 'print_current_supply' => 0],
        ];
    }

    private function fetcher(): FakeSolanaFetcherForValidation
    {
        $f = new FakeSolanaFetcherForValidation();
        $f->groupItems = [self::memberItem()];
        $f->asset      = self::collectionAsset();

        return $f;
    }

    // ── A verified collection group validates ───────────────────────────

    public function testANonEmptyVerifiedGroupResultIsValid(): void
    {
        $v = (new SolanaContractProbe($this->fetcher()))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::VALID, $v->state());
        self::assertSame('SPL-Metaplex', $v->standard());
        self::assertTrue($v->mayPersist());
    }

    public function testTheGroupRequestIsBoundedAndAsksForVerifiedOnly(): void
    {
        $f = $this->fetcher();
        (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(self::MINT, $f->lastGroupValue, 'the submitted mint is the groupValue');
        self::assertSame(1, $f->groupCalls, 'one group lookup is all the proof this needs');
    }

    // ── ⚠⚠ An empty result is a decided negative ────────────────────────

    public function testAnEmptyGroupResultIsInvalid(): void
    {
        $f = $this->fetcher();
        $f->groupItems = [];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::INVALID, $v->state());
        self::assertTrue($v->isDecided(), 'with unverified collections excluded, empty IS an answer');
        self::assertFalse($v->mayPersist());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_GROUPING_UNVERIFIED));
    }

    /**
     * ⚠ The documented no-assets response. `-32004` on `getAssetsByGroup` means
     * "No assets found for the specified group" — an answer about the address.
     */
    public function testTheDocumentedNoAssetsErrorIsInvalid(): void
    {
        $f = $this->fetcher();
        $f->groupError = ['code' => -32004, 'message' => 'No assets found for the specified group.'];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::INVALID, $v->state());
        self::assertTrue($v->isDecided());
    }

    // ── ⚠⚠⚠ Every other documented error fails closed ───────────────────

    /** @return array<string, array{0: int, 1: string}> */
    public static function nonDecisiveErrors(): array
    {
        return [
            'authentication (-32001)' => [-32001, 'Authentication failed. Missing or invalid API key.'],
            'permission (-32003)'     => [-32003, 'You do not have permission to access this resource.'],
            'rate limit (-32029)'     => [-32029, 'Rate limit exceeded. Please try again later.'],
            'rate limit alt (-32005)' => [-32005, 'Rate limit exceeded'],
            'generic server (-32000)' => [-32000, 'Server error'],
            'internal (-32603)'       => [-32603, 'Internal error'],
            'method (-32601)'         => [-32601, 'Method not found'],
            'params (-32602)'         => [-32602, 'Invalid params'],
            'unknown vendor code'     => [-31999, 'Something new Helius added'],
        ];
    }

    #[DataProvider('nonDecisiveErrors')]
    public function testNonNotFoundErrorsAreUnavailableNotInvalid(int $code, string $message): void
    {
        $f = $this->fetcher();
        $f->groupError = ['code' => $code, 'message' => $message];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(
            ContractValidationVerdict::UNAVAILABLE,
            $v->state(),
            "JSON-RPC {$code} says nothing about the mint"
        );
        self::assertNotSame(ContractValidationVerdict::INVALID, $v->state());
        self::assertFalse($v->mayPersist());
    }

    public function testAuthenticationFailureIsReportedAsMissingCredentials(): void
    {
        $f = $this->fetcher();
        $f->groupError = ['code' => -32001, 'message' => 'Authentication failed. Missing or invalid API key.'];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_CREDENTIALS_MISSING));
    }

    public function testRateLimitingIsReportedAsRateLimited(): void
    {
        $f = $this->fetcher();
        $f->groupError = ['code' => -32029, 'message' => 'Rate limit exceeded. Please try again later.'];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_PROVIDER_RATE_LIMITED));
    }

    /**
     * ⚠ `-32004` is only decisive for the method that documents it. Accepting
     * it from the wrong method would let one endpoint's silence answer a
     * question the other asked.
     */
    public function testTheNotFoundCodeIsNotDecisiveForAnUnrelatedMethod(): void
    {
        self::assertSame(
            'not_found',
            SolanaFetcher::classifyDasError(['code' => -32004], SolanaFetcher::METHOD_ASSETS_BY_GROUP)
        );
        self::assertSame(
            'not_found',
            SolanaFetcher::classifyDasError(['code' => -32004], SolanaFetcher::METHOD_GET_ASSET)
        );
        self::assertSame(
            'transport',
            SolanaFetcher::classifyDasError(['code' => -32004], 'getTokenAccounts'),
            'a not-found from a method that does not document it decides nothing'
        );
        self::assertSame('transport', SolanaFetcher::classifyDasError(['code' => -32004], ''));
    }

    public function testAMalformedErrorObjectIsUnavailable(): void
    {
        $f = $this->fetcher();
        $f->groupError = ['no_code_key' => true];

        self::assertSame(
            ContractValidationVerdict::UNAVAILABLE,
            (new SolanaContractProbe($f))->validate(self::MINT, $this->budget())->state()
        );
    }

    public function testAMalformedGroupBodyIsUnavailable(): void
    {
        $f = $this->fetcher();
        $f->groupKindOverride = 'malformed';

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_MALFORMED_RESPONSE));
    }

    // ── Compression: the SAMPLED MEMBER, and only that ──────────────────

    /**
     * ⚠⚠ PARTIAL DECISION-8 ENFORCEMENT, DELIBERATELY LABELLED AS SUCH.
     *
     * The sampled member's `compression.compressed` is documented, so a
     * compressed sample is refused. But nothing in `getAssetsByGroup`
     * aggregates by compression, so ONE sample cannot prove a mixed
     * collection's type — see the probe's class docblock and the PR report.
     */
    public function testACompressedSampledMemberIsUnsupported(): void
    {
        $f = $this->fetcher();
        $f->groupItems = [self::memberItem(true)];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNSUPPORTED, $v->state());
        self::assertFalse($v->mayPersist());
        self::assertSame('compressed-nft', $v->standard());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_STANDARD_DEFERRED));
    }

    public function testAMemberWithNoCompressionFieldIsUnavailableNotAccepted(): void
    {
        $item = self::memberItem();
        unset($item['compression']);

        $f = $this->fetcher();
        $f->groupItems = [$item];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(
            ContractValidationVerdict::UNAVAILABLE,
            $v->state(),
            'the field is documented, so its absence means the shape is not what we expect'
        );
    }

    // ── ⚠⚠ Metadata: the COLLECTION's name, never a member's ────────────

    public function testTheCollectionNameComesFromTheCollectionAssetNotTheMember(): void
    {
        $f = $this->fetcher();
        // The member is called "Mad Lads #8420"; the collection is "Mad Lads".
        $m = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget())->metadata();

        self::assertInstanceOf(IntakeMetadata::class, $m);
        self::assertSame('Mad Lads', $m->valueOf('name'));
        self::assertNotSame('Mad Lads #8420', $m->valueOf('name'), 'a member name is not the collection name');
    }

    public function testTheMintAddressIsNeverStoredAsTheName(): void
    {
        $f = $this->fetcher();
        $f->asset = self::collectionAsset(null, null);

        $m = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget())->metadata();

        self::assertInstanceOf(IntakeMetadata::class, $m);
        self::assertNotSame(self::MINT, $m->valueOf('name'));
        self::assertSame(IntakeMetadata::ABSENT, $m->stateOf('name'));
    }

    /**
     * ⚠ `supply.print_current_supply` describes EDITION PRINTS of the one
     * collection NFT, not how many items the collection holds. Storing it as
     * the collection's item count would be a different number from the one an
     * operator expects, so supply is NOT_APPLICABLE on Solana.
     */
    public function testSolanaSupplyIsNotApplicableAndIsNeverStored(): void
    {
        $f = $this->fetcher();
        $f->asset = self::collectionAsset();
        $f->asset['supply'] = ['print_max_supply' => 500, 'print_current_supply' => 137];

        $m = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget())->metadata();

        self::assertInstanceOf(IntakeMetadata::class, $m);
        self::assertSame(IntakeMetadata::NOT_APPLICABLE, $m->stateOf('total_supply'));
        self::assertArrayNotHasKey('total_supply', $m->writableFields());
        self::assertNotSame(137, $m->valueOf('total_supply'));
    }

    public function testSymbolAndDescriptionAreNotApplicableOnSolana(): void
    {
        $m = (new SolanaContractProbe($this->fetcher()))->validate(self::MINT, $this->budget())->metadata();

        self::assertSame(IntakeMetadata::NOT_APPLICABLE, $m?->stateOf('symbol'));
        self::assertSame(IntakeMetadata::NOT_APPLICABLE, $m?->stateOf('description'));
    }

    /**
     * ⚠ A fully successful Solana read must be COMPLETE, not permanently
     * partial. Before NOT_APPLICABLE existed, the three fields DAS cannot
     * supply were UNKNOWN forever and `complete` was unreachable.
     */
    public function testAFullySuccessfulSolanaReadIsComplete(): void
    {
        $m = (new SolanaContractProbe($this->fetcher()))->validate(self::MINT, $this->budget())->metadata();

        self::assertSame(IntakeMetadata::STATE_COMPLETE, $m?->state());
        self::assertSame(2, $m?->applicableCount(), 'name and image are the applicable pair');
    }

    public function testAFailedCollectionMetadataReadIsUnavailableAndPersistsNothing(): void
    {
        $f = $this->fetcher();
        $f->assetKind = 'transport';

        $v = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertFalse($v->mayPersist());
    }

    // ── Bounded cost ────────────────────────────────────────────────────

    public function testValidationCostsTwoDasCalls(): void
    {
        $f = $this->fetcher();
        (new SolanaContractProbe($f))->validate(self::MINT, $this->budget());

        // ⚠ TWO, not one. `showCollectionMetadata` has no documented response
        // shape (Metaplex: "reserved for future use on this method"), so the
        // collection's own name and image need a second `getAsset`. This is a
        // deliberate, reported deviation from the one-call plan budget.
        self::assertSame(1, $f->groupCalls);
        self::assertSame(1, $f->assetCalls);
    }

    public function testAnExhaustedBudgetMakesNoRequest(): void
    {
        $f = $this->fetcher();

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(0, 30));

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertSame(0, $f->groupCalls);
        self::assertSame(0, $f->assetCalls);
    }

    public function testABudgetThatRunsOutBeforeMetadataIsUnavailable(): void
    {
        $f = $this->fetcher();

        // Enough for the group lookup, not for the metadata read.
        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(1, 30));

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_BUDGET_EXHAUSTED));
        self::assertSame(1, $f->groupCalls);
        self::assertSame(0, $f->assetCalls);
    }
}

class FakeSolanaFetcherForValidation extends \BCC\Trust\Onchain\Fetchers\SolanaFetcher
{
    public int $groupCalls = 0;
    public int $assetCalls = 0;

    public ?string $lastGroupValue = null;

    /** @var list<array<string, mixed>> */
    public array $groupItems = [];

    /** @var array<string, mixed>|null a raw JSON-RPC error object */
    public ?array $groupError = null;

    public ?string $groupKindOverride = null;

    /** @var array<string, mixed>|null */
    public ?array $asset = null;

    public string $assetKind = 'none';

    public function __construct()
    {
        // No transport in these tests.
    }

    public function assetsByGroupResult(string $collectionMint): array
    {
        $this->groupCalls++;
        $this->lastGroupValue = $collectionMint;

        if ($this->groupKindOverride !== null) {
            return ['ok' => false, 'result' => null, 'kind' => $this->groupKindOverride];
        }

        if ($this->groupError !== null) {
            // ⚠ Through the REAL classifier, with the REAL method name, so the
            // discrimination under test is production's and not the double's.
            return [
                'ok'     => false,
                'result' => null,
                'kind'   => \BCC\Trust\Onchain\Fetchers\SolanaFetcher::classifyDasError(
                    $this->groupError,
                    \BCC\Trust\Onchain\Fetchers\SolanaFetcher::METHOD_ASSETS_BY_GROUP
                ),
            ];
        }

        return [
            'ok'     => true,
            'result' => [
                'total' => count($this->groupItems),
                'limit' => 1,
                'page'  => 1,
                'items' => $this->groupItems,
            ],
            'kind'   => 'none',
        ];
    }

    public function assetResult(string $mint): array
    {
        $this->assetCalls++;

        if ($this->assetKind !== 'none') {
            return ['ok' => false, 'result' => null, 'kind' => $this->assetKind];
        }

        return ['ok' => true, 'result' => $this->asset ?? [], 'kind' => 'none'];
    }
}
