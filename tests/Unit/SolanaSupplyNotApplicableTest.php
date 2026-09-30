<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Services\ContractValidator;
use BCC\Trust\Onchain\Services\Validation\SolanaContractProbe;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\ContractValidationVerdict;
use BCC\Trust\Onchain\ValueObjects\IntakeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Solana v1: `total_supply` is NOT_APPLICABLE, and `getAssetsByGroup.total` is
 * never a supply source.
 *
 * ── ⚠⚠⚠ WHY THIS REPLACES A CAREFULLY-BUILT HEURISTIC ───────────────────
 * PR E inferred Solana collection supply from the verified group response's
 * `total`, guarded by a deliberately conservative rule: accept only when the
 * response echoed the requested `limit: 1` AND `total > limit`, on the
 * reasoning that a page-bounded count can never exceed its own page limit.
 *
 * That guard was CORRECT against the information available — Helius's parameter
 * table describes `total` as "the total number of Solana NFTs found in this
 * collection or group". Live measurement on staging disproved the SOURCE, not
 * the guard:
 *
 *     requested limit 1  → echoed limit 1  → total 1
 *     requested limit 5  → echoed limit 5  → total 5
 *     requested limit 20 → echoed limit 20 → total 20
 *
 * `total` tracks the requested limit exactly: it is the RETURNED PAGE COUNT.
 * And `showGrandTotal: true` returned an empty result object with no
 * `grand_total` field at all. So there is no bounded, documented source of
 * collection-wide Solana supply, and the conservative guard was protecting a
 * number that could never be collection-wide in the first place.
 *
 * ── WHAT NOT_APPLICABLE MEANS HERE, PRECISELY ───────────────────────────
 * It means **BCC does not reliably collect Solana collection supply through
 * this intake path**. It does NOT mean the collection has no supply, that its
 * supply is zero, or anything at all about the collection's real size. A
 * Solana row's absent supply is a statement about our reach, not about the
 * chain.
 *
 * Consequence: a Solana read that resolves every APPLICABLE field is
 * `complete` again, because the unreachable field is no longer counted as an
 * outstanding shortfall.
 */
#[CoversClass(SolanaContractProbe::class)]
#[CoversClass(IntakeMetadata::class)]
final class SolanaSupplyNotApplicableTest extends TestCase
{
    private const MINT = '4fKR1UC2UA5R5m3ZGJwisZD4tkqQ2ZEPgGeZn51bB8uy';

    /** @return array<string, mixed> one documented, uncompressed group member */
    private static function member(): array
    {
        return [
            'interface'   => 'V1_NFT',
            'id'          => '9ZmY3nZrRMcnZWb8CyBqnUBKtVJTgdbGJKy6MmtDtBaT',
            'compression' => ['eligible' => false, 'compressed' => false],
            'grouping'    => [['group_key' => 'collection', 'group_value' => self::MINT]],
            'content'     => ['metadata' => ['name' => 'Member #1'], 'links' => ['image' => 'https://x.test/m.png']],
            'supply'      => ['print_max_supply' => 0, 'print_current_supply' => 0],
        ];
    }

    /** @return array<string, mixed> the collection's own `getAsset` response */
    private static function collectionAsset(): array
    {
        return [
            'interface' => 'V1_NFT',
            'id'        => self::MINT,
            'content'   => [
                'metadata' => ['name' => 'Mad Lads'],
                'links'    => ['image' => 'https://example.test/coll.png'],
            ],
            // A decoy: edition prints of THIS asset, never the collection size.
            'supply'    => ['print_max_supply' => 777, 'print_current_supply' => 777],
        ];
    }

    private function fetcher(mixed $total = 1, mixed $limit = 1): FakeSolanaFetcherForSupply
    {
        $f = new FakeSolanaFetcherForSupply();
        $f->groupItems      = [self::member()];
        $f->groupTotal      = $total;
        $f->groupLimit      = $limit;
        $f->compressedItems = [];
        $f->asset           = self::collectionAsset();

        return $f;
    }

