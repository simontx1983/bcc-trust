<?php
/**
 * NftPieceEndpoint — handles GET /bcc/v1/nft-pieces/{chain}/{contract}/{tokenId}.
 *
 * Single route. Anonymous OR Bearer (no viewer-aware fields in V2
 * Phase 6 — `permissions` is `{}` for everyone). Returns the §3.7
 * NftPiece view-model in the §1.4 envelope. Cache headers per §4.17.
 *
 * Errors map verbatim to §4.17:
 *   - bcc_invalid_chain         (422) — unsupported chain slug
 *   - bcc_invalid_request       (422) — malformed contract / empty token_id
 *   - bcc_not_found             (404) — collection unindexed AND fetch failed,
 *                                       OR token not on-chain
 *   - bcc_unsupported_standard  (422) — fungible token contract
 *   - bcc_upstream_unavailable  (503) — Cosmos LCD timeout, no cache
 *
 * @package BCC\Trust\Onchain\REST
 * @since V2 Phase 6 (§H1)
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\REST;

use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\WalletAddressValidator;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\NftPieceViewModelBuilder;
use BCC\Trust\Onchain\Services\PieceBuilderException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class NftPieceEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Chain slugs accepted by the V2 Phase 6 surface. */
    private const SUPPORTED_CHAIN_SLUGS = ['ethereum', 'solana', 'cosmos'];

    /**
     * Per-caller throttle, the figure api-contract-v1.md §4.17 already
     * documents ("60/min/user (anonymous: 60/min/IP)") but the route never
     * enforced (F-154). Throttle::allow keys anonymous callers by /24 or
     * /64 subnet, signed-in callers by user id.
     */
    private const RATE_LIMIT  = 60;
    private const RATE_WINDOW = 60;

    /**
     * token_id shape per chain type, matching what the indexers store:
     *   evm     decimal uint256 (EvmFetcher::hexToDecimalString), ≤ 78 digits
     *   solana  the mint pubkey, base58, 32–44 chars (SolanaFetcher stores
     *           mint as both contract_address and token_id)
     *   cosmos  CW-721 ids are arbitrary strings, so ≤ 128 chars with no
     *           whitespace or control/format characters
     */
    private const TOKEN_ID_PATTERNS = [
        'evm'    => '/^[0-9]{1,78}$/D',
        'solana' => '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D',
        'cosmos' => '/^[^\p{C}\s]{1,128}$/Du',
    ];

    /**
     * §4.17 cache windows per chain class.
     *
     * These are SHARED-cacheable (no `private`), which is only safe
     * because this response is viewer-INVARIANT: identical bytes for
     * anonymous and for every authenticated caller. `permissions` is a
     * fixed `{}` and there is no per-viewer field.
     *
     * That invariant used to be violated: the payload carried the
     * holder's `wallet_address` and resolved BCC identity, which meant a
     * member↔wallet join sat in every intermediary and in the LiteSpeed
     * edge tier for up to 30 minutes. The wallet data is gone (see
     * NftPieceViewModelBuilder::buildDominantOwner), so the invariant
     * now holds.
     *
     * If you ever add a field that varies by viewer, this must become
     * `private` — a shared cache has no concept of who asked.
     * See docs/wallet-privacy-policy.md.
     */
    private const CACHE_INDEXED = 'max-age=300, stale-while-revalidate=1800';
    private const CACHE_COSMOS  = 'max-age=60, stale-while-revalidate=600';

    public static function register(): void
    {
        $instance = new self();

        // Route segments allow most URL-safe characters; the controller
        // re-validates per-chain. token_id is permissive (.+) since
        // CW-721 token IDs may contain non-alphanumeric characters that
        // the client URL-encodes.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/nft-pieces/(?P<chainSlug>[a-z0-9-]+)/(?P<contractAddress>[A-Za-z0-9]+)/(?P<tokenId>.+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'getPiece'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'chainSlug' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'contractAddress' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'tokenId' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getPiece(WP_REST_Request $request): WP_REST_Response
    {
        // Throttle FIRST, before any lookup or upstream call. The route is
        // public, and a miss costs an upstream provider call (Alchemy,
        // Helius, a Cosmos LCD) per request.
        if (!\BCC\Core\Security\Throttle::allow('nft_piece', self::RATE_LIMIT, self::RATE_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please wait.', 429);
        }

        $chainSlug       = (string) $request->get_param('chainSlug');
        $contractAddress = (string) $request->get_param('contractAddress');
        // WordPress URL-decodes path params once; the contract spec
        // says the client sends `encodeURIComponent(tokenId)` so the
        // value we receive here is already decoded.
        $tokenId = (string) $request->get_param('tokenId');

        // ── Validation: chain ───────────────────────────────────────
        if (!in_array($chainSlug, self::SUPPORTED_CHAIN_SLUGS, true)) {
            return ApiResponse::error(
                'bcc_invalid_chain',
                'Chain not supported. Use one of: ethereum, solana, cosmos.',
                422
            );
        }

        // ── Validation: contract ───────────────────────────────────
        $chainType = self::chainTypeForSlug($chainSlug);
        if ($chainType === null) {
            // The slug is in our allow-list but the chain row was
            // deleted / inactive. Treat as invalid_chain rather than
            // 500 because the user-visible cause is the same.
            return ApiResponse::error(
                'bcc_invalid_chain',
                'Chain not active.',
                422
            );
        }
        if (!WalletAddressValidator::validate($contractAddress, $chainType)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'contract_address is malformed for the chain.',
                422
            );
        }

        // ── Validation: token_id ────────────────────────────────────
        if ($tokenId === '') {
            return ApiResponse::error(
                'bcc_invalid_request',
                'token_id is required.',
                422
            );
        }
        // Bound length AND charset per chain, so a malformed id is refused
        // here instead of becoming an upstream call (F-154). An unknown chain
        // type has no pattern and is refused too.
        $pattern = self::TOKEN_ID_PATTERNS[$chainType] ?? null;
        if ($pattern === null || preg_match($pattern, $tokenId) !== 1) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'token_id is malformed for the chain.',
                422
            );
        }

        // ── Build view-model ────────────────────────────────────────
        try {
            [$piece, $cacheState] = NftPieceViewModelBuilder::build($chainSlug, $contractAddress, $tokenId);
        } catch (PieceBuilderException $e) {
            return $this->mapBuilderException($e);
        } catch (\Throwable $e) {
            return ApiResponse::error(
                'bcc_internal_error',
                'Could not assemble piece view-model.',
                500
            );
        }

        $response = ApiResponse::ok($piece);
        $response->header('Cache-Control', $chainSlug === 'cosmos' ? self::CACHE_COSMOS : self::CACHE_INDEXED);
        // §4.17: X-BCC-Cache ∈ {FRESH, STALE, MISS} — distinguished
        // by the builder per cache-row freshness. Cosmos always
        // reports MISS (read-time, no persistence).
        $response->header('X-BCC-Cache', $cacheState);
        return $response;
    }

    private static function chainTypeForSlug(string $chainSlug): ?string
    {
        $chain = ChainRepository::getBySlug($chainSlug);
        if ($chain === null) {
            return null;
        }
        $type = (string) $chain->chain_type;
        return $type === '' ? null : $type;
    }

    private function mapBuilderException(PieceBuilderException $e): WP_REST_Response
    {
        return match ($e->errorCode) {
            NftPieceViewModelBuilder::ERROR_INVALID_CHAIN => ApiResponse::error(
                'bcc_invalid_chain',
                $e->getMessage(),
                422
            ),
            NftPieceViewModelBuilder::ERROR_NOT_FOUND => ApiResponse::error(
                'bcc_not_found',
                $e->getMessage(),
                404
            ),
            NftPieceViewModelBuilder::ERROR_UNSUPPORTED_STANDARD => ApiResponse::error(
                'bcc_unsupported_standard',
                $e->getMessage(),
                422
            ),
            NftPieceViewModelBuilder::ERROR_UPSTREAM_UNAVAILABLE => ApiResponse::error(
                'bcc_upstream_unavailable',
                $e->getMessage(),
                503
            ),
            default => ApiResponse::error(
                'bcc_internal_error',
                'Could not assemble piece view-model.',
                500
            ),
        };
    }
}
