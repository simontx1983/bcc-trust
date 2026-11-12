<?php
/**
 * F-57 — the NFT piece route's UPSTREAM budget.
 *
 * F-154 (NftPieceRouteGateTest) throttles every request per caller. That
 * did not bound what reaches a provider: a cache MISS on a token that does
 * not exist writes nothing, so each repeat of a bogus token_id was a fresh
 * upstream call, and spread over many callers there was no ceiling.
 *
 * These tests pin the F-57 contract, using the same stubs as the F-154
 * test so the production classes run unchanged:
 *   - cache hits are never charged;
 *   - an upstream fetch is charged per caller, then site-wide, BEFORE the
 *     fetcher is reached;
 *   - per-caller refusal → 429 bcc_rate_limited, and the site-wide counter
 *     is not charged;
 *   - site-wide refusal → 503 bcc_upstream_unavailable;
 *   - a refused refresh with a stale row serves the stale row;
 *   - Cosmos (no pieces cache) is charged before any LCD query.
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
// CosmosFetcher's class constants reference this WordPress constant, so the
// class cannot load in the unit bootstrap without it (Cosmos tests below).
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftPieceUpstreamBudgetTest extends TestCase
{
    private const EVM_CONTRACT    = '0x1234567890abcdef1234567890abcdef12345678';
    private const COSMOS_CONTRACT = 'cosmos10ww8p6v6kfz6ghnqvus9gfxcyxaxazh3fvyufv3w5a2z9yfe4rjq8894ax';

    private const REQUEST_KEY  = 'nft_piece|';
    private const CALLER_KEY   = 'nft_piece_upstream|';
    private const GLOBAL_KEY   = 'nft_piece_upstream|nft_piece_upstream:global';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-piece-gate-stubs.php';

        $GLOBALS['__bcc_np'] = [
            'throttle_denied'  => false,
            'throttle_calls'   => [],
            'throttle_keys'    => [],
            'throttle_deny'    => [],
            'chains' => [
                'ethereum' => (object) ['id' => 1, 'slug' => 'ethereum', 'chain_type' => 'evm', 'marketplace_template' => ''],
                'cosmos'   => (object) ['id' => 3, 'slug' => 'cosmos', 'chain_type' => 'cosmos', 'marketplace_template' => ''],
            ],
            'collections' => [
                '1|' . self::EVM_CONTRACT => (object) [
                    'id' => 9, 'collection_name' => 'Demo', 'token_standard' => 'ERC-721', 'is_verified' => 0,
                ],
                '3|' . self::COSMOS_CONTRACT => (object) [
                    'id' => 10, 'collection_name' => 'Cosmo', 'token_standard' => 'CW-721', 'is_verified' => 0,
                ],
            ],
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

    private static function cachePiece(string $tokenId, int $expiresInSeconds): void
    {
        $GLOBALS['__bcc_np']['pieces']['1|' . self::EVM_CONTRACT . '|' . $tokenId] = (object) [
            'name'            => 'Piece ' . $tokenId,
            'description'     => null,
            'image_url'       => 'https://cdn.example/' . $tokenId . '.png',
            'image_url_thumb' => null,
            'attributes_json' => '[]',
            'expires_at'      => gmdate('Y-m-d H:i:s', time() + $expiresInSeconds),
        ];
    }

    // ── 1. cache hits are free ───────────────────────────────────────────

    public function testAFreshCacheRowIsNeverChargedAgainstTheUpstreamBudget(): void
    {
        self::cachePiece('7', 3600);

        $r = self::call('ethereum', self::EVM_CONTRACT, '7');

        self::assertSame(200, $r->get_status(), var_export($r->get_data(), true));
        self::assertSame([self::REQUEST_KEY], $GLOBALS['__bcc_np']['throttle_keys'], 'only F-154\'s per-request throttle runs on a hit');
        self::assertSame(0, $GLOBALS['__bcc_np']['fetcher_calls']);
    }

    // ── 2. a miss is charged, both layers, before the fetcher ───────────

    public function testAMissIsChargedPerCallerThenSiteWideBeforeReachingUpstream(): void
    {
        // A non-driver fetcher: the builder reaches FetcherFactory (proving
        // the budget allowed the call) and then answers "no metadata".
        $GLOBALS['__bcc_np']['fetcher_object'] = true;

        $r = self::call('ethereum', self::EVM_CONTRACT, '404');

        self::assertSame([404, 'bcc_not_found'], self::statusAndCode($r));
        self::assertSame(
            [self::REQUEST_KEY, self::CALLER_KEY, self::GLOBAL_KEY],
            $GLOBALS['__bcc_np']['throttle_keys'],
            'request throttle, then the per-caller upstream budget, then the site-wide one — in that order'
        );
        self::assertSame(1, $GLOBALS['__bcc_np']['fetcher_calls'], 'an allowed miss does reach upstream');
    }

    // ── 3. per-caller refusal ────────────────────────────────────────────

    public function testAnExhaustedCallerBudgetIs429BeforeUpstreamAndSparesTheSiteWideBudget(): void
    {
        $GLOBALS['__bcc_np']['throttle_deny'] = [self::CALLER_KEY];

        $r = self::call('ethereum', self::EVM_CONTRACT, '404');

        self::assertSame([429, 'bcc_rate_limited'], self::statusAndCode($r));
        self::assertSame(0, $GLOBALS['__bcc_np']['fetcher_calls'], 'no upstream call after a refusal');
        self::assertNotContains(
            self::GLOBAL_KEY,
            $GLOBALS['__bcc_np']['throttle_keys'],
            'a caller over their own limit must not keep spending the shared budget'
        );
    }

    // ── 4. site-wide refusal ─────────────────────────────────────────────

    public function testAnExhaustedSiteWideBudgetIs503UpstreamUnavailableBeforeUpstream(): void
    {
        $GLOBALS['__bcc_np']['throttle_deny'] = [self::GLOBAL_KEY];

        $r = self::call('ethereum', self::EVM_CONTRACT, '404');

        self::assertSame([503, 'bcc_upstream_unavailable'], self::statusAndCode($r));
        self::assertSame(0, $GLOBALS['__bcc_np']['fetcher_calls']);
    }

    // ── 5. stale-while-refused ───────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function refusingLayer(): iterable
    {
        yield 'per-caller' => [self::CALLER_KEY];
        yield 'site-wide'  => [self::GLOBAL_KEY];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusingLayer')]
    public function testARefusedRefreshServesTheStaleRowInsteadOfAnError(string $deny): void
    {
        self::cachePiece('8', -60);
        $GLOBALS['__bcc_np']['throttle_deny'] = [$deny];

        $r = self::call('ethereum', self::EVM_CONTRACT, '8');

        self::assertSame(200, $r->get_status(), var_export($r->get_data(), true));
        $data = $r->get_data()['data'] ?? $r->get_data();
        self::assertSame('Piece 8', $data['name']);
        self::assertSame('STALE', $r->headers['X-BCC-Cache'] ?? null);
        self::assertSame(0, $GLOBALS['__bcc_np']['fetcher_calls'], 'the stale row is served without an upstream call');
    }

    // ── 6. Cosmos: charged before any LCD query ──────────────────────────

    public function testCosmosIsChargedBeforeAnyLcdQuery(): void
    {
        // An UNCONSTRUCTED CosmosFetcher: any LCD method would fail loudly,
        // so a clean 429 proves the refusal ran before fetchTokenMetadata.
        $GLOBALS['__bcc_np']['fetcher_cosmos'] = true;
        $GLOBALS['__bcc_np']['throttle_deny']  = [self::CALLER_KEY];

        $r = self::call('cosmos', self::COSMOS_CONTRACT, 'token-1');

        self::assertSame([429, 'bcc_rate_limited'], self::statusAndCode($r));
        self::assertContains(self::CALLER_KEY, $GLOBALS['__bcc_np']['throttle_keys']);
    }

    public function testCosmosSiteWideRefusalIs503(): void
    {
        $GLOBALS['__bcc_np']['fetcher_cosmos'] = true;
        $GLOBALS['__bcc_np']['throttle_deny']  = [self::GLOBAL_KEY];

        $r = self::call('cosmos', self::COSMOS_CONTRACT, 'token-1');

        self::assertSame([503, 'bcc_upstream_unavailable'], self::statusAndCode($r));
    }
}