    private function metadataFor(mixed $total, mixed $limit): ?IntakeMetadata
    {
        $f = $this->fetcher($total, $limit);

        return (new SolanaContractProbe($f))
            ->validate(self::MINT, new ProviderRequestBudget(3, 30))
            ->metadata();
    }

    // ── 1. Applicability ────────────────────────────────────────────────

    public function testTotalSupplyIsNotApplicableOnSolana(): void
    {
        self::assertSame(
            ['name', 'image_url'],
            IntakeMetadata::applicableFieldsFor('solana'),
            'Solana attempts name and image only'
        );
        self::assertSame(
            IntakeMetadata::NOT_APPLICABLE,
            IntakeMetadata::forFamily('solana')->stateOf('total_supply')
        );
        self::assertFalse(IntakeMetadata::forFamily('solana')->isApplicable('total_supply'));
    }

    /** EVM and Cosmos are untouched — supply is genuinely reachable there. */
    public function testOtherFamiliesStillCollectSupply(): void
    {
        self::assertContains('total_supply', IntakeMetadata::applicableFieldsFor('evm'));
        self::assertContains('total_supply', IntakeMetadata::applicableFieldsFor('cosmos'));
        self::assertTrue(IntakeMetadata::forFamily('evm')->isApplicable('total_supply'));
        self::assertTrue(IntakeMetadata::forFamily('cosmos')->isApplicable('total_supply'));
    }

    // ── 2. ⚠⚠ THE HEADLINE: complete is reachable again ─────────────────

    public function testAValidSolanaReadIsCompleteWithSupplyNotApplicable(): void
    {
        $m = $this->metadataFor(1, 1);

        self::assertNotNull($m);
        self::assertSame(IntakeMetadata::KNOWN, $m->stateOf('name'));
        self::assertSame(IntakeMetadata::KNOWN, $m->stateOf('image_url'));
        self::assertSame(IntakeMetadata::NOT_APPLICABLE, $m->stateOf('total_supply'));
        self::assertSame(
            IntakeMetadata::STATE_COMPLETE,
            $m->state(),
            'an unreachable field is not an outstanding shortfall'
        );
        self::assertSame(2, $m->applicableCount(), 'name and image are the applicable pair');
    }

    /**
     * ⚠ THIS IS THE MUTATION TARGET. Flip `total_supply` back to applicable and
     * this fails, because the field can never resolve.
     */
    public function testCompletenessDependsOnSupplyBeingNotApplicable(): void
    {
        $m = $this->metadataFor(4200, 1);

        self::assertNotNull($m);
        self::assertSame(
            IntakeMetadata::STATE_COMPLETE,
            $m->state(),
            'if this is partial, total_supply has been made applicable again and can never resolve'
        );
    }

    /** A FAILED applicable field still degrades the state under the old rules. */
    public function testAMissingApplicableFieldIsStillPartial(): void
    {
        $f = $this->fetcher();
        $asset = self::collectionAsset();
        unset($asset['content']['links']['image']); // image absent
        $f->asset = $asset;

        $m = (new SolanaContractProbe($f))
            ->validate(self::MINT, new ProviderRequestBudget(3, 30))
            ->metadata();

        self::assertNotNull($m);
        self::assertSame(IntakeMetadata::ABSENT, $m->stateOf('image_url'));
        self::assertSame(
            IntakeMetadata::STATE_COMPLETE,
            $m->state(),
            'ABSENT is an answer, so the read is still complete'
        );
    }

