<?php
/**
 * Creator Gallery Endpoint — GET /bcc/v1/creators/:slug/gallery (§H1).
 *
 * Backs the §N9 / Phase-6 NFT creator gallery surface. Thin wrapper
 * over the existing onchain stack:
 *   - `WalletRepository::getForProject` to confirm the creator has
 *     wallets indexed (else the gallery is silent)
 *   - `CollectionService::getForProject` to read paginated collections
 *     from `bcc_onchain_collections` (already cached + paginated)
 *   - Shape every row server-side per §A2 / §L5 — floor prices,
 *     volume, holder counts come back as pre-formatted strings the
 *     frontend renders verbatim. No client-side `Intl.NumberFormat`,
 *     no chain-token-symbol mapping in TypeScript.
 *
 * Stale-while-revalidate (§H1):
 *   - Read the cached + paginated row set immediately (object cache
 *     in `CollectionService`, then DB row in `bcc_onchain_collections`)
 *   - If any row's `expires_at` is past, dispatch ONE async refresh
 *     job per request via `wp_schedule_single_event`. A 5-minute
 *     transient gates concurrent dispatches so 100 simultaneous
 *     viewers don't queue 100 fetches for the same page.
 *   - The fetch itself runs in the next cron tick — the request
 *     returns within the §L1 300ms budget either way.
 *
 * V1 fetcher coverage: ETH (Etherscan / Alchemy) + Solana (Helius)
 * per §H1. Other chain fetchers exist but their NFT paths are
 * stubbed today; they return empty collection arrays without
 * burning API budget. Frontend renders "Coming soon" gracefully.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §H1 NFT indexer)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\PageTypeMap;
use BCC\Trust\Onchain\Services\CollectionService;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class CreatorGalleryEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Hard ceilings — gallery rendering is paginated, not unbounded. */
    private const PER_PAGE_DEFAULT = 12;
    private const PER_PAGE_MAX     = 50;
    private const PAGE_MAX         = 20;

    /** §A2 — server-rendered chain-native floor-price units per chain_slug. */
    private const CHAIN_NATIVE_TOKEN = [
        'ethereum' => 'ETH',
        'polygon'  => 'MATIC',
        'solana'   => 'SOL',
        'base'     => 'ETH',
        'arbitrum' => 'ETH',
        'optimism' => 'ETH',
    ];

    /** Public hook for the async-refresh handler — registered in Plugin.php. */
    public const REFRESH_HOOK = 'bcc_onchain_holdings_refresh';

    /** TTL for the per-post "refresh in flight" transient — long enough
     *  that a cold fetcher run finishes before a duplicate dispatch fires,
     *  short enough that genuinely-stuck refreshes don't lock out forever. */
    private const REFRESH_LOCK_TTL = 5 * MINUTE_IN_SECONDS;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/creators/(?P<slug>[A-Za-z0-9][A-Za-z0-9_-]{0,99})/gallery',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'handle'],
                'permission_callback' => '__return_true',
                'args' => [
                    'slug' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'sort' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request->get_param('slug');
        if ($slug === '') {
            return ApiResponse::error('bcc_invalid_request', 'slug is required.', 400);
        }

        $post = self::resolveCreatorPost($slug);
        if ($post === null) {
            return ApiResponse::error('bcc_not_found', 'Creator not found.', 404);
        }
        $postId = (int) $post->ID;

        $page = (int) $request->get_param('page');
        if ($page <= 0) {
            $page = 1;
        }
        if ($page > self::PAGE_MAX) {
            return ApiResponse::error(
                'bcc_invalid_request',
                sprintf('page must be ≤ %d.', self::PAGE_MAX),
                400
            );
        }

        $perPage = (int) $request->get_param('per_page');
        if ($perPage <= 0) {
            $perPage = self::PER_PAGE_DEFAULT;
        }
        if ($perPage > self::PER_PAGE_MAX) {
            $perPage = self::PER_PAGE_MAX;
        }

        $sortRaw = (string) $request->get_param('sort');
        $sort = self::normalizeSort($sortRaw);

        // ── Read the paginated collections (cached). ────────────────────
        $service = new CollectionService();
        unset($service); // CollectionService is a static facade — instance not needed.
        $result = CollectionService::getForProject($postId, $page, $perPage, $sort, false);

        $rawItems = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        $total    = isset($result['total']) ? (int) $result['total'] : 0;
        $pages    = isset($result['pages']) ? (int) $result['pages'] : 1;

        $items = [];
        $isStale = false;
        $lastRefreshedAt = null;

        $now = time();
        foreach ($rawItems as $row) {
            $shape = self::shapeRow($row);
            if ($shape !== null) {
                $items[] = $shape;
            }

            // Track staleness across the visible page. We don't sweep the
            // entire dataset — if the visible page is fresh, we don't
            // refresh, even if older non-visible rows happen to be stale.
            if (is_object($row) && isset($row->expires_at) && is_string($row->expires_at)) {
                $expiresEpoch = strtotime($row->expires_at . ' UTC');
                if ($expiresEpoch !== false && $expiresEpoch < $now) {
                    $isStale = true;
                }
            }
            if (is_object($row) && isset($row->fetched_at) && is_string($row->fetched_at)) {
                $fetchedEpoch = strtotime($row->fetched_at . ' UTC');
                if ($fetchedEpoch !== false) {
                    if ($lastRefreshedAt === null || $fetchedEpoch > $lastRefreshedAt) {
                        $lastRefreshedAt = $fetchedEpoch;
                    }
                }
            }
        }

        // First-ever request to a never-indexed creator: no rows yet,
        // but the creator has wallets the indexer COULD fetch. Mark
        // stale so the refresh kicks in and the next page-load will
        // have data.
        if ($total === 0) {
            $wallets = WalletRepository::getForProject($postId);
            if (!empty($wallets)) {
                $isStale = true;
            }
        }

        // ── Dispatch refresh if stale (rate-limited per post). ──────────
        if ($isStale) {
            self::maybeDispatchRefresh($postId);
        }

        $payload = [
            'items'             => $items,
            'pagination'        => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $pages,
                'has_more'    => $page < $pages,
            ],
            'is_stale'          => $isStale,
            'last_refreshed_at' => $lastRefreshedAt !== null
                ? gmdate('Y-m-d\TH:i:s\Z', $lastRefreshedAt)
                : null,
        ];

        $response = ApiResponse::ok($payload);
        // Per-page-no-viewer cacheable. If we ever surface viewer-specific
        // badges (e.g. "you hold this") tighten this to private.
        $response->header('Cache-Control', 'public, max-age=30, stale-while-revalidate=60');
        return $response;
    }

    /**
     * Schedule a single async refresh for a creator's wallets, gated
     * by a 5-minute transient lock so concurrent requests don't pile
     * up duplicate fetches.
     */
    private static function maybeDispatchRefresh(int $postId): void
    {
        $lock = 'bcc_gallery_refresh_lock_' . $postId;
        if (get_transient($lock) !== false) {
            return;
        }
        set_transient($lock, 1, self::REFRESH_LOCK_TTL);

        wp_schedule_single_event(time(), self::REFRESH_HOOK, [$postId]);
    }

    /**
     * Map a row from CollectionRepository into a server-shaped
     * CollectionItem per §A2. Returns null when the row is missing
     * the fields the frontend needs (defensive — corrupt rows shouldn't
     * surface as half-rendered placeholders).
     *
     * @return array<string, mixed>|null
     */
    private static function shapeRow(object $row): ?array
    {
        $id = isset($row->id) ? (int) $row->id : 0;
        $contract = isset($row->contract_address) && is_string($row->contract_address)
            ? $row->contract_address
            : '';
        $chainSlug = isset($row->chain_slug) && is_string($row->chain_slug)
            ? $row->chain_slug
            : '';
        if ($id <= 0 || $contract === '' || $chainSlug === '') {
            return null;
        }

        $name = isset($row->collection_name) && is_string($row->collection_name) && $row->collection_name !== ''
            ? $row->collection_name
            : self::truncateContract($contract);

        $imageUrl = isset($row->image_url) && is_string($row->image_url) && $row->image_url !== ''
            ? $row->image_url
            : null;

        $chainName = isset($row->chain_name) && is_string($row->chain_name)
            ? $row->chain_name
            : ucfirst($chainSlug);

        $explorerBase = isset($row->explorer_url) && is_string($row->explorer_url) && $row->explorer_url !== ''
            ? rtrim($row->explorer_url, '/')
            : null;
        $explorerUrl = $explorerBase !== null
            ? $explorerBase . '/address/' . $contract
            : null;

        $totalSupply = isset($row->total_supply) && is_numeric($row->total_supply)
            ? (int) $row->total_supply
            : null;

        $floorPriceLabel = self::formatFloorPrice($row, $chainSlug);
        $totalVolumeLabel = self::formatVolume($row, $chainSlug);
        $uniqueHoldersLabel = self::formatHolders($row);

        return [
            'id'                   => $id,
            'contract_address'     => $contract,
            'chain_slug'           => $chainSlug,
            'chain_name'           => $chainName,
            'name'                 => $name,
            'image_url'            => $imageUrl,
            'total_supply'         => $totalSupply,
            'floor_price_label'    => $floorPriceLabel,
            'total_volume_label'   => $totalVolumeLabel,
            'unique_holders_label' => $uniqueHoldersLabel,
            'explorer_url'         => $explorerUrl,
        ];
    }

    private static function formatFloorPrice(object $row, string $chainSlug): ?string
    {
        if (!isset($row->floor_price) || !is_numeric($row->floor_price)) {
            return null;
        }
        $price = (float) $row->floor_price;
        if ($price <= 0) {
            return null;
        }
        $currencyRaw = isset($row->floor_currency) && is_string($row->floor_currency) && $row->floor_currency !== ''
            ? strtoupper($row->floor_currency)
            : null;
        $currency = $currencyRaw ?? self::CHAIN_NATIVE_TOKEN[$chainSlug] ?? '';

        // Trim trailing zeroes for visual cleanliness — "1.5 ETH" beats
        // "1.50000000 ETH".
        $formatted = rtrim(rtrim(number_format($price, 4, '.', ','), '0'), '.');
        if ($formatted === '') {
            $formatted = '0';
        }
        return $currency !== ''
            ? sprintf('%s %s', $formatted, $currency)
            : $formatted;
    }

    private static function formatVolume(object $row, string $chainSlug): ?string
    {
        if (!isset($row->total_volume) || !is_numeric($row->total_volume)) {
            return null;
        }
        $volume = (float) $row->total_volume;
        if ($volume <= 0) {
            return null;
        }
        $currency = self::CHAIN_NATIVE_TOKEN[$chainSlug] ?? '';

        // Compact display: 1.2K / 4.5M / 2.3B. Avoids 8-figure floor
        // prices crashing the layout.
        if ($volume >= 1_000_000_000) {
            $formatted = number_format($volume / 1_000_000_000, 1) . 'B';
        } elseif ($volume >= 1_000_000) {
            $formatted = number_format($volume / 1_000_000, 1) . 'M';
        } elseif ($volume >= 1_000) {
            $formatted = number_format($volume / 1_000, 1) . 'K';
        } else {
            $formatted = number_format($volume, 2);
        }
        return $currency !== ''
            ? sprintf('%s %s volume', $formatted, $currency)
            : sprintf('%s volume', $formatted);
    }

    private static function formatHolders(object $row): ?string
    {
        if (!isset($row->unique_holders) || !is_numeric($row->unique_holders)) {
            return null;
        }
        $holders = (int) $row->unique_holders;
        if ($holders <= 0) {
            return null;
        }
        return sprintf('%s holder%s', number_format($holders), $holders === 1 ? '' : 's');
    }

    /**
     * Truncated contract for display when the collection name is missing
     * — "0xabcd…1234" — readable without surrendering useful detail.
     */
    private static function truncateContract(string $contract): string
    {
        $len = strlen($contract);
        if ($len <= 12) {
            return $contract;
        }
        return substr($contract, 0, 6) . '…' . substr($contract, -4);
    }

    private static function normalizeSort(string $raw): string
    {
        $allowed = ['total_volume', 'floor_price', 'unique_holders', 'total_supply', 'collection_name'];
        return in_array($raw, $allowed, true) ? $raw : 'total_volume';
    }

    /**
     * Resolve a creator slug → the underlying peepso-page CPT post,
     * verifying its `_bcc_page_type` meta is `nft`. Returns null when
     * nothing resolves OR when the post exists but isn't actually a
     * creator page (404, not 500).
     */
    private static function resolveCreatorPost(string $slug): ?\WP_Post
    {
        $expected = PageTypeMap::KIND_TO_PAGE_TYPE['creator'];

        // Numeric slug → direct ID lookup (the route regex permits both
        // shapes per CardsEndpoint convention).
        if (ctype_digit($slug)) {
            $post = get_post((int) $slug);
            if (!$post instanceof \WP_Post || $post->post_type !== 'peepso-page') {
                return null;
            }
            $type = (string) get_post_meta($post->ID, '_bcc_page_type', true);
            return $type === $expected ? $post : null;
        }

        // Slug lookup against post_name on peepso-page CPT.
        $candidates = get_posts([
            'name'           => $slug,
            'post_type'      => 'peepso-page',
            'post_status'    => 'publish',
            'numberposts'    => 1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);
        if (empty($candidates)) {
            return null;
        }
        $post = get_post((int) $candidates[0]);
        if (!$post instanceof \WP_Post) {
            return null;
        }
        $type = (string) get_post_meta($post->ID, '_bcc_page_type', true);
        return $type === $expected ? $post : null;
    }
}
