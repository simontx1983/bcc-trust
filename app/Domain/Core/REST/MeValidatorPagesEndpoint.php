<?php
/**
 * GET /bcc/v1/me/validator-pages — the operator dashboard's data source.
 *
 * Every validator page the signed-in member holds a VERIFIED operator
 * claim on, across every chain. Authenticated members only, and each
 * member sees ONLY their own claims: the repository read is user-scoped
 * and there is no parameter that could widen it.
 *
 * ⭐ MULTI-CHAIN IS THE POINT. An operator running validators on several
 * chains gets one row per claimed page here, each carrying its own
 * chain and its own public page. Nothing in this surface — or in the
 * claims table it reads — caps a member at one validator page.
 *
 * ⚠⚠ `chain_reported_status` IS NOT A MEMBERSHIP CLAIM, and the name
 * says so deliberately. `bcc_onchain_validators.status` cannot prove
 * whether a validator is in its chain's current active set:
 *
 *   - it freezes forever for a row the index fetch stops returning,
 *     because bulkUpsert() only writes rows the fetch contained;
 *   - 'inactive' does NOT mean "absent from the fetch" — SolanaFetcher
 *     merges `current` AND `delinquent` into one list and labels the
 *     delinquent ones 'inactive', so there it means PRESENT;
 *   - enrichByOperator() and the wallet-link upsert() can both write it
 *     at a time unrelated to any fetch.
 *
 * So this endpoint reports the stored value as what it is — the last
 * status a provider reported — and makes no "in the set" / "not in the
 * set" assertion. Proving membership needs a per-row field written only
 * on a clean, verified, untruncated fetch; until that exists, no caller
 * of this endpoint may render a membership badge from this field.
 *
 * ⚠ `fetched_at` is deliberately NOT exposed. It has three writers
 * (bulkUpsert with a 6h debounce on unchanged rows, upsert on the
 * wallet-link path, and enrichByOperator unconditionally), so it would
 * overstate freshness for an enriched-but-absent validator. A precise
 * "last seen" date cannot be sourced honestly from it.
 *
 * @package BCC\Trust\Core\REST
 * @since Operator dashboard (2026-11-04)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MeValidatorPagesEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const ROUTE_PATH      = '/me/validator-pages';

    /** Bound on rows returned; the repository clamps to 200 as well. */
    private const MAX_ITEMS = 100;

    public static function register(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE_PATH, [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [new self(), 'handle'],
            // Auth in-handler (MeProgressionEndpoint pattern) — bearer
            // resolution happens upstream, so anonymous gets a clean 401
            // rather than a 403 from a permission_callback that cannot
            // see the resolved user.
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error(
                'bcc_unauthorized',
                'Sign in to see the validator pages you manage.',
                401
            );
        }

        $rows  = ClaimRepository::listVerifiedValidatorPagesForUser($userId, self::MAX_ITEMS);
        $items = [];

        foreach ($rows as $row) {
            $pageId = isset($row->page_id) ? (int) $row->page_id : 0;

            $items[] = [
                // The claim itself — the only thing that authorizes management.
                'claim_role'   => (string) $row->claim_role,
                'claim_status' => (string) $row->claim_status,

                // The public page. page_id 0 / page_slug null means the
                // claim is held but the page is missing — surfaced rather
                // than dropped, so the operator can see the discrepancy.
                'page_id'   => $pageId > 0 ? $pageId : null,
                'page_slug' => isset($row->page_slug) && (string) $row->page_slug !== ''
                    ? (string) $row->page_slug
                    : null,

                // Chain identity. Each claimed page keeps its own chain.
                'chain' => [
                    'slug' => isset($row->chain_slug) ? (string) $row->chain_slug : null,
                    'name' => isset($row->chain_name) ? (string) $row->chain_name : null,
                    'type' => isset($row->chain_type) ? (string) $row->chain_type : null,
                ],

                'validator' => [
                    'id'               => (int) $row->validator_id,
                    'moniker'          => isset($row->moniker) && (string) $row->moniker !== ''
                        ? (string) $row->moniker
                        : null,
                    'operator_address' => (string) $row->operator_address,
                ],

                // ⚠ See the class docblock: the LAST STATUS A PROVIDER
                // REPORTED, not a statement about current set membership.
                'chain_reported_status' => (string) $row->validator_status,
            ];
        }

        return ApiResponse::ok([
            'items' => $items,
            'total' => count($items),
        ]);
    }
}