    public function testAFailedMetadataReadIsStillUnavailable(): void
    {
        $f = $this->fetcher();
        $f->assetKind = 'transport';

        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(3, 30));

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertFalse($v->mayPersist());
    }

    // ── 3. ⚠⚠⚠ `total` and `limit` have NO effect on supply ─────────────

    /** @return array<string, array{0: mixed, 1: mixed}> total, limit */
    public static function everyTotalAndLimitCombination(): array
    {
        return [
            'live-measured: limit 1'   => [1, 1],
            'live-measured: limit 5'   => [5, 5],
            'live-measured: limit 20'  => [20, 20],
            'limit 0'                  => [0, 0],
            'negative limit'           => [4200, -1],
            'negative total'           => [-5, 1],
            'limit 2'                  => [4200, 2],
            'default page limit 500'   => [4200, 500],
            'the old accepted shape'   => [4200, 1],
            'huge total'               => [PHP_INT_MAX, 1],
            'string total'             => ['4200', 1],
            'float total'              => [4200.5, 1],
            'null total'               => [null, 1],
            'array total'              => [[4200], 1],
            'string limit'             => [4200, '1'],
            'null limit'               => [4200, null],
            'missing limit'            => [4200, FakeSolanaFetcherForSupply::OMIT],
            'missing total'            => [FakeSolanaFetcherForSupply::OMIT, 1],
            'both missing'             => [FakeSolanaFetcherForSupply::OMIT, FakeSolanaFetcherForSupply::OMIT],
        ];
    }

    /**
     * ⚠⚠⚠ THE CORE GUARANTEE. No `total`/`limit` pair — including the shape the
     * old heuristic accepted (`total 4200`, `limit 1`) — may yield a known
     * supply or disturb completeness.
     */
    #[DataProvider('everyTotalAndLimitCombination')]
    public function testNoTotalOrLimitEverProducesAKnownSupply(mixed $total, mixed $limit): void
    {
        $m = $this->metadataFor($total, $limit);

        self::assertNotNull($m);
        self::assertSame(
            IntakeMetadata::NOT_APPLICABLE,
            $m->stateOf('total_supply'),
            'the group response must have no influence on supply'
        );
        self::assertNull($m->valueOf('total_supply'));
        self::assertArrayNotHasKey('total_supply', $m->writableFields());
        self::assertSame(
            IntakeMetadata::STATE_COMPLETE,
            $m->state(),
            'an arbitrary total must not change metadata completeness either'
        );
    }

    /** And the verdict itself is unaffected. */
    #[DataProvider('everyTotalAndLimitCombination')]
    public function testTheVerdictIsUnaffectedByTotalAndLimit(mixed $total, mixed $limit): void
    {
        $f = $this->fetcher($total, $limit);
        $v = (new SolanaContractProbe($f))->validate(self::MINT, new ProviderRequestBudget(3, 30));

        self::assertSame(ContractValidationVerdict::VALID, $v->state());
        self::assertTrue($v->mayPersist());
    }

    /**
     * ⚠⚠ The page count must never be WRITTEN. Asserted on the writable set,
     * which is what reaches the repository — a value present but unwritable
     * would still be a latent leak into a future writer.
     */
    #[DataProvider('everyTotalAndLimitCombination')]
    public function testThePageCountIsNeverWritable(mixed $total, mixed $limit): void
    {
        $m = $this->metadataFor($total, $limit);

        self::assertNotNull($m);
        $writable = $m->writableFields();
        self::assertArrayNotHasKey('total_supply', $writable);
        if (is_int($total)) {
            self::assertNotContains($total, $writable, 'the returned page count reached the writable set');
        }
    }

    /** The edition-print decoy is never used either. */
    public function testTheEditionPrintSupplyIsNeverUsed(): void
    {
        $m = $this->metadataFor(1, 1);

        self::assertNotNull($m);
        self::assertNotSame(777, $m->valueOf('total_supply'));
        self::assertNull($m->valueOf('total_supply'));
    }

    // ── 4. The call ceiling is unchanged ────────────────────────────────

    public function testTheThreeCallCeilingIsUnchanged(): void
    {
        self::assertSame(3, ContractValidator::BUDGET_SOLANA);
    }

    public function testAValidSolanaReadStillSpendsExactlyThreeCalls(): void
    {
        $f = $this->fetcher();
        $budget = new ProviderRequestBudget(3, 30);

        (new SolanaContractProbe($f))->validate(self::MINT, $budget);

        self::assertSame(1, $f->groupCalls, 'getAssetsByGroup');
        self::assertSame(1, $f->compressedCalls, 'searchAssets(compressed)');
        self::assertSame(1, $f->assetCalls, 'getAsset');
        self::assertSame(3, $budget->spent(), 'no request was added or removed');
    }

    // ── 5. The obsolete reasoning is gone from the code ─────────────────

    /**
     * ⚠ A source-level assertion, deliberately. The heuristic and its guard
     * must be REMOVED, not left behind unreferenced for a future reader to
     * revive — the whole point is that the source it read is disproven.
     */
    public function testTheSupplyHeuristicIsRemovedFromTheProbe(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../app/Domain/Onchain/Services/Validation/SolanaContractProbe.php'
        );

        foreach (
            [
                'withSupplyFromGroupTotal',
                'REQUESTED_GROUP_LIMIT',
                'SUPPLY_CEILING',
            ] as $gone
        ) {
            self::assertStringNotContainsString(
                $gone,
                $src,
                "{$gone} existed only to serve the disproven supply inference"
            );
        }

        // And no code path writes total_supply on Solana at all.
        $code = '';
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($tok) ? $tok[1] : $tok;
        }
        self::assertStringNotContainsString('total_supply', $code, 'no executable line mentions supply');
    }

    // ── 6. Nothing downstream consumes Solana supply ────────────────────

    /**
     * ⚠⚠ Ownership, eligibility, membership and provisioning must not read
     * `total_supply` — an absent supply may never gate a holder, a community or
     * an eligibility decision. Asserted across the real service tree.
     */
    public function testNoOwnershipOrProvisioningPathConsumesSupply(): void
    {
        $roots = [
            __DIR__ . '/../../app/Domain/Onchain/Services',
            __DIR__ . '/../../app/Domain/Onchain/Workers',
        ];
        $needles = ['total_supply'];
        $interesting = '~(Ownership|Eligib|Member|Provision|Gate|Holder|Revoke)~i';

        $scanned = 0;
        $hits = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $f) {
                if (!$f instanceof \SplFileInfo || $f->getExtension() !== 'php') {
                    continue;
                }
                if (!preg_match($interesting, $f->getFilename())) {
                    continue;
                }
                $scanned++;
                $src = (string) file_get_contents($f->getPathname());
                $code = '';
                foreach (token_get_all($src) as $tok) {
                    if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= is_array($tok) ? $tok[1] : $tok;
                }
                foreach ($needles as $n) {
                    if (str_contains($code, $n)) {
                        $hits[] = $f->getFilename();
                    }
                }
            }
        }

        self::assertGreaterThan(5, $scanned, 'the scan must actually reach the ownership/provisioning tree');
        self::assertSame(
            [],
            $hits,
            'no ownership, eligibility, membership or provisioning path may read total_supply'
        );
    }
}

