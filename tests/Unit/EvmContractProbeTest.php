<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\Validation\EvmContractProbe;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\ContractValidationVerdict;
use BCC\Trust\Onchain\ValueObjects\IntakeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EVM targeted validation — Ethereum and Base at launch (DECISION 7).
 *
 * ── THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────────
 *  1. An ERC-1155 silently accepted as ERC-721. Holder gating proves
 *     ownership with a per-contract balance read; on a 1155 that balance is
 *     per-token-id, so a 721-shaped check would admit someone holding a
 *     different token in the same contract.
 *  2. A collection persisted when its metadata could not be read. Both launch
 *     chains are Alchemy-keyed, so a metadata failure is a configuration or
 *     provider fault — not a collection that has no name and no image.
 *  3. A malformed ERC-165 result read as an answered "no". Two of those would
 *     manufacture INVALID out of data BCC could not parse.
 */
#[CoversClass(EvmContractProbe::class)]
final class EvmContractProbeTest extends TestCase
{
    private const CONTRACT = '0x1234567890abcdef1234567890abcdef12345678';

    /** Canonical ABI-encoded booleans. */
    private const TRUE_WORD  = '0x0000000000000000000000000000000000000000000000000000000000000001';
    private const FALSE_WORD = '0x0000000000000000000000000000000000000000000000000000000000000000';

    private const IFACE_721  = '0x80ac58cd';
    private const IFACE_1155 = '0xd9b67a26';

    private function budget(int $n = 10): ProviderRequestBudget
    {
        return new ProviderRequestBudget($n, 30);
    }

    // ── ERC-721 happy path ──────────────────────────────────────────────

    public function testAnErc721ContractIsValidWithItsStandardRecorded(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [self::IFACE_721 => self::TRUE_WORD];
        $fetcher->metadata = [
            'name'            => 'Blue Collar Apes',
            'symbol'          => 'BCA',
            'totalSupply'     => '5000',
            'openSeaMetadata' => ['imageUrl' => 'https://example.test/a.png'],
        ];

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::VALID, $v->state());
        self::assertSame('ERC-721', $v->standard());
        self::assertTrue($v->mayPersist());

