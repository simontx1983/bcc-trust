<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\Validation\EvmContractProbe;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\ContractValidationVerdict;
use BCC\Trust\Onchain\ValueObjects\IntakeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * EVM targeted validation — Ethereum and Base at launch (DECISION 7).
 *
 * ── THE TWO THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. An ERC-1155 silently accepted as ERC-721. Holder gating proves
 *     ownership with a per-contract balance read; on a 1155 that balance is
 *     per-token-id, so a 721-shaped check would admit someone holding a
 *     different token in the same contract. That provisions a community with
 *     the wrong members.
 *  2. A missing Alchemy key producing an accepted collection with empty
 *     metadata. An empty-but-verified-looking row is worse than no row: it
 *     looks reviewed.
 */
#[CoversClass(EvmContractProbe::class)]
final class EvmContractProbeTest extends TestCase
{
    private const CONTRACT = '0x1234567890abcdef1234567890abcdef12345678';

    /** ABI-encoded `true` / `false`. */
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
        self::assertSame('ERC-1155', $v->standard(), 'the standard is recorded so the refusal can explain itself');
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_STANDARD_DEFERRED));
    }

    public function testAnErc1155IsNeverReportedAsErc721(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [
            self::IFACE_721  => self::FALSE_WORD,
            self::IFACE_1155 => self::TRUE_WORD,
        ];

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertNotSame('ERC-721', $v->standard());
    }

    // ── Decided negatives ───────────────────────────────────────────────

    public function testAContractSupportingNeitherInterfaceIsInvalid(): void
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

    public function testAnEoaWithNoCodeIsInvalidNotUnavailable(): void
    {
        // `eth_call` against an address with no code returns bare `0x`.
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [
            self::IFACE_721  => '0x',
            self::IFACE_1155 => '0x',
        ];

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::INVALID, $v->state());
        self::assertTrue($v->isDecided(), 'an empty return IS an answer: there is no contract here');
    }

    // ── ⚠⚠ Fail closed ──────────────────────────────────────────────────

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
        // contract that reverts has told us nothing — reading it as "false"
        // would manufacture a negative verdict.
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->ethCallKind = 'rpc_error';

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
    }

    public function testAMalformedResponseIsUnavailableNotInvalid(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->ethCallKind = 'malformed';

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $v->state());
        self::assertTrue($v->hasEvidence(ContractValidationVerdict::EV_MALFORMED_RESPONSE));
    }

    /**
     * ⚠ The stated brief: "do not accept an empty but apparently verified
     * collection."
     */
    public function testAValidContractWhoseMetadataFailsKeepsEveryFieldUnknown(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [self::IFACE_721 => self::TRUE_WORD];
        $fetcher->metadataKind = 'credentials_missing';

        $v = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());
        $m = $v->metadata();

        self::assertSame(ContractValidationVerdict::VALID, $v->state(), 'the standard WAS proved');
        self::assertInstanceOf(IntakeMetadata::class, $m);
        self::assertSame(
            IntakeMetadata::STATE_UNAVAILABLE,
            $m->state(),
            'a failed metadata read must be recorded as unavailable, not as a collection with no name'
        );
        self::assertSame([], $m->writableFields(), 'nothing may be written from a failed read');
        foreach (IntakeMetadata::FIELDS as $f) {
            self::assertSame(IntakeMetadata::UNKNOWN, $m->stateOf($f));
        }
    }

    public function testAnAnsweredMetadataReadMissingFieldsRecordsThemAbsentNotUnknown(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [self::IFACE_721 => self::TRUE_WORD];
        // Alchemy answered, but this collection has no symbol and no image.
        $fetcher->metadata = ['name' => 'Sparse Collection'];

        $m = (new EvmContractProbe($fetcher))->validate(self::CONTRACT, $this->budget())->metadata();

        self::assertInstanceOf(IntakeMetadata::class, $m);
        self::assertSame(IntakeMetadata::KNOWN, $m->stateOf('name'));
        self::assertSame(IntakeMetadata::ABSENT, $m->stateOf('symbol'));
        self::assertSame(IntakeMetadata::ABSENT, $m->stateOf('image_url'));
        // Description is never attempted on EVM, so it stays UNKNOWN.
        self::assertSame(IntakeMetadata::UNKNOWN, $m->stateOf('description'));
        self::assertSame(IntakeMetadata::STATE_PARTIAL, $m->state());
    }

    // ── No price data, ever ─────────────────────────────────────────────

    public function testNoPriceOrMarketFieldIsReadFromTheProviderResponse(): void
    {
        $fetcher = new FakeEvmFetcherForValidation();
        $fetcher->interfaceAnswers = [self::IFACE_721 => self::TRUE_WORD];
        $fetcher->metadata = [
            'name'            => 'Priced Collection',
            'openSeaMetadata' => [
                'imageUrl'       => 'https://example.test/i.png',
                'floorPrice'     => 12.5,
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
    public int $ethCalls = 0;
    public int $metadataCalls = 0;

    /** interfaceId => hex result */
    public array $interfaceAnswers = [];

    /** Non-'none' forces every eth_call to fail with this kind. */
    public string $ethCallKind = 'none';

    public string $metadataKind = 'none';

    /** @var array<string, mixed>|null */
    public ?array $metadata = null;

    public function __construct()
    {
        // No transport in these tests; a real constructor wants a chain row.
    }

    public function ethCallResult(string $to, string $data): array
    {
        $this->ethCalls++;

        if ($this->ethCallKind !== 'none') {
            return ['ok' => false, 'result' => null, 'kind' => $this->ethCallKind];
        }

        // The bytes4 interface id is the first 4 bytes of the argument.
        $iface = '0x' . substr($data, 10, 8);

        return ['ok' => true, 'result' => $this->interfaceAnswers[$iface] ?? '0x', 'kind' => 'none'];
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
