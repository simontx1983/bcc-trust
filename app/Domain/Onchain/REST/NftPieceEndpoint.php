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

    /** §4.17 cache windows per chain class. */
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
        // Bound the size so a malicious caller can't ship a 1MB token
        // ID through the URL. CW-721 tokens are typically ≤ 64 chars.
        if (strlen($tokenId) > 255) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'token_id exceeds maximum length.',
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
