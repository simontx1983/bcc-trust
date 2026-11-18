<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Services\SolanaCollectionAuthority;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-339: a Solana collection creator claim is proved by the CURRENT update
 * authority — a DAS getAsset `authorities[]` entry with scope `full` on the
 * collection mint. The only decided no (false) is a DIFFERENT real address
 * holding full authority; everything else — outages, unreadable answers, a
 * not-found mint and a renounced authority — is null (unknown), which never
 * grants and never revokes (F-14 decision A, as the EVM owner() check).
 */
#[CoversClass(SolanaCollectionAuthority::class)]
final class SolanaCollectionAuthorityTest extends TestCase
{
    private const WALLET = '7xKXtg2CW87d97TXJSDpbD5jBkheTqA83TZRuJosgAsU';
    private const OTHER  = '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM';
    private const MINT   = 'J1S9H3QjnRtBbbuD4HjPV6RpRhwuk4zKbxsnCHuTgh9w';

    private FakeSolanaFetcherForAuthority $fetcher;

    protected function setUp(): void
    {
        $this->fetcher = new FakeSolanaFetcherForAuthority();
    }

    /** @param list<mixed> $authorities */
    private function withAuthorities(array $authorities): void
    {
        $this->fetcher->asset = ['id' => self::MINT, 'authorities' => $authorities];
    }

    private function check(string $wallet = self::WALLET, string $mint = self::MINT): ?bool
    {
        return (new SolanaCollectionAuthority($this->fetcher))->isAuthority($wallet, $mint);
    }

    public function testAFullAuthorityIsTheCreator(): void
    {
        $this->withAuthorities([['address' => self::WALLET, 'scopes' => ['full']]]);

        self::assertTrue($this->check());
        self::assertSame([self::MINT], $this->fetcher->assetCalls, 'one getAsset, on the collection mint');
    }

    public function testItFindsTheWalletAmongSeveralAuthorities(): void
    {
        $this->withAuthorities([
            ['address' => self::OTHER, 'scopes' => ['full']],
            ['address' => self::WALLET, 'scopes' => ['metadata', 'full']],
        ]);

        self::assertTrue($this->check());
    }

    public function testAnotherWalletsAuthorityIsADecidedNo(): void
    {
        $this->withAuthorities([['address' => self::OTHER, 'scopes' => ['full']]]);

        self::assertFalse($this->check());
    }

    public function testANarrowerScopeProvesNothingEitherWay(): void
    {
        $this->withAuthorities([['address' => self::WALLET, 'scopes' => ['metadata']]]);
        self::assertNull($this->check(), 'a delegated scope is not ownership, and nobody else holds full');

        $this->withAuthorities([['address' => self::WALLET, 'scopes' => ['metadata']], ['address' => self::OTHER, 'scopes' => ['full']]]);
        self::assertFalse($this->check(), 'someone else holds full authority');

        $this->withAuthorities([['address' => self::OTHER, 'scopes' => ['metadata']]]);
        self::assertNull($this->check(), "another wallet's delegated scope does not make it the owner");
    }

    public function testNoAuthorityListedIsRenouncedSoUnknown(): void
    {
        $this->withAuthorities([]);

        self::assertNull($this->check(), 'renounced is unknown, never "not the creator"');
    }

    /** @return array<string, array{string}> */
    public static function renouncedTo(): array
    {
        return [
            'system program' => ['11111111111111111111111111111111'],
            'incinerator'    => ['1nc1nerator11111111111111111111111111111111'],
        ];
    }

    #[DataProvider('renouncedTo')]
    public function testAnAuthorityHandedToABurnAddressIsRenouncedSoUnknown(string $burn): void
    {
        $this->withAuthorities([['address' => $burn, 'scopes' => ['full']]]);

        self::assertNull($this->check());
    }

    public function testABurnAddressDoesNotHideARealOtherHolder(): void
    {
        $this->withAuthorities([['address' => '11111111111111111111111111111111', 'scopes' => ['full']], ['address' => self::OTHER, 'scopes' => ['full']]]);

        self::assertFalse($this->check());
    }

    public function testAddressesAreCaseSensitive(): void
    {
        $this->withAuthorities([['address' => strtolower(self::WALLET), 'scopes' => ['full']]]);

        self::assertFalse($this->check(), 'base58 is case-sensitive: a case-folded address is a different key');
    }

    public function testANotFoundMintIsUnknownNeverANo(): void
    {
        $this->fetcher->assetKind = 'not_found';

        self::assertNull($this->check(), 'a collection we can no longer read must never revoke a creator');
    }

    /** @return array<string, array{string}> */
    public static function outages(): array
    {
        return [
            'no helius key' => ['credentials_missing'],
            'transport'     => ['transport'],
            'non-2xx'       => ['http_error'],
            'rate limited'  => ['rate_limited'],
            'malformed'     => ['malformed'],
            'unknown kind'  => ['something_new'],
        ];
    }

    #[DataProvider('outages')]
    public function testAnOutageIsIndeterminateNeverANo(string $kind): void
    {
        $this->fetcher->assetKind = $kind;

        self::assertNull($this->check());
    }

    public function testAnOkAnswerWithNoAssetIsIndeterminate(): void
    {
        $this->fetcher->asset = null;

        self::assertNull($this->check());
    }

    public function testAResponseWithoutAuthoritiesIsIndeterminate(): void
    {
        $this->fetcher->asset = ['id' => self::MINT];

        self::assertNull($this->check());
    }

    public function testAnAnswerAboutAnotherAssetIsIndeterminate(): void
    {
        $this->fetcher->asset = ['id' => self::OTHER, 'authorities' => [['address' => self::WALLET, 'scopes' => ['full']]]];

        self::assertNull($this->check());
    }

    public function testAnUnreadableEntryLeavesANoUndecided(): void
    {
        $this->withAuthorities([['address' => self::OTHER, 'scopes' => ['full']], ['address' => self::WALLET]]);

        self::assertNull($this->check(), 'the unreadable entry could have been this wallet');
    }

    public function testAReadableMatchStillWinsOverAnUnreadableEntry(): void
    {
        $this->withAuthorities(['garbage', ['address' => self::WALLET, 'scopes' => ['full']]]);

        self::assertTrue($this->check());
    }

    public function testNoWalletIsANoWithoutAProviderCall(): void
    {
        self::assertFalse($this->check(''));
        self::assertSame([], $this->fetcher->assetCalls);
    }

    public function testNoMintIsIndeterminateWithoutAProviderCall(): void
    {
        self::assertNull($this->check(self::WALLET, '  '));
        self::assertSame([], $this->fetcher->assetCalls);
    }
}

/** Fetcher double: no transport, a scripted getAsset answer. */
class FakeSolanaFetcherForAuthority extends SolanaFetcher
{
    /** @var list<string> */
    public array $assetCalls = [];

    /** @var array<string, mixed>|null */
    public ?array $asset = null;
    public string $assetKind = 'none';

    public function __construct()
    {
        // No transport in these tests.
    }

    public function assetResult(string $mint): array
    {
        $this->assetCalls[] = $mint;
        if ($this->assetKind !== 'none') {
            return ['ok' => false, 'result' => null, 'kind' => $this->assetKind];
        }

        return ['ok' => true, 'result' => $this->asset, 'kind' => 'none'];
    }
}
