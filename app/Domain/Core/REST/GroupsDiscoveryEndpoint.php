<?php
/**
 * GroupsDiscoveryEndpoint — handles GET /bcc/v1/groups.
 *
 * Cross-kind discovery list. Sort key (per plan):
 *   verified DESC, heat_score DESC, member_count DESC
 *
 * Verified groups (those with `_bcc_group_kind = 'holders'`) rank
 * above non-verified — but active verified groups beat sleepy ones,
 * so a "verified but dead" community doesn't dominate the discovery
 * surface.
 *
 * Filters:
 *   ?verified=1 → only on-chain verified groups
 *   ?page / ?page_size → standard pagination (default 20, max 50)
 *
 * Privacy: secret groups never appear here regardless of viewer.
 * Closed groups appear but content is private at PeepSo's layer.
 *
 * Each item carries the same `verification` + `activity` blocks the
 * holder-groups endpoint emits, so the frontend can render heat
 * indicators and the "On-Chain Verified" badge consistently.
 *
 * Cache strategy: 60s public cache. Heat is recomputed on each fetch
 * so cold groups warming up surface within a minute.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 (Verified-has-teeth wiring)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\PageDiscoveryRepository;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupsDiscoveryEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE     = 50;

    /** Limit on the candidate pool fetched + sorted in PHP before pagination. */
    private const CANDIDATE_LIMIT   = 500;

    public static function register(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/groups',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'getList'],
                'permission_callback' => '__return_true',
                'args' => [
                    'verified'  => ['sanitize_callback' => 'absint'],
                    'mine'      => ['sanitize_callback' => 'absint'],
                    'chain'     => ['sanitize_callback' => 'sanitize_key'],
                    'page'      => ['sanitize_callback' => 'absint'],
                    'page_size' => ['sanitize_callback' => 'absint'],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getList(WP_REST_Request $request): WP_REST_Response
    {
        $verifiedOnly = (int) $request->get_param('verified') === 1;
        $mineOnly     = (int) $request->get_param('mine') === 1;
        $chainSlug    = (string) $request->get_param('chain');
        $page         = max(1, (int) $request->get_param('page') ?: 1);
        $pageSize     = (int) $request->get_param('page_size') ?: self::DEFAULT_PAGE_SIZE;
        $pageSize     = max(1, min(self::MAX_PAGE_SIZE, $pageSize));

        $viewerId = get_current_user_id();

        // ── mine=1 path — viewer's own group memberships ────────────────
        // Anonymous viewers can browse the rest of the directory but
        // "mine" needs an identity. Return an empty result rather than
        // 401 so the page can render the empty-state copy with a sign-in
        // hint instead of erroring out.
        if ($mineOnly) {
            if ($viewerId <= 0) {
                return $this->respond([], $page, $pageSize, 0, true);
            }

            $memberIds = PeepSoGroupRepository::getUserMemberGroupIds(
                $viewerId,
                self::CANDIDATE_LIMIT
            );
            if ($memberIds === []) {
                return $this->respond([], $page, $pageSize, 0, true);
            }
            // Intersect with the browsable set so secret-group memberships
            // (which the rest of the directory hides) stay hidden here too.
            $browsable    = array_flip(PeepSoGroupRepository::listBrowsableGroupIds(self::CANDIDATE_LIMIT));
            $candidateIds = array_values(array_filter(
                $memberIds,
                static fn(int $id): bool => isset($browsable[$id])
            ));
            if ($candidateIds === []) {
                return $this->respond([], $page, $pageSize, 0, true);
            }
        } else {
            $candidateIds = PeepSoGroupRepository::listBrowsableGroupIds(self::CANDIDATE_LIMIT);
            if ($candidateIds === []) {
                return $this->respond([], $page, $pageSize, 0);
            }
        }

        $resolver = Plugin::instance()->groupContextResolver();
        $contexts = $resolver->forManyGroups($candidateIds);

        if ($verifiedOnly) {
            $contexts = array_filter(
                $contexts,
                static fn(GroupContext $c): bool => $c->isVerified()
            );
            if ($contexts === []) {
                return $this->respond([], $page, $pageSize, 0, $mineOnly);
            }
        }

        // Chain filter — restricts to groups whose `_bcc_gate_chain_id`
        // post_meta resolves to the requested slug. NFT holder groups
        // carry this meta key directly (the gate config writes it at
        // claim time); user/system groups and Locals don't, so they
        // drop out of chain-scoped views. Unknown chain slugs return
        // an empty result rather than 400 — the chain dropdown is
        // client-driven and a stale slug shouldn't surface as an error.
        if ($chainSlug !== '') {
            $contexts = $this->filterContextsByChain($contexts, $chainSlug);
            if ($contexts === []) {
                return $this->respond([], $page, $pageSize, 0, $mineOnly);
            }
        }

        $orderedIds  = array_keys($contexts);
        $displays    = PeepSoGroupRepository::findManyByIds($orderedIds);
        $activity    = Plugin::instance()->groupActivityHeatService()->forGroups($orderedIds);

        // Cross-domain enrichment: NFT-type cards carry their collection's
        // image_url + market stats (floor, holders, volume, etc.) so the
        // discovery surface can render cover art and the flip-card back
        // can render decision-grade signals. Locals, system, and user-
        // created groups have no equivalent in V1 (PeepSo group avatars
        // are V1.5; non-NFT groups have no `collection_stats`). Two
        // Onchain repo calls, both batched. Cap-at-500 matches
        // CANDIDATE_LIMIT above.
        $enrichmentByGroup = $this->resolveCollectionEnrichment($orderedIds);

        // Chain + trust enrichment — both surfaces want them on the card
        // (chain chip alongside the kind label; trust threshold replaces
        // the OPEN privacy chip when set). Bulk-resolve to avoid N+1:
        //   - chain slugs come from one indexed SELECT joining wp_postmeta
        //     → bcc_onchain_chains.
        //   - trust gate min: warm WP's post-meta cache once for the
        //     candidate set, then read per-group from L1.
        $chainSlugByGroup = \BCC\Core\ServiceLocator::resolveChainRead()->resolveSlugsForGroups($orderedIds);
        update_meta_cache('post', $orderedIds);
        $trustMinByGroup = [];
        foreach ($orderedIds as $gid) {
            $v = (int) get_post_meta($gid, '_bcc_trust_gate_min', true);
            if ($v > 0) {
                $trustMinByGroup[$gid] = $v;
            }
        }

        // Build sortable rows.
        $rows = [];
        foreach ($contexts as $groupId => $ctx) {
            $display = $displays[$groupId] ?? null;
            $heat    = $activity[$groupId] ?? [
                'posts_last_7d'    => 0,
                'last_activity_at' => null,
                'heat'             => 'cold',
                'heat_label'       => 'Quiet',
            ];
            $enrichment = $enrichmentByGroup[$groupId] ?? null;
            $description = self::truncateDescription(
                $display !== null ? (string) $display->post_content : ''
            );
            $rows[] = [
                'group_id'          => $groupId,
                'context'           => $ctx,
                'display'           => $display,
                'activity'          => $heat,
                'image_url'         => $enrichment['image_url'] ?? null,
                'collection_stats'  => $enrichment['stats'] ?? null,
                'description'       => $description,
                'chain_tag'         => $chainSlugByGroup[$groupId] ?? null,
                'trust_min'         => $trustMinByGroup[$groupId] ?? null,
                'sort_verified'     => $ctx->isVerified() ? 1 : 0,
                'sort_heat'         => (int) $heat['posts_last_7d'],
                'sort_member_cnt'   => $display !== null ? (int) $display->member_count : 0,
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                if ($a['sort_verified'] !== $b['sort_verified']) {
                    return $b['sort_verified'] <=> $a['sort_verified'];
                }
                if ($a['sort_heat'] !== $b['sort_heat']) {
                    return $b['sort_heat'] <=> $a['sort_heat'];
                }
                return $b['sort_member_cnt'] <=> $a['sort_member_cnt'];
            }
        );

        $total      = count($rows);
        $offset     = ($page - 1) * $pageSize;
        $pagedRows  = array_slice($rows, $offset, $pageSize);

        // Viewer membership for the PAGE of items only — one batched
        // repo call (active-status filtered), powering each item card's
        // community_dossier.viewer_is_member without a per-item query.
        $pagedGroupIds = [];
        foreach ($pagedRows as $row) {
            $pagedGroupIds[] = (int) $row['group_id'];
        }
        $membershipByGroup = ($viewerId > 0 && $pagedGroupIds !== [])
            ? PeepSoGroupRepository::findUserMemberships($viewerId, $pagedGroupIds)
            : [];

        $cardView = Plugin::instance()->cardViewService();

        $items = [];
        foreach ($pagedRows as $row) {
            $groupId = (int) $row['group_id'];
            /** @var GroupContext $ctx */
            $ctx     = $row['context'];
            $display = $row['display'];
            // §4.4 community Card — pure composition from the row data
            // already in hand (zero queries per item). Additive: the
            // legacy flat fields stay untouched for the migration window.
            $card = $cardView->getCommunityCardFromGroupData(
                [
                    'group_id'         => $groupId,
                    'slug'             => $display !== null ? (string) $display->post_name : '',
                    'name'             => $display !== null ? (string) $display->post_title : '',
                    'type'             => $ctx->type->value,
                    'privacy'          => $ctx->privacy->value,
                    'member_count'     => $display !== null ? (int) $display->member_count : 0,
                    'description'      => $row['description'],
                    'image_url'        => $row['image_url'],
                    'verification'     => $ctx->verification?->toApiResponse(),
                    'collection_stats' => $row['collection_stats'],
                    'chain_tag'        => $row['chain_tag'],
                    'trust_min'        => $row['trust_min'],
                    'viewer_is_member' => isset($membershipByGroup[$groupId]),
                    'posts_last_7d'    => (int) ($row['activity']['posts_last_7d'] ?? 0),
                ],
                $viewerId
            );
            $items[] = $this->composeItem(
                $row['context'],
                $row['display'],
                $row['activity'],
                $row['image_url'],
                $row['collection_stats'],
                $row['description'],
                $row['chain_tag'],
                $row['trust_min'],
                $card
            );
        }

        // Authed responses now carry viewer_is_member inside each item's
        // card.community_dossier — viewer-scoped data must never sit in
        // a shared cache. Anon responses stay viewer-agnostic (membership
        // is always false) and keep the 60s public cache.
        return $this->respond($items, $page, $pageSize, $total, $mineOnly || $viewerId > 0);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function respond(array $items, int $page, int $pageSize, int $total, bool $perViewer = false): WP_REST_Response
    {
        $response = ApiResponse::ok([
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'page_size'   => $pageSize,
                'total'       => $total,
                'total_pages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
            ],
        ]);
        // `mine=1` results are viewer-scoped — must NEVER be served from
        // a shared cache. Other filter combos (verified, no-filter) are
        // viewer-agnostic and stay on the 60s public cache.
        if ($perViewer) {
            $response->header('Cache-Control', 'private, no-store');
        } else {
            $response->header('Cache-Control', 'public, max-age=60');
        }
        return $response;
    }

    /**
     * @param object{id: numeric-string, post_name: string, post_title: string, post_content: string, member_count: numeric-string}|null $display
     * @param array{posts_last_7d: int, last_activity_at: string|null, heat: string, heat_label: string} $activity
     * @param array<string, mixed>|null $stats
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    private function composeItem(
        GroupContext $ctx,
        ?object $display,
        array $activity,
        ?string $imageUrl,
        ?array $stats,
        ?string $description,
        ?string $chainTag,
        ?int $trustMin,
        array $card
    ): array {
        return [
            'group_id'         => $ctx->groupId,
            'slug'             => $display !== null ? (string) $display->post_name : '',
            'name'             => $display !== null ? (string) $display->post_title : '',
            'type'             => $ctx->type->value,
            'member_count'     => $display !== null ? (int) $display->member_count : 0,
            'privacy'          => $ctx->privacy->value,
            'verification'     => $ctx->verification?->toApiResponse(),
            'description'      => $description,
            'image_url'        => $imageUrl,
            'collection_stats' => $stats,
            'activity'         => $activity,
            // Same key vocabulary as CreatePlainGroupResponse + the detail
            // view-model — single contract shape across all surfaces.
            'chain_tag'        => $chainTag,
            'trust_min'        => $trustMin,
            // §4.4 card convergence (additive): the full community Card
            // view-model. New consumers render `item.card` via the
            // CardFactory; the flat fields above remain for the
            // migration window.
            'card'             => $card,
        ];
    }

    /**
     * Resolve collection enrichment per NFT-type group in the candidate
     * pool — image_url for the cover surface plus a `stats` block for
     * the flip-card back face. Two batched lookups (gate configs +
     * collections), no N+1. Non-NFT groups (locals, system, user) yield
     * nothing in the map — the caller falls back to null/null.
     *
     * Stats include both the raw values (mirror of wp_bcc_onchain_collections
     * columns) AND server-pre-formatted `*_display` strings. The frontend
     * renders `*_display` verbatim per §A2 / §S; raw values are kept
     * for future use (sorting, charts) and contract debuggability.
     * Em-dash ("—") is used for missing/zero values so the wire never
     * surfaces "0.00 STARS" as a fake-low signal.
     *
     * @param list<int> $groupIds
     * @return array<int, array{
     *     image_url: string|null,
     *     stats: array{
     *         token_standard: string|null,
     *         total_supply: int|null,
     *         unique_holders: int|null,
     *         floor_price: string|null,
     *         floor_currency: string|null,
     *         total_volume: string|null,
     *         listed_percentage: float|null,
     *         royalty_percentage: float|null,
     *         distribution_pct: int|null,
     *         min_balance: int|null,
     *         floor_display: string|null,
     *         volume_display: string|null,
     *         holders_display: string|null,
     *         supply_display: string|null,
     *         listed_display: string|null,
     *         royalty_display: string|null,
     *         min_balance_display: string|null,
     *         marketplace: array{url: string, label: string}|null
     *     }|null
     * }>
     */
    /**
     * Drop GroupContexts whose chain meta doesn't resolve to the
     * requested chain slug. Matches EITHER `_bcc_gate_chain_id` (NFT
     * holder groups — the gate config writes it at admin claim time)
     * OR `_bcc_chain_tag` (user-created plain groups — written at
     * create-time on /communities/new and locked from there on).
     * Single bulk SELECT scoped to the candidate set — no N+1.
     *
     * Groups without either meta key (Locals, legacy user/system
     * groups that pre-date the chain-tag field) drop out of chain-
     * scoped results. Locals fall out by design — they bind to a
     * chain via post_title prefix, not post_meta. When that needs
     * to be chain-filterable, this helper expands to include a
     * Local-specific path without touching the endpoint wiring.
     *
     * @param array<int, GroupContext> $contexts
     * @return array<int, GroupContext>
     */
    private function filterContextsByChain(array $contexts, string $chainSlug): array
    {
        if ($contexts === []) {
            return [];
        }

        // Caller passes a slug; resolve to a chain id once (cached in
        // ChainRepository). Any-state lookup preserves the pre-refactor
        // behavior of gating on deactivated chains too (the old inline query
        // had no is_active filter).
        $chainId = ChainRepository::resolveIdAnyState($chainSlug);
        if ($chainId === null || $chainId <= 0) {
            return [];
        }

        $groupIds   = array_map('intval', array_keys($contexts));
        $gatedIds   = PageDiscoveryRepository::findPostIdsGatedByChain($groupIds, $chainId);
        if ($gatedIds === []) {
            return [];
        }

        $allowed = [];
        foreach ($gatedIds as $postId) {
            $allowed[$postId] = true;
        }

        $out = [];
        foreach ($contexts as $gid => $ctx) {
            if (isset($allowed[$gid])) {
                $out[$gid] = $ctx;
            }
        }
        return $out;
    }

    /**
     * @param list<int> $groupIds
     * @return array<int, array{image_url: string|null, stats: array<string, mixed>}>
     */
    private function resolveCollectionEnrichment(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $wantedGroups = array_flip($groupIds);

        // Pull all gate configs (cap matches CANDIDATE_LIMIT). For v1 scale
        // this is at most one SELECT + a warmed post_meta cache.
        $configs = GatedGroupRepository::listAllGatedGroupConfigs(self::CANDIDATE_LIMIT);

        $collectionIdByGroup = [];
        $minBalanceByGroup   = [];
        $wantedCollectionIds = [];
        foreach ($configs as $cfg) {
            if (!isset($wantedGroups[$cfg->groupId])) {
                continue;
            }
            if ($cfg->collectionId === null) {
                continue;
            }
            $collectionIdByGroup[$cfg->groupId] = $cfg->collectionId;
            $minBalanceByGroup[$cfg->groupId]   = $cfg->minBalance;
            $wantedCollectionIds[$cfg->collectionId] = true;
        }

        if ($collectionIdByGroup === []) {
            return [];
        }

        $collections = CollectionRepository::findManyByIds(
            array_keys($wantedCollectionIds)
        );

        $enrichmentByGroup = [];
        foreach ($collectionIdByGroup as $groupId => $collectionId) {
            $row = $collections[$collectionId] ?? null;
            if ($row === null) {
                continue;
            }
            $imageUrl = ($row->image_url !== null && $row->image_url !== '')
                ? (string) $row->image_url
                : null;

            $totalSupply       = $row->total_supply !== null ? (int) $row->total_supply : null;
            $uniqueHolders     = $row->unique_holders !== null ? (int) $row->unique_holders : null;
            $floorPrice        = $row->floor_price !== null ? (string) $row->floor_price : null;
            $floorCurrency     = $row->floor_currency !== null ? (string) $row->floor_currency : null;
            $totalVolume       = $row->total_volume !== null ? (string) $row->total_volume : null;
            $listedPct         = $row->listed_percentage !== null ? (float) $row->listed_percentage : null;
            $royaltyPct        = $row->royalty_percentage !== null ? (float) $row->royalty_percentage : null;
            $distributionPct   = self::distributionPct($uniqueHolders, $totalSupply);
            $minBalance        = $minBalanceByGroup[$groupId] ?? null;

            $enrichmentByGroup[$groupId] = [
                'image_url' => $imageUrl,
                'stats'     => [
                    'token_standard'      => $row->token_standard !== null ? (string) $row->token_standard : null,
                    'total_supply'        => $totalSupply,
                    'unique_holders'      => $uniqueHolders,
                    'floor_price'         => $floorPrice,
                    'floor_currency'      => $floorCurrency,
                    'total_volume'        => $totalVolume,
                    'listed_percentage'   => $listedPct,
                    'royalty_percentage'  => $royaltyPct,
                    'distribution_pct'    => $distributionPct,
                    'min_balance'         => $minBalance,
                    'floor_display'       => self::formatPriceDisplay($floorPrice, $floorCurrency),
                    'volume_display'      => self::formatVolumeDisplay($totalVolume, $floorCurrency),
                    'holders_display'     => self::formatHoldersDisplay($uniqueHolders, $distributionPct),
                    'supply_display'      => self::formatIntegerDisplay($totalSupply),
                    'listed_display'      => self::formatPercentDisplay($listedPct),
                    'royalty_display'     => self::formatPercentDisplay($royaltyPct),
                    'min_balance_display' => self::formatMinBalanceDisplay($minBalance),
                    'marketplace'         => self::resolveMarketplaceLink(
                        (string) $row->chain_slug,
                        (string) $row->contract_address
                    ),
                ],
            ];
        }

        return $enrichmentByGroup;
    }

    private static function distributionPct(?int $holders, ?int $supply): ?int
    {
        if ($holders === null || $supply === null || $supply <= 0) {
            return null;
        }
        return (int) round(($holders / $supply) * 100);
    }

    private static function formatPriceDisplay(?string $rawPrice, ?string $currency): ?string
    {
        if ($rawPrice === null || $currency === null) {
            return null;
        }
        $n = (float) $rawPrice;
        if (!is_finite($n) || $n <= 0.0) {
            return '—';
        }
        return number_format($n, 2) . ' ' . $currency;
    }

    private static function formatVolumeDisplay(?string $rawVolume, ?string $currency): ?string
    {
        if ($rawVolume === null || $currency === null) {
            return null;
        }
        $n = (float) $rawVolume;
        if (!is_finite($n) || $n <= 0.0) {
            return '—';
        }
        return self::compactNumber($n) . ' ' . $currency;
    }

    private static function formatHoldersDisplay(?int $holders, ?int $distributionPct): ?string
    {
        if ($holders === null) {
            return null;
        }
        $base = number_format($holders);
        if ($distributionPct === null) {
            return $base;
        }
        return $base . ' (' . $distributionPct . '% dist.)';
    }

    private static function formatIntegerDisplay(?int $n): ?string
    {
        if ($n === null) {
            return null;
        }
        if ($n <= 0) {
            return '—';
        }
        return number_format($n);
    }

    private static function formatPercentDisplay(?float $n): ?string
    {
        if ($n === null) {
            return null;
        }
        return number_format($n, 2) . '%';
    }

    /**
     * Compact-format a number — 640601464 → "640.6M", 1234567 → "1.2M",
     * 1234 → "1.2K". Mirrors `Intl.NumberFormat({ notation: 'compact' })`
     * client-side for the simple thresholds we care about.
     */
    private static function compactNumber(float $n): string
    {
        $abs = abs($n);
        if ($abs >= 1.0e9) {
            return number_format($n / 1.0e9, 1) . 'B';
        }
        if ($abs >= 1.0e6) {
            return number_format($n / 1.0e6, 1) . 'M';
        }
        if ($abs >= 1.0e3) {
            return number_format($n / 1.0e3, 1) . 'K';
        }
        return number_format($n);
    }

    /**
     * Format a min-balance gate threshold as a display string ("1 NFT"
     * vs "5 NFTs"). Returns null when the gate is unset or zero — a
     * truly ungated group has no "Requires" line on the card.
     */
    private static function formatMinBalanceDisplay(?int $minBalance): ?string
    {
        if ($minBalance === null || $minBalance < 1) {
            return null;
        }
        if ($minBalance === 1) {
            return '1 NFT';
        }
        return number_format($minBalance) . ' NFTs';
    }

    /**
     * Strip tags + collapse whitespace + truncate group post_content
     * to a card-friendly snippet. Returns null when the description is
     * empty after stripping (so the wire never carries an empty string
     * the frontend would have to nullable-check separately).
     */
    private static function truncateDescription(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $stripped = trim((string) wp_strip_all_tags($raw, true));
        $stripped = (string) preg_replace('/\s+/u', ' ', $stripped);
        if ($stripped === '') {
            return null;
        }
        if (function_exists('mb_strlen') && mb_strlen($stripped) > 200) {
            return rtrim(mb_substr($stripped, 0, 200)) . '…';
        }
        if (strlen($stripped) > 200) {
            return rtrim(substr($stripped, 0, 200)) . '…';
        }
        return $stripped;
    }

    /**
     * Resolve the canonical marketplace URL + display label for an NFT
     * collection from chain_slug + contract_address. Returns null when
     * the chain isn't mapped — the frontend hides the link.
     *
     * The mapping is filterable via `bcc_marketplace_link_map` so new
     * chains can be added (or canonical marketplaces swapped) without
     * a code release. Each entry: `['template' => sprintf-string with one
     * %s for the contract address, 'label' => display label]`.
     *
     * V1 covers the Cosmos Hub (stargaze.zone — the Stargaze marketplace
     * app survived the 2026 chain migration and now serves Hub-hosted
     * collections) and the major EVM chains via OpenSea. Solana, NEAR,
     * and the long tail of cosmos chains (Osmosis/Juno/Akash/etc.)
     * return null until canonical marketplace URLs are picked — the
     * surface degrades gracefully.
     *
     * @return array{url: string, label: string}|null
     */
    private static function resolveMarketplaceLink(string $chainSlug, string $contractAddress): ?array
    {
        if ($contractAddress === '' || $chainSlug === '') {
            return null;
        }

        $defaults = [
            // Bare /m/{contract} — the post-Hub-migration app parses a
            // trailing /tokens segment as token_id "tokens" (verified
            // 2026-07-02).
            'cosmos'    => ['template' => 'https://www.stargaze.zone/m/%s',             'label' => 'Stargaze'],
            'ethereum'  => ['template' => 'https://opensea.io/assets/ethereum/%s',     'label' => 'OpenSea'],
            'polygon'   => ['template' => 'https://opensea.io/assets/matic/%s',        'label' => 'OpenSea'],
            'arbitrum'  => ['template' => 'https://opensea.io/assets/arbitrum/%s',     'label' => 'OpenSea'],
            'optimism'  => ['template' => 'https://opensea.io/assets/optimism/%s',     'label' => 'OpenSea'],
            'base'      => ['template' => 'https://opensea.io/assets/base/%s',         'label' => 'OpenSea'],
            'avalanche' => ['template' => 'https://opensea.io/assets/avalanche/%s',    'label' => 'OpenSea'],
            'bsc'       => ['template' => 'https://opensea.io/assets/bsc/%s',          'label' => 'OpenSea'],
        ];

        /** @var array<string, array{template: string, label: string}> $map */
        $map = apply_filters('bcc_marketplace_link_map', $defaults);

        $entry = $map[$chainSlug] ?? null;
        if ($entry === null) {
            return null;
        }

        return [
            'url'   => sprintf($entry['template'], $contractAddress),
            'label' => $entry['label'],
        ];
    }
}