        $m = $v->metadata();
        self::assertInstanceOf(IntakeMetadata::class, $m);
        self::assertSame('Blue Collar Apes', $m->valueOf('name'));
        self::assertSame('BCA', $m->valueOf('symbol'));
        self::assertSame(5000, $m->valueOf('total_supply'));
        self::assertSame('https://example.test/a.png', $m->valueOf('image_url'));
    }

    public function testASecondInterfaceCallIsNotMadeOnceErc721IsConfirmed(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [self::IFACE_721 => self::TRUE_WORD];

        (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(1, $fetcher->ethCalls, 'confirming 721 makes the 1155 question pointless');
    }

    // ── ⚠⚠ ERC-1155 gating ──────────────────────────────────────────────

    public function testAnErc1155IsUnsupportedAndNeverValid(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [
            self::IFACE_721  => self::FALSE_WORD,
            self::IFACE_1155 => self::TRUE_WORD,
        ];

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNSUPPORTED, $v->state());
        self::assertFalse($v->isValid(), 'an 1155 must never be accepted');
        self::assertFalse($v->mayPersist(), 'no row, so nothing can provision a community from it');
        self::assertSame('ERC-1155', $v->standard());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_STANDARD_DEFERRED));
    }

    public function testAnErc1155IsNeverReportedAsErc721(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [
            self::IFACE_721  => self::FALSE_WORD,
            self::IFACE_1155 => self::TRUE_WORD,
        ];

        self::assertNotSame(
            'ERC-721',
            (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget())->standard()
        );
    }

    // ── Decided negatives ───────────────────────────────────────────────

    public function testOnlyCanonicalFalseOnBothInterfacesProducesADecidedNegative(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [
            self::IFACE_721  => self::FALSE_WORD,
            self::IFACE_1155 => self::FALSE_WORD,
        ];

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::INVALID, $v->state());
        self::assertTrue($v->isDecided());
    }

    /**
     * ⚠ PREMISE CORRECTED AFTER REVIEW. `eth_call` against an address with no
     * code returns bare `0x`, and this used to be read as an answered "no" →
     * INVALID. But `0x` is also what a node returns when a call could not be
     * executed, so it cannot carry a verdict about the contract.
     */
    public function testAnAddressReturningEmptyDataIsUnavailableNotInvalid(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->rawResult = '0x';

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertFalse($v->mayPersist());
    }

    // ── ⚠⚠⚠ ERC-165 return data must be canonical ───────────────────────

    /** @return array<string, array{0: string}> */
    public static function malformedInterfaceResults(): array
    {
        return [
            'empty 0x'           => ['0x'],
            'short word 0x2'     => ['0x2'],
            'truncated word'     => ['0x00000000000000000000000000000001'],
            'non-hex payload'    => ['0xzzzz'],
            'overlong word'      => ['0x' . str_repeat('0', 63) . '100'],
            'noncanonical value' => ['0x' . str_repeat('0', 62) . '02'],
        ];
    }

    /**
     * ⚠⚠⚠ A MALFORMED ERC-165 RESULT IS NOT AN ANSWERED "NO".
     *
     * Before this fix, `ethCallResult()` accepted anything starting with `0x`
     * and `supportsInterface()` treated `0x2` or a truncated word as false.
     * Two such reads (721 then 1155) produced INVALID — a negative
     * authenticity verdict manufactured from unparseable data.
     */
    #[DataProvider('malformedInterfaceResults')]
    public function testMalformedInterfaceDataIsUnavailableNotInvalid(string $raw): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->rawResult = $raw;

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(
            ContractValidationVerdict::UNAVAILABLE,
            $v->state(),
            "raw result {$raw} must not be read as an answered false"
        );
        self::assertNotSame(ContractValidationVerdict::INVALID, $v->state());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_MALFORMED_RESPONSE));
    }

    // ── ⚠⚠ Transport failures fail closed ───────────────────────────────

    public function testMissingCredentialsAreUnavailableNotInvalid(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->ethCallKind = 'credentials_missing';

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertFalse($v->isDecided());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_CREDENTIALS_MISSING));
    }

    public function testATimeoutIsUnavailableNotInvalid(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->ethCallKind = 'transport';

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_PROVIDER_TIMEOUT));
    }

    public function testAnRpcErrorIsUnavailableNotInvalid(): void
    {
        // A reverting `supportsInterface` surfaces as a JSON-RPC error. A
        // contract that reverts has told us nothing.
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->ethCallKind = 'rpc_error';

        self::assertSame(
            ContractValidationVerdict::UNAVAILABLE,
            (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget())->state()
        );
    }

    // ── ⚠⚠⚠ Metadata failure must not persist a collection ──────────────

    /** @return array<string, array{0: string, 1: string}> */
    public static function metadataFailureKinds(): array
    {
        return [
            'missing credentials' => ['credentials_missing', ContractValidationVerdict::EV_CREDENTIALS_MISSING],
            'transport timeout'   => ['transport', ContractValidationVerdict::EV_PROVIDER_TIMEOUT],
            'http error'          => ['http_error', ContractValidationVerdict::EV_PROVIDER_ERROR],
            'malformed body'      => ['malformed', ContractValidationVerdict::EV_MALFORMED_RESPONSE],
        ];
    }

    /**
     * ⚠⚠⚠ REPLACES the earlier test that expected VALID when metadata
     * retrieval failed. That verdict let `ManualCollectionIntakeService`
     * persist a row for a contract whose name, symbol, image and supply BCC
     * had never successfully read — an empty row that looks reviewed.
     */
    #[DataProvider('metadataFailureKinds')]
    public function testEveryMetadataFailureModeIsUnavailableAndPersistsNothing(
        string $kind,
        string $evidence
    ): void {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [self::IFACE_721 => self::TRUE_WORD];
        $fetcher->metadataKind = $kind;

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertFalse($v->mayPersist(), 'no row may be written from an unreadable metadata response');
        self::assertFalse($v->isDecided(), 'the standard was proved, but the collection was not');
        self::assertTrue($v->hasEvidence($evidence));
    }

    /**
     * ⚠ THE OTHER HALF OF THE RULE. A metadata response that ARRIVED and
     * simply lacks optional fields is still VALID — "the provider answered and
     * the field is absent" is not "metadata could not be obtained".
     */
    public function testAnAnsweredResponseWithAbsentOptionalFieldsIsStillValid(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [self::IFACE_721 => self::TRUE_WORD];
        $fetcher->metadata = ['name' => 'Minimal Collection'];

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::VALID, $v->state());
        self::assertTrue($v->mayPersist());
        self::assertSame(IntakeMetadata::KNOWN, $v->metadata()?->stateOf('name'));
        self::assertSame(IntakeMetadata::ABSENT, $v->metadata()?->stateOf('image_url'));
    }

    public function testAnEmptyButSuccessfullyParsedResponseIsValid(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [self::IFACE_721 => self::TRUE_WORD];
        $fetcher->metadata = [];

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::VALID, $v->state());
        foreach (['name', 'symbol', 'image_url', 'total_supply'] as $f) {
            self::assertSame(IntakeMetadata::ABSENT, $v->metadata()?->stateOf($f));
        }
    }

    // ── No price data, ever ─────────────────────────────────────────────

    public function testNoPriceOrMarketFieldIsReadFromTheProviderResponse(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [self::IFACE_721 => self::TRUE_WORD];
        $fetcher->metadata = [
            'name'            => 'Priced Collection',
            'openSeaMetadata' => [
                'imageUrl'        => 'https://example.test/i.png',
                'floorPrice'      => 12.5,
                'twitterUsername' => '@x',
            ],
        ];

        $m = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget())->metadata();

        self::assertInstanceOf(IntakeMetadata::class, $m);
        foreach ($m->writableFields() as $k => $val) {
            self::assertContains($k, IntakeMetadata::FIELDS);
            self::assertNotSame(12.5, $val);
        }
        self::assertArrayNotHasKey('floor_price', $m->writableFields());
    }

    // ── Budget ──────────────────────────────────────────────────────────

    public function testAnExhaustedBudgetMakesNoRequestAtAll(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, new ProviderRequestBudget(0, 30));

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_BUDGET_EXHAUSTED));
        self::assertSame(0, $fetcher->ethCalls);
        self::assertSame(0, $fetcher->metadataCalls);
    }

    public function testValidationNeverExceedsThreeProviderCalls(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [
            self::IFACE_721  => self::FALSE_WORD,
            self::IFACE_1155 => self::FALSE_WORD,
        ];

        (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertLessThanOrEqual(3, $fetcher->ethCalls + $fetcher->metadataCalls);
    }
}

