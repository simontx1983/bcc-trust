<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-154: the anonymous NFT-piece route must be cheap to refuse.
 *
 * ── ⚠ THE THINGS THAT MUST NOT HAPPEN ───────────────────────────────────
 *  1. NO THROTTLE. The contract (§4.17) documents 60/min/IP; the route
 *     enforced nothing, so each request could cost an upstream call.
 *  2. A COSMOS CONTRACT NOBODY INDEXED REACHES THE LCD. Cosmos used to be
 *     exempt from the "collection must be indexed" 404, so any bech32
 *     address drove read-time smart queries.
 *  3. A MALFORMED token_id BECOMES AN UPSTREAM CALL. Only a 255-byte cap
 *     existed; now each chain has a shape.
 *  4. AN UPSTREAM IMAGE URL RENDERS UNCHECKED. Token-level image_url and
 *     image_url_thumb now pass CollectionMetadataRules::sanitizeImageUrl,
 *     even from a FRESH cache row written before the rule.
 *  5. THE GATES REFUSE TOO MUCH. Valid ids on every chain still reach the
 *     collection lookup.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftPieceRouteGateTest extends TestCase
{
    private const EVM_CONTRACT    = '0x1234567890abcdef1234567890abcdef12345678';
    private const SOL_MINT        = 'So11111111111111111111111111111111111111112';
    private const COSMOS_CONTRACT = 'cosmos10ww8p6v6kfz6ghnqvus9gfxcyxaxazh3fvyufv3w5a2z9yfe4rjq8894ax';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-piece-gate-stubs.php';

        $GLOBALS['__bcc_np'] = [
            'throttle_denied'  => false,
            'throttle_calls'   => [],
            'chains' => [
                'ethereum' => (object) ['id' => 1, 'slug' => 'ethereum', 'chain_type' => 'evm', 'marketplace_template' => ''],
                'solana'   => (object) ['id' => 2, 'slug' => 'solana', 'chain_type' => 'solana', 'marketplace_template' => ''],
                'cosmos'   => (object) ['id' => 3, 'slug' => 'cosmos', 'chain_type' => 'cosmos', 'marketplace_template' => ''],
            ],
            'collections'      => [],
            'collection_calls' => [],
            'fetcher_calls'    => 0,
            'pieces'           => [],
        ];
    }

    private static function call(string $chain, string $contract, string $tokenId): \WP_REST_Response
    {
        $req = new \WP_REST_Request([
            'chainSlug'       => $chain,
            'contractAddress' => $contract,
            'tokenId'         => $tokenId,
        ]);
        return (new \BCC\Trust\Onchain\REST\NftPieceEndpoint())->getPiece($req);
    }

    /** @return array{0:int,1:string} */
    private static function statusAndCode(\WP_REST_Response $r): array
    {
        $body = $r->get_data();
        return [$r->get_status(), is_array($body) && isset($body['error']['code']) ? (string) $body['error']['code'] : 'ok'];
    }

    // ── 1. throttle ──────────────────────────────────────────────────────

    public function testThrottleRunsFirstAndRefusesBeforeAnyLookup(): void
    {
        $GLOBALS['__bcc_np']['throttle_denied'] = true;

        $r = self::call('ethereum', self::EVM_CONTRACT, '1');

        self::assertSame([429, 'bcc_rate_limited'], self::statusAndCode($r));
        self::assertSame(['nft_piece'], $GLOBALS['__bcc_np']['throttle_calls']);
        self::assertSame([], $GLOBALS['__bcc_np']['collection_calls'], 'nothing past the throttle may run');
    }

    // ── 2. token_id shape per chain ──────────────────────────────────────

    /** @return iterable<string, array{string, string, string}> */
    public static function malformed(): iterable
    {
        yield 'evm letters'         => ['ethereum', self::EVM_CONTRACT, 'abc'];
        yield 'evm hex'             => ['ethereum', self::EVM_CONTRACT, '0x1f'];
        yield 'evm 79 digits'       => ['ethereum', self::EVM_CONTRACT, str_repeat('9', 79)];
        yield 'evm negative'        => ['ethereum', self::EVM_CONTRACT, '-1'];
        yield 'evm trailing nl'     => ['ethereum', self::EVM_CONTRACT, "1\n"];
        yield 'solana short'        => ['solana', self::SOL_MINT, 'abc'];
        yield 'solana zero char'    => ['solana', self::SOL_MINT, '0' . substr(self::SOL_MINT, 1)];
        yield 'cosmos 129 chars'    => ['cosmos', self::COSMOS_CONTRACT, str_repeat('a', 129)];
        yield 'cosmos space'        => ['cosmos', self::COSMOS_CONTRACT, 'token 1'];
        yield 'cosmos control char' => ['cosmos', self::COSMOS_CONTRACT, "tok\x01en"];
        yield 'cosmos zero width'   => ['cosmos', self::COSMOS_CONTRACT, "tok\u{200B}en"];
    }

    #[DataProvider('malformed')]
    public function testMalformedTokenIdsAreRefusedBeforeAnyLookup(string $chain, string $contract, string $tokenId): void
    {
        $r = self::call($chain, $contract, $tokenId);

        self::assertSame([422, 'bcc_invalid_request'], self::statusAndCode($r));
        self::assertSame([], $GLOBALS['__bcc_np']['collection_calls']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function wellFormed(): iterable
    {
        yield 'evm decimal'       => ['ethereum', self::EVM_CONTRACT, '1234'];
        yield 'evm uint256 max'   => ['ethereum', self::EVM_CONTRACT, str_repeat('9', 78)];
        yield 'solana mint'       => ['solana', self::SOL_MINT, self::SOL_MINT];
        yield 'cosmos numeric'    => ['cosmos', self::COSMOS_CONTRACT, '1'];
        yield 'cosmos slug-ish'   => ['cosmos', self::COSMOS_CONTRACT, 'mad-scientist.#042'];
        yield 'cosmos unicode'    => ['cosmos', self::COSMOS_CONTRACT, 'Ünïcødé-7'];
        yield 'cosmos 128 chars'  => ['cosmos', self::COSMOS_CONTRACT, str_repeat('a', 128)];
    }

    #[DataProvider('wellFormed')]
    public function testWellFormedTokenIdsReachTheCollectionLookup(string $chain, string $contract, string $tokenId): void
    {
        $r = self::call($chain, $contract, $tokenId);

        self::assertCount(1, $GLOBALS['__bcc_np']['collection_calls'], 'a valid id must not be refused by the shape gate');
        self::assertSame([404, 'bcc_not_found'], self::statusAndCode($r), 'no collection row in this fixture');
    }

    // ── 3. Cosmos must be indexed ────────────────────────────────────────

    public function testAnUnindexedCosmosContractIs404AndNeverReachesTheLcd(): void
    {
        $r = self::call('cosmos', self::COSMOS_CONTRACT, '1');

        self::assertSame([404, 'bcc_not_found'], self::statusAndCode($r));
        self::assertSame(0, $GLOBALS['__bcc_np']['fetcher_calls'], 'no fetcher may be built for an unindexed contract');
    }

    // ── 4. token-level image sanitising ──────────────────────────────────

    public function testTokenImagesPassTheCollectionImageRuleEvenFromAFreshCacheRow(): void
    {
        $GLOBALS['__bcc_np']['collections']['1|' . self::EVM_CONTRACT] = (object) [
            'id' => 9, 'collection_name' => 'Demo', 'token_standard' => 'ERC-721', 'is_verified' => 0,
        ];
        $GLOBALS['__bcc_np']['pieces']['1|' . self::EVM_CONTRACT . '|7'] = (object) [
            'name'            => 'Piece 7',
            'description'     => null,
            'image_url'       => 'ipfs://QmImage/7.png',
            'image_url_thumb' => 'https://cdn.example/7-thumb.png',
            'attributes_json' => '[]',
            'expires_at'      => gmdate('Y-m-d H:i:s', time() + 3600),
        ];

        $r = self::call('ethereum', self::EVM_CONTRACT, '7');
        self::assertSame(200, $r->get_status(), var_export($r->get_data(), true));
        $data = $r->get_data()['data'] ?? $r->get_data();

        self::assertNull($data['image_url'], 'ipfs:// cannot render and has no gateway rewrite: null placeholder');
        self::assertSame('https://cdn.example/7-thumb.png', $data['image_url_thumb'], 'an https image is kept');
        self::assertSame(0, $GLOBALS['__bcc_np']['fetcher_calls'], 'a fresh cache row needs no upstream call');
    }

    public function testJavascriptAndHttpImagesAreDropped(): void
    {
        $GLOBALS['__bcc_np']['collections']['1|' . self::EVM_CONTRACT] = (object) [
            'id' => 9, 'collection_name' => 'Demo', 'token_standard' => 'ERC-721', 'is_verified' => 0,
        ];
        $GLOBALS['__bcc_np']['pieces']['1|' . self::EVM_CONTRACT . '|8'] = (object) [
            'name' => 'Piece 8', 'description' => null,
            'image_url'       => 'javascript:alert(1)',
            'image_url_thumb' => 'http://cdn.example/8.png',
            'attributes_json' => '[]',
            'expires_at'      => gmdate('Y-m-d H:i:s', time() + 3600),
        ];

        $data = self::call('ethereum', self::EVM_CONTRACT, '8')->get_data();
        $data = $data['data'] ?? $data;

        self::assertNull($data['image_url']);
        self::assertNull($data['image_url_thumb']);
    }
}