/**
 * Fetcher double whose group response can carry ANY `total`/`limit`, including
 * absent ones, so the "no influence" claim is tested against shapes a provider
 * could really return.
 */
class FakeSolanaFetcherForSupply extends SolanaFetcher
{
    public const OMIT = '__omit__';

    public int $groupCalls = 0;
    public int $compressedCalls = 0;
    public int $assetCalls = 0;

    /** @var list<array<string, mixed>> */
    public array $groupItems = [];
    public mixed $groupTotal = 1;
    public mixed $groupLimit = 1;

    /** @var list<array<string, mixed>> */
    public array $compressedItems = [];
    public ?string $compressedKind = null;

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
        $result = ['page' => 1, 'items' => $this->groupItems];
        if ($this->groupTotal !== self::OMIT) {
            $result['total'] = $this->groupTotal;
        }
        if ($this->groupLimit !== self::OMIT) {
            $result['limit'] = $this->groupLimit;
        }

        return ['ok' => true, 'result' => $result, 'kind' => 'none'];
    }

    public function compressedMembersExistResult(string $collectionMint): array
    {
        $this->compressedCalls++;
        if ($this->compressedKind !== null) {
            return ['ok' => false, 'result' => null, 'kind' => $this->compressedKind];
        }

        return [
            'ok'     => true,
            'result' => ['total' => count($this->compressedItems), 'limit' => 1, 'page' => 1, 'items' => $this->compressedItems],
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
