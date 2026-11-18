<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\EvmFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-278: EvmFetcher::count_erc1155_holdings reads a wallet's CURRENT holdings
 * of one ERC-1155 contract from Alchemy NFT API v3 getNFTsForOwner. Only the
 * provider's complete "owns none" may be a zero; every failure or odd body is
 * null (UNKNOWN), which never revokes.
 */
#[CoversClass(EvmFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EvmFetcherErc1155OwnershipTest extends TestCase
{
    private const WALLET   = '0x1111111111111111111111111111111111111111';
    private const CONTRACT = '0xAbCdEf0000000000000000000000000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        if (!function_exists('add_query_arg')) {
            eval('function add_query_arg(array $args, string $url): string { return $url . "?" . http_build_query($args); }');
        }
        // V1FetchFailureTracker counts attempts in the object cache.
        if (!function_exists('wp_cache_incr')) {
            eval('function wp_cache_incr(string $key, int $offset = 1, string $group = "") { return false; }');
        }
        if (!function_exists('wp_cache_add')) {
            eval('function wp_cache_add(string $key, $data, string $group = "", int $expire = 0): bool { return true; }');
        }
        \BCC\Trust\Onchain\Support\ApiRetry::reset();
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::reset();
        \BCC\Core\Log\Logger::reset();
    }

    private function fetcher(string $rpcUrl = 'https://eth-mainnet.g.alchemy.com/v2/testkey'): EvmFetcher
    {
        return new EvmFetcher((object) [
            'id'         => 1,
            'slug'       => 'ethereum',
            'chain_type' => 'evm',
            'rpc_url'    => $rpcUrl,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function respond(array $payload): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = ['body' => (string) json_encode($payload)];
    }

    public function testAnEmptyFilteredReadIsAnExactZero(): void
    {
        $this->respond(['ownedNfts' => [], 'totalCount' => 0, 'pageKey' => null]);

        $count = $this->fetcher()->count_erc1155_holdings(self::WALLET, self::CONTRACT);

        self::assertNotNull($count);
        self::assertSame(0, $count->count);
        self::assertTrue($count->complete, 'the provider read every holding of the contract');
    }

    public function testAPositiveCountIsALowerBoundOnTheBalance(): void
    {
        // totalCount counts distinct token ids, not the summed balance.
        $this->respond(['ownedNfts' => [['tokenId' => '5', 'balance' => '2']], 'totalCount' => 1]);

        $count = $this->fetcher()->count_erc1155_holdings(self::WALLET, self::CONTRACT);

        self::assertNotNull($count);
        self::assertSame(1, $count->count);
        self::assertFalse($count->complete);
    }

    public function testTheRequestAsksForOneContractWithoutMetadataOrSpamFiltering(): void
    {
        $this->respond(['ownedNfts' => [], 'totalCount' => 0]);

        $this->fetcher()->count_erc1155_holdings(self::WALLET, self::CONTRACT);

        $url = (string) (\BCC\Trust\Onchain\Support\ApiRetry::$calls[0]['url'] ?? \BCC\Trust\Onchain\Support\ApiRetry::$calls[0][0] ?? '');
        self::assertStringContainsString('/nft/v3/testkey/getNFTsForOwner?', $url);
        self::assertStringContainsString('owner=' . self::WALLET, $url);
        self::assertStringContainsString(rawurlencode('contractAddresses[]') . '=' . strtolower(self::CONTRACT), $url);
        self::assertStringContainsString('withMetadata=false', $url);
        self::assertStringContainsString('pageSize=1', $url);
        self::assertStringNotContainsString('excludeSpam', $url, 'a spam-flagged gate contract must still be counted');
        self::assertSame([480], \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$cuAdds, 'charged to the shared CU gauge');
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function oddBodies(): array
    {
        return [
            'no totalCount'          => [['ownedNfts' => []]],
            'string totalCount'      => [['ownedNfts' => [], 'totalCount' => '0']],
            'negative totalCount'    => [['ownedNfts' => [['tokenId' => '1']], 'totalCount' => -1]],
            'no ownedNfts'           => [['totalCount' => 0]],
            'ownedNfts not a list'   => [['ownedNfts' => ['a' => 1], 'totalCount' => 1]],
            'zero but tokens listed' => [['ownedNfts' => [['tokenId' => '1']], 'totalCount' => 0]],
            'count but none listed'  => [['ownedNfts' => [], 'totalCount' => 3]],
            'error envelope'         => [['error' => 'rate limited']],
        ];
    }

    /** @param array<string, mixed> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('oddBodies')]
    public function testAnOddBodyIsNoAnswerNeverAZero(array $body): void
    {
        $this->respond($body);

        self::assertNull($this->fetcher()->count_erc1155_holdings(self::WALLET, self::CONTRACT));
    }

    public function testATransportFailureIsNoAnswer(): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('cURL error 28');

        self::assertNull($this->fetcher()->count_erc1155_holdings(self::WALLET, self::CONTRACT));
    }

    public function testNothingIsAskedForANonEvmAddressOrWithoutACredentialOrCuBudget(): void
    {
        self::assertNull($this->fetcher()->count_erc1155_holdings('not-an-address', self::CONTRACT));
        self::assertNull($this->fetcher()->count_erc1155_holdings(self::WALLET, 'cosmos1abc'));
        self::assertNull($this->fetcher('https://eth.example/v2/')->count_erc1155_holdings(self::WALLET, self::CONTRACT));

        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$cuRemaining = 479;
        self::assertNull($this->fetcher()->count_erc1155_holdings(self::WALLET, self::CONTRACT));

        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$calls);
    }
}
