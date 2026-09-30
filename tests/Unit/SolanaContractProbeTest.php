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
     * ⚠⚠⚠ THE EDITION-PRINT `supply` OBJECT IS NEVER THE COLLECTION'S SUPPLY.
     *
     * `supply.print_current_supply` describes EDITION PRINTS of the one
     * collection NFT — a different number answering a different question.
     *
     * ⚠ Round 4 changed the STATE, not this rule. Supply is now APPLICABLE on
     * Solana (the group result's `total` is a real source), so an unprovable
     * count is UNKNOWN rather than NOT_APPLICABLE. What must never happen is
     * the print count being stored, and that is what this pins: the fixture's
     * group total is ambiguous, so the answer is UNKNOWN — emphatically not 137.
     */
    public function testTheEditionPrintSupplyIsNeverStoredAsCollectionSupply(): void
    {
        $f = $this->fetcher();
        $f->asset = self::collectionAsset();
        $f->asset['supply'] = ['print_max_supply' => 500, 'print_current_supply' => 137];

        $m = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget())->metadata();

        self::assertInstanceOf(IntakeMetadata::class, $m);
        self::assertSame(IntakeMetadata::UNKNOWN, $m->stateOf('total_supply'));
        self::assertArrayNotHasKey('total_supply', $m->writableFields());
        self::assertNotSame(137, $m->valueOf('total_supply'));
        self::assertNotSame(500, $m->valueOf('total_supply'));
    }

    public function testSymbolAndDescriptionAreNotApplicableOnSolana(): void
    {
        $m = (new SolanaContractProbe($this->fetcher()))->validate(self::MINT, $this->budget())->metadata();

        self::assertSame(IntakeMetadata::NOT_APPLICABLE, $m?->stateOf('symbol'));
        self::assertSame(IntakeMetadata::NOT_APPLICABLE, $m?->stateOf('description'));
    }

    /**
     * ⚠ A fully successful Solana read must be COMPLETE, not permanently
     * partial. Before NOT_APPLICABLE existed, the fields DAS cannot supply were
     * UNKNOWN forever and `complete` was unreachable.
     *
     * ⚠⚠ Round 4 made supply the THIRD applicable field, so "fully successful"
     * now includes a provable membership count — hence the explicit group total
     * above the page limit. The default fixture's total is ambiguous, which is
     * `partial`, and that is correct: a field we attempted and could not read
     * IS a shortfall. See `testAnAmbiguousOrUnusableGroupTotalLeavesSupplyUnknown`.
     */
    public function testAFullySuccessfulSolanaReadIsComplete(): void
    {
        $f = $this->fetcher();
        $f->groupTotal = 4200; // > limit 1, so provably collection-wide

        $m = (new SolanaContractProbe($f))->validate(self::MINT, $this->budget())->metadata();

        self::assertSame(IntakeMetadata::STATE_COMPLETE, $m?->state());
        self::assertSame(
            3,
            $m?->applicableCount(),
            'name, image and — since round 4 — the membership count'
        );
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

    // ═══════════════════════════════════════════════════════════════════
    //  DECISION 8 — FULLY ENFORCED cNFT EXCLUSION (round 4)
    // ═══════════════════════════════════════════════════════════════════
    //
    // ⚠⚠⚠ THE DEFECT THESE EXIST TO CLOSE. Round 3 decided compression from
    // ONE sampled member. A collection may legitimately mix compressed and
    // uncompressed members, so a mixed collection whose first sample happened
    // to be uncompressed VALIDATED and was persisted — the exclusion was
    // sampled, not enforced, and the class docblock said so.
    //
    // The authoritative question is an EXISTENCE question, and `searchAssets`
    // documents exactly it: `grouping: ["collection", <addr>]` + `compressed:
    // true`, bounded to page 1 / limit 1. One returned item proves at least
    // one compressed member exists. No count is needed, so `showGrandTotal`
    // is not requested — and its response field name is undocumented anyway.

    /**
     * ⚠⚠ THE HEADLINE CASE. Uncompressed sample, compressed member elsewhere.
     * Round 3 returned VALID here and wrote the row.
     */
    public function testAMixedCollectionWithAnUncompressedFirstSampleIsUnsupported(): void
    {
        $f = $this->fetcher();
        $f->groupItems      = [self::memberItem(false)]; // the sample looks fine
        $f->compressedItems = [self::memberItem(true)];  // but one exists

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30));

        self::assertSame(ContractValidationVerdict::UNSUPPORTED, $v->state());
        self::assertSame('compressed-nft', $v->standard());
        self::assertFalse($v->mayPersist(), 'a collection containing a cNFT persists nothing');
        self::assertSame(1, $f->compressedCalls, 'the existence query must actually be made');
    }

    public function testACollectionWithNoCompressedMemberIsValid(): void
    {
        $f = $this->fetcher();
        $f->groupItems      = [self::memberItem(false)];
        $f->compressedItems = []; // decisive zero
        $f->asset           = self::collectionAsset();

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30));

        self::assertSame(ContractValidationVerdict::VALID, $v->state());
        self::assertSame(1, $f->compressedCalls);
    }

    /** A compressed SAMPLE is still refused immediately — and costs no extra call. */
    public function testACompressedSampleIsRefusedWithoutTheExistenceQuery(): void
    {
        $f = $this->fetcher();
        $f->groupItems = [self::memberItem(true)];

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30));

        self::assertSame(ContractValidationVerdict::UNSUPPORTED, $v->state());
        self::assertSame('compressed-nft', $v->standard());
        self::assertSame(
            0,
            $f->compressedCalls,
            'an already-decided negative must not spend another provider request'
        );
    }

    /**
     * ⚠⚠⚠ PROVIDER UNCERTAINTY IS NEVER A ZERO. Every non-decisive outcome of
     * the existence query is UNAVAILABLE and persists nothing — otherwise an
     * expired key would read as "no compressed members".
     *
     * @return array<string, array{0: string}>
     */
    public static function nonDecisiveExistenceKinds(): array
    {
        return [
            'transport'           => ['transport'],
            'malformed'           => ['malformed'],
            'rate limited'        => ['rate_limited'],
            'credentials missing' => ['credentials_missing'],
            'http error'          => ['http_error'],
            'not found'           => ['not_found'],
        ];
    }

    #[DataProvider('nonDecisiveExistenceKinds')]
    public function testANonDecisiveExistenceQueryIsUnavailable(string $kind): void
    {
        $f = $this->fetcher();
        $f->groupItems           = [self::memberItem(false)];
        $f->compressedKind       = $kind;
        $f->asset                = self::collectionAsset();

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30));

        self::assertSame(
            ContractValidationVerdict::UNAVAILABLE,
            $v->state(),
            "a '{$kind}' existence answer must never be read as zero compressed members"
        );
        self::assertFalse($v->mayPersist());
    }

    /** An undocumented response shape is not an answer either. */
    public function testAnExistenceResponseWithoutAnItemsArrayIsUnavailable(): void
    {
        $f = $this->fetcher();
        $f->groupItems            = [self::memberItem(false)];
        $f->compressedMalformed   = true;

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30));

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
    }

    /** The budget must actually permit the third call, or enforcement never runs. */
    public function testTheExistenceQueryIsNotSilentlySkippedWhenTheBudgetIsTight(): void
    {
        $f = $this->fetcher();
        $f->groupItems      = [self::memberItem(false)];
        $f->compressedItems = [self::memberItem(true)];

        // Exactly enough for the group lookup and nothing more.
        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(1, 30));

        self::assertSame(
            ContractValidationVerdict::UNAVAILABLE,
            $v->state(),
            'an unaskable exclusion question is UNAVAILABLE, never an implied pass'
        );
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_BUDGET_EXHAUSTED));
    }

    public function testTheExistenceQueryAsksAboutTheSubmittedMint(): void
    {
        $f = $this->fetcher();
        $f->groupItems      = [self::memberItem(false)];
        $f->compressedItems = [];
        $f->asset           = self::collectionAsset();

        (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30));

        self::assertSame(self::MINT, $f->lastCompressedGroupValue);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SUPPLY FROM THE GROUP TOTAL (round 4)
    // ═══════════════════════════════════════════════════════════════════
    //
    // ⚠⚠⚠ `total` IS NOT PROVABLY THE COLLECTION SIZE. Helius's parameter
    // table calls it "the total number of Solana NFTs found in this collection
    // or group", but the documented response EXAMPLE shows `"total": 1` for a
    // one-item page, and `showGrandTotal` — "Show total number of matching
    // assets (slower request)" — exists on the same method. If `total` were
    // already collection-wide, that option would be redundant.
    //
    // So only `total > limit` PROVES the value is not page-bounded: a page
    // count can never exceed the page limit. Anything at or below the limit is
    // indistinguishable from "items on this page" and stays UNKNOWN.
    //
    // ⚠ `showGrandTotal`'s response FIELD NAME is undocumented, so requesting
    // it and reading a guessed key would repeat the `showCollectionMetadata`
    // mistake exactly.

    public function testSupplyComesFromTheGroupTotalWhenItExceedsTheLimit(): void
    {
        $f = $this->fetcher();
        $f->groupItems      = [self::memberItem(false)];
        $f->groupTotal      = 4200;   // > limit 1, so it cannot be a page count
        $f->compressedItems = [];
        $f->asset           = self::collectionAsset();

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30));

        self::assertSame(ContractValidationVerdict::VALID, $v->state());
        $m = $v->metadata();
        self::assertNotNull($m);
        self::assertSame(IntakeMetadata::KNOWN, $m->stateOf('total_supply'));
        self::assertSame(4200, $m->valueOf('total_supply'));
    }

    /**
     * ⚠⚠ A `total` at or below the page limit is AMBIGUOUS, so it is UNKNOWN —
     * not 1. Recording 1 would put a fabricated supply on every collection if
     * `total` turns out to be the page count.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function ambiguousOrUnusableTotals(): array
    {
        return [
            'equal to the limit' => [1],
            'zero'               => [0],
            'negative'           => [-5],
            'absurdly large'     => [PHP_INT_MAX],
            'a string'           => ['4200'],
            'a float'            => [4200.5],
            'null'               => [null],
            'an array'           => [[4200]],
        ];
    }

    #[DataProvider('ambiguousOrUnusableTotals')]
    public function testAnAmbiguousOrUnusableGroupTotalLeavesSupplyUnknown(mixed $total): void
    {
        $f = $this->fetcher();
        $f->groupItems      = [self::memberItem(false)];
        $f->groupTotal      = $total;
        $f->compressedItems = [];
        $f->asset           = self::collectionAsset();

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30));

        self::assertSame(ContractValidationVerdict::VALID, $v->state(), 'supply is optional, not a gate');
        $m = $v->metadata();
        self::assertNotNull($m);
        self::assertSame(
            IntakeMetadata::UNKNOWN,
            $m->stateOf('total_supply'),
            'an unprovable count is UNKNOWN — never a number, never zero'
        );
        self::assertArrayNotHasKey('total_supply', $m->writableFields());
        self::assertSame(
            IntakeMetadata::STATE_PARTIAL,
            $m->state(),
            'a field we attempted and could not read IS a real shortfall'
        );
    }

    /**
     * ⚠⚠⚠ THE EDITION-PRINT `supply` OBJECT IS A DIFFERENT NUMBER. It describes
     * prints of ONE master-edition NFT (`print_current_supply` /
     * `print_max_supply`), not how many items a collection contains. Using it
     * would show an operator a confident number that answers another question.
     */
    public function testTheEditionPrintSupplyObjectIsNeverUsedAsCollectionSupply(): void
    {
        $f = $this->fetcher();
        $f->groupItems      = [self::memberItem(false)];
        $f->groupTotal      = 4200;
        $f->compressedItems = [];
        // The collection asset carries a print supply of 7 — a decoy.
        $asset              = self::collectionAsset();
        $asset['supply']    = ['print_max_supply' => 7, 'print_current_supply' => 7, 'edition_nonce' => 254];
        $f->asset           = $asset;

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30));

        self::assertSame(4200, $v->metadata()?->valueOf('total_supply'));
        self::assertNotSame(7, $v->metadata()?->valueOf('total_supply'));
    }

    // ── ⚠⚠⚠ THE INFERENCE ONLY HOLDS FOR limit === 1 (round 5) ──────────
    //
    // `total > limit` was too weak. It proves `limit` is an integer smaller
    // than `total` — nothing more. The actual reasoning is narrower: the probe
    // REQUESTS `limit: 1`, so a response echoing `limit: 1` is one the provider
    // honoured, and in that response a page count can be at most 1. Any other
    // echoed limit means the provider served a page we did not ask for, so the
    // response is not the one the inference was reasoned about and `total`
    // could mean something else entirely.
    //
    // Requiring exactly 1 keeps the conclusion tied to the request that
    // justified it. It costs no extra provider call — the limit is already in
    // the response we have.

    public function testSupplyIsKnownOnlyWhenTheEchoedLimitIsExactlyOne(): void
    {
        $f = $this->fetcher();
        $f->groupItems      = [self::memberItem(false)];
        $f->groupTotal      = 4200;
        $f->groupLimit      = 1; // the limit we asked for, echoed back
        $f->compressedItems = [];
        $f->asset           = self::collectionAsset();

        $m = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30))->metadata();

        self::assertSame(IntakeMetadata::KNOWN, $m?->stateOf('total_supply'));
        self::assertSame(4200, $m?->valueOf('total_supply'));
    }

    /**
     * Every limit that is not exactly 1 leaves supply UNKNOWN.
     *
     * ⚠ `2` and `500` are the dangerous ones: `4200 > 2` and `4200 > 500` both
     * satisfied the old rule, so a provider quietly serving its own page size
     * would have had its `total` persisted as the collection's supply.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function limitsThatCannotJustifyTheInference(): array
    {
        return [
            'zero'            => [0],
            'negative'        => [-1],
            'two'             => [2],
            'a default page'  => [500],
            'null'            => [null],
            'a numeric string'=> ['1'],
            'a float'         => [1.0],
            'omitted'         => [FakeSolanaFetcherForValidation::OMIT],
        ];
    }

    #[DataProvider('limitsThatCannotJustifyTheInference')]
    public function testAnyOtherEchoedLimitLeavesSupplyUnknown(mixed $limit): void
    {
        $f = $this->fetcher();
        $f->groupItems      = [self::memberItem(false)];
        $f->groupTotal      = 4200; // would pass the old `total > limit` rule
        $f->groupLimit      = $limit;
        $f->compressedItems = [];
        $f->asset           = self::collectionAsset();

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30));
        $m = $v->metadata();

        // ⚠ The collection is STILL VALID. An unprovable supply is a metadata
        // shortfall, not a reason to refuse a verified collection.
        self::assertSame(ContractValidationVerdict::VALID, $v->state());
        self::assertTrue($v->mayPersist());

        self::assertSame(
            IntakeMetadata::UNKNOWN,
            $m?->stateOf('total_supply'),
            'the inference is only justified for the limit we actually requested'
        );
        self::assertArrayNotHasKey('total_supply', $m?->writableFields() ?? []);
        self::assertNotSame(4200, $m?->valueOf('total_supply'));
        self::assertSame(IntakeMetadata::STATE_PARTIAL, $m?->state());
    }

    /** And no extra provider call is made to recover it. */
    #[DataProvider('limitsThatCannotJustifyTheInference')]
    public function testAnUnusableLimitCostsNoExtraRequest(mixed $limit): void
    {
        $f = $this->fetcher();
        $f->groupItems      = [self::memberItem(false)];
        $f->groupTotal      = 4200;
        $f->groupLimit      = $limit;
        $f->compressedItems = [];
        $f->asset           = self::collectionAsset();

        (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30));

        self::assertSame(1, $f->groupCalls, 'one group lookup, however unusable its limit');
        self::assertSame(1, $f->compressedCalls);
        self::assertSame(1, $f->assetCalls);
    }

    /** The conservative rule still bites when the limit IS 1. */
    public function testATotalEqualToAnEchoedLimitOfOneIsStillUnknown(): void
    {
        $f = $this->fetcher();
        $f->groupItems      = [self::memberItem(false)];
        $f->groupTotal      = 1;
        $f->groupLimit      = 1;
        $f->compressedItems = [];
        $f->asset           = self::collectionAsset();

        $m = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30))->metadata();

        self::assertSame(
            IntakeMetadata::UNKNOWN,
            $m?->stateOf('total_supply'),
            'total 1 with limit 1 is indistinguishable from a one-item page'
        );
    }

    /** A fully successful Solana read — name, image AND a provable supply. */
    public function testAFullSolanaReadIsComplete(): void
    {
        $f = $this->fetcher();
        $f->groupItems      = [self::memberItem(false)];
        $f->groupTotal      = 4200;
        $f->compressedItems = [];
        $f->asset           = self::collectionAsset();

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(5, 30));

        self::assertSame(IntakeMetadata::STATE_COMPLETE, $v->metadata()?->state());
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

    /**
     * The `total` the group result reports. `null` means "derive it from the
     * item count", which is what a page-bounded reading would produce.
     */
    public mixed $groupTotal = null;

    /**
     * The `limit` the group result ECHOES BACK.
     *
     * ⚠ Settable, and defaulting to the 1 the probe actually requests, because
     * the whole supply inference rests on the provider having honoured that
     * request. A response echoing any other limit is not the response the
     * inference was reasoned about. Use the sentinel below to omit the key.
     */
    public mixed $groupLimit = 1;

    /** Assign to `$groupLimit` to leave `limit` out of the result entirely. */
    public const OMIT = '__omit__';

    // ── The documented `searchAssets` compressed-existence seam ──────────

    public int $compressedCalls = 0;

    public ?string $lastCompressedGroupValue = null;

    /** @var list<array<string, mixed>> items the compressed filter matches */
    public array $compressedItems = [];

    public ?string $compressedKind = null;

    /** Return a body with no `items` ARRAY — an undocumented shape. */
    public bool $compressedMalformed = false;

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

        $result = [
            'total' => $this->groupTotal ?? count($this->groupItems),
            'page'  => 1,
            'items' => $this->groupItems,
        ];
        if ($this->groupLimit !== self::OMIT) {
            $result['limit'] = $this->groupLimit;
        }

        return ['ok' => true, 'result' => $result, 'kind' => 'none'];
    }

    /**
     * The documented `searchAssets` existence query, as the probe consumes it.
     *
     * ⚠ `items` is the ONLY thing the caller may read for existence. A body
     * without an items ARRAY is not the documented shape and must not be
     * guessed at.
     *
     * @return array{ok: bool, result: ?array<string, mixed>, kind: string}
     */
    public function compressedMembersExistResult(string $collectionMint): array
    {
        $this->compressedCalls++;
        $this->lastCompressedGroupValue = $collectionMint;

        if ($this->compressedKind !== null) {
            return ['ok' => false, 'result' => null, 'kind' => $this->compressedKind];
        }

        if ($this->compressedMalformed) {
            return ['ok' => false, 'result' => null, 'kind' => 'malformed'];
        }

        return [
            'ok'     => true,
            'result' => [
                'total' => count($this->compressedItems),
                'limit' => 1,
                'page'  => 1,
                'items' => $this->compressedItems,
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