class FakeEvmFetcherForValidation extends \BCC\Trust\Onchain\Fetchers\EvmFetcher
{
    /** Canonical ABI false — the default for an unlisted interface id. */
    public const FALSE_WORD_FAKE = '0x0000000000000000000000000000000000000000000000000000000000000000';

    public int $ethCalls = 0;
    public int $metadataCalls = 0;

    /** @var array<string, string> interfaceId => hex result */
    public array $interfaceAnswers = [];

    /** Non-'none' makes every eth_call fail with that kind. */
    public string $ethCallKind = 'none';

    /** When set, EVERY interface call returns this raw hex verbatim. */
    public ?string $rawResult = null;

    public string $metadataKind = 'none';

    /** @var array<string, mixed>|null */
    public ?array $metadata = null;

    public function __construct()
    {
        // No transport in these tests; the real constructor wants a chain row.
    }

    public function ethCallResult(string $to, string $data): array
    {
        $this->ethCalls++;

        if ($this->ethCallKind !== 'none') {
            return ['ok' => false, 'result' => null, 'kind' => $this->ethCallKind];
        }

        if ($this->rawResult !== null) {
            // ⚠ Deliberately bypasses the fetcher's own shape check so the
            // PROBE's handling of malformed data is what gets tested.
            return ['ok' => true, 'result' => $this->rawResult, 'kind' => 'none'];
        }

        $iface = '0x' . substr($data, 10, 8);

        return ['ok' => true, 'result' => $this->interfaceAnswers[$iface] ?? self::FALSE_WORD_FAKE, 'kind' => 'none'];
    }

    public function contractMetadataResult(string $contract): array
    {
        $this->metadataCalls++;

        if ($this->metadataKind !== 'none') {
            return ['ok' => false, 'data' => null, 'kind' => $this->metadataKind];
        }

        return ['ok' => true, 'data' => $this->metadata ?? [], 'kind' => 'none'];
    }
}
