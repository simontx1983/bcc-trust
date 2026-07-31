<?php
/**
 * Cold-start bridge surface — civic map, not a recommendation engine.
 *
 * This service composes three blocks (halls + recent operators + hot
 * posts) for the home-feed empty state. It exists so a cold-start
 * viewer sees "the room is still here," NOT to maximize engagement.
 *
 * Load-bearing rules (changing any of these silently destroys the
 * surface):
 *
 *   - ORDER BY last_activity_at DESC. Never by trust score, reputation,
 *     follower count, review volume, engagement count, or any other
 *     ranking metric. Recency is the only honest order.
 *
 *   - Bounded: 3 halls, 4 operators, 2 hot posts. No pagination,
 *     no "load more." This is a bridge surface, not a browser.
 *
 *   - Stable-random operator selection seeded by (viewer_user_id, date).
 *     Rotates daily so the same 4 operators don't accumulate gravity
 *     across thousands of cold-start sessions.
 *
 *   - Server-rendered `recent_action` text uses ONLY the feed-kind
 *     verb allowlist (REVIEWED, POSTED, WATCHED, VOUCHED, DISPUTED,
 *     DROPPED, RELEASED). No "Has been active" or "Recently engaged" —
 *     present-continuous phrasings imply ongoing demand on the viewer.
 *
 *   - Auth-permissive. Anon viewers see the same panel shape (without
 *     chain-alignment personalization). Forces civic-by-construction.
 *
 *   - If the operators block ever ranks, personalizes, or grows: RETIRE
 *     IT BEFORE THE OTHER TWO. It is the highest-drift-risk block.
 *
 * Deliberately NOT tracked (do not add analytics here):
 *   - Click-through rate on any block.
 *   - Time-spent on DiscoverPanel.
 *   - Per-operator surfacing count.
 *   - "Discover → Follow" conversion (and don't add a Follow button).
 *   - A/B test infrastructure for layout variants.
 *   - Per-block "performance" comparison.
 *
 * Operational telemetry that IS allowed: response latency, error rate,
 * all-three-empty alarm signal.
 *
 * @package BCC\Trust\Core\Services
 * @since   V1 (2026-05, Sprint 3 cold-start)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Repositories\UserSyncRepository;
use BCC\Trust\Core\Services\Feed\FeedRankingService;
use BCC\Trust\Core\Support\ReputationTierMap;
use BCC\Trust\Onchain\Repositories\ChainRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedColdStartService
{
    /** Bounded counts — see class doctype. Do NOT widen. */
    private const HALLS_LIMIT           = 3;
    private const OPERATORS_LIMIT       = 4;
    private const OPERATORS_CANDIDATES  = 12; // wider pool for stable-random shuffle
    private const OPERATORS_WINDOW_DAYS = 7;
    private const HOT_POSTS_LIMIT       = 2;

    /**
     * Verb allowlist by feed module-id (peepso_activities.act_module_id).
     * Mapping mirrors {@see \BCC\Core\Feed\FeedItemNormalizer::MODULE_TO_KIND}
     * but in PAST TENSE so the wire text is never present-continuous.
     *
     * Modules outside this list resolve to the fall-back "Recently on
     * the floor." — DO NOT add a generic "is active" mapping. The whole
     * point of the allowlist is to refuse phrasings that imply demand
     * on the viewer.
     */
    private const VERB_BY_MODULE = [
        '1'          => 'POSTED a status',
        '4'          => 'POSTED a photo',
        'status'     => 'POSTED a status',
        'review'     => 'REVIEWED a card',
        'dispute'    => 'SIGNED a dispute',
        'signal'     => 'WATCHED a card',
        'watch_batch' => 'WATCHED cards',
        'page_claim' => 'VOUCHED for a page',
        'project'    => 'DROPPED a release',
        'nft'        => 'RELEASED a piece',
        'blog'       => 'POSTED a blog excerpt',
    ];

    private const FALLBACK_RECENT_ACTION = 'Recently on the floor.';

    private UserViewService $userViewService;
    private FeedRankingService $feedRankingService;

    public function __construct(
        UserViewService $userViewService,
        FeedRankingService $feedRankingService
    ) {
        $this->userViewService    = $userViewService;
        $this->feedRankingService = $feedRankingService;
    }

    /**
     * Compose the three-block cold-start payload for a viewer.
     *
     * `$viewerId === null` (or 0) → anon caller. The Halls block is NOT
     * filtered by chain alignment (no usermeta to read); operators
     * block stable-random seed uses the date alone, so all anon viewers
     * see the same 4 operators on a given UTC day.
     *
     * @return array{
     *   halls: list<array{slug:string,name:string,chain_slug:?string,member_count:int}>,
     *   recent_operators: list<array{handle:string,display_name:string,avatar_url:string,reputation_tier:string,reputation_tier_label:string,rank_label:string|null,recent_action:string,link:string}>,
     *   hot_posts: list<array<string, mixed>>
     * }
     */
    public function getColdStart(?int $viewerId): array
    {
        $viewerIdInt = ($viewerId !== null && $viewerId > 0) ? $viewerId : 0;

        return [
            'halls'            => $this->composeHalls($viewerIdInt),
            'recent_operators' => $this->composeOperators($viewerIdInt),
            'hot_posts'        => $this->composeHotPosts(),
        ];
    }

    /**
     * Halls block — 3 entries.
     *
     * Chain-alignment: when the viewer has `bcc_home_chain` usermeta set
     * (written by OnboardingEndpoint on the §B4 wizard), prefer the Hall
     * for that chain. Anon viewers and authed-without-chain see the
     * default order (post_title ASC, matching /halls).
     *
     * Returns < 3 entries gracefully (the FE collapses missing entries).
     *
     * @return list<array{slug:string,name:string,chain_slug:?string,member_count:int}>
     */
    private function composeHalls(int $viewerId): array
    {
        // Resolve the viewer's home-chain slug to a numeric chain id for
        // the meta-based Hall filter. An unknown/absent slug → null → the
        // default (all-chains) Hall ordering.
        $chainSlug = $this->resolveHomeChain($viewerId);
        $chainId   = $chainSlug !== null ? ChainRepository::resolveIdAnyState($chainSlug) : null;

        $rows = PeepSoGroupRepository::listHalls($chainId, 0, self::HALLS_LIMIT);

        $groupIds   = array_map(static fn($r) => (int) $r->id, $rows);
        $chainByGroup = $groupIds === [] ? [] : ChainRepository::resolveSlugsForGroups($groupIds);

        $out = [];
        foreach ($rows as $row) {
            // Chain slug comes from the Hall's `_bcc_chain_tag` meta via
            // ChainRepository — the canonical source, shared with /halls,
            // so the cold-start surface and directory can never diverge.
            $out[] = [
                'slug'         => (string) $row->post_name,
                'name'         => (string) $row->post_title,
                'chain_slug'   => $chainByGroup[(int) $row->id] ?? null,
                'member_count' => (int) $row->member_count,
            ];
        }
        return $out;
    }

    /**
     * Resolve the viewer's home chain from wp_usermeta.bcc_home_chain.
     * Returns null for anon, missing meta, or non-string values.
     */
    private function resolveHomeChain(int $viewerId): ?string
    {
        if ($viewerId <= 0) {
            return null;
        }
        $value = get_user_meta($viewerId, 'bcc_home_chain', true);
        if (!is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }

    /**
     * Operators block — 4 entries, stable-randomized within a 12-wide
     * candidate pool of the most-recently-active users (last 7 days,
     * ORDER BY usr_last_activity DESC).
     *
     * Stable-random seed: hash('sha256', viewerId|YYYY-MM-DD). Same
     * viewer + same UTC day → same ordering. Next UTC day → different
     * ordering. Anon viewers share the per-day seed (viewerId=0 is the
     * constant input for that branch).
     *
     * @return list<array{handle:string,display_name:string,avatar_url:string,reputation_tier:string,reputation_tier_label:string,rank_label:string|null,recent_action:string,link:string}>
     */
    private function composeOperators(int $viewerId): array
    {
        $candidateIds = UserSyncRepository::getRecentlyActiveUserIds(
            self::OPERATORS_WINDOW_DAYS,
            self::OPERATORS_CANDIDATES,
            $viewerId // excludes self when > 0; no-op when 0 (anon)
        );
        if ($candidateIds === []) {
            return [];
        }

        $shuffled = self::stableShuffle($candidateIds, $viewerId);
        $selected = array_slice($shuffled, 0, self::OPERATORS_LIMIT);

        // Reuse the SAME prefetch bundle UsersEndpoint::members uses, so
        // operator hydration stays N+1-free and the row shape is
        // identical to the /members directory rows. Keep the bundle
        // narrow — we drop the visualizations the cold-start panel
        // doesn't render (verifications, owned_pages_by_type, etc.)
        // BUT the UserViewService::getSummary contract reads ALL keys
        // optionally — missing prefetch keys fall through to per-user
        // queries. To stay strictly N+1-free for the 4 rows we DO render
        // we include the full bundle. The 4-row scale makes the cost
        // trivial vs. the simpler reasoning.
        $prefetched = self::buildOperatorPrefetch($selected);

        $out = [];
        foreach ($selected as $userId) {
            $summary = $this->userViewService->getSummary($userId, $viewerId, $prefetched);
            if ($summary === null) {
                continue;
            }
            $out[] = self::projectOperatorRow($summary, self::resolveRecentAction($userId));
        }
        return $out;
    }

    /**
     * Project the rich UserViewService::getSummary() row down to the
     * cold-start operator wire shape. Server-resolves every visible
     * field (§A2) — the frontend renders the strings verbatim.
     *
     * @param array<string, mixed> $summary
     * @return array{handle:string,display_name:string,avatar_url:string,reputation_tier:string,reputation_tier_label:string,rank_label:string|null,recent_action:string,link:string}
     */
    private static function projectOperatorRow(array $summary, string $recentAction): array
    {
        $handle = is_string($summary['handle'] ?? null) ? (string) $summary['handle'] : '';
        return [
            'handle'        => $handle,
            'display_name'  => is_string($summary['display_name'] ?? null)
                ? (string) $summary['display_name']
                : '',
            'avatar_url'    => is_string($summary['avatar_url'] ?? null)
                ? (string) $summary['avatar_url']
                : '',
            'reputation_tier' => isset($summary['reputation_tier']) && is_string($summary['reputation_tier'])
                ? (string) $summary['reputation_tier']
                : 'neutral',
            'reputation_tier_label' => isset($summary['reputation_tier_label']) && is_string($summary['reputation_tier_label'])
                ? (string) $summary['reputation_tier_label']
                : ReputationTierMap::toReputationTierLabel('neutral'),
            // Nullable since the Rank redesign Phase 5 cutover — New
            // Members carry no rank chip on the operator row.
            'rank_label'    => is_string($summary['rank_label'] ?? null)
                ? (string) $summary['rank_label']
                : null,
            'recent_action' => $recentAction,
            'link'          => $handle !== '' ? '/u/' . $handle : '/',
        ];
    }

    /**
     * Resolve the past-tense `recent_action` line for a user from their
     * single most recent feed-emitting activity row.
     *
     * Locked vocabulary — see {@see VERB_BY_MODULE}. When the user has
     * no qualifying recent activity (their last-seen was a non-feed
     * event — e.g. a profile view), we surface
     * {@see FALLBACK_RECENT_ACTION}. We DO NOT invent a verb.
     *
     * §11 reuse: PeepSoActivityRepository::getActivities is the
     * canonical seam for "activities by author" — same one
     * ActivityFeedService uses for the `following` scope.
     */
    private static function resolveRecentAction(int $userId): string
    {
        if ($userId <= 0) {
            return self::FALLBACK_RECENT_ACTION;
        }

        $rows = PeepSoActivityRepository::getActivities(
            [$userId],
            null,    // any module
            null,
            null,
            1        // single most-recent row
        );
        if ($rows === []) {
            return self::FALLBACK_RECENT_ACTION;
        }

        $row    = $rows[0];
        $module = (string) ($row->act_module_id ?? '');

        return self::VERB_BY_MODULE[$module] ?? self::FALLBACK_RECENT_ACTION;
    }

    /**
     * Deterministic shuffle of `$ids` seeded by `(viewerId, UTC date)`.
     *
     * Idiom is intentionally inline (per the brief's "no premature
     * utility extraction" rule). The only other place a deterministic
     * hash appears in this codebase is WatchBatchAggregator's batch_id
     * generator, where the inputs and entropy budget are different
     * enough that a shared helper would couple two unrelated concerns.
     *
     * Algorithm: deterministic Fisher–Yates using per-position bytes
     * from a SHA-256 stream over the seed + position counter. This
     * avoids any reliance on PHP's `mt_srand` (which is process-global
     * state and gets clobbered by other code running in the same
     * request).
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private static function stableShuffle(array $ids, int $viewerId): array
    {
        $count = count($ids);
        if ($count <= 1) {
            return $ids;
        }

        $seedInput = $viewerId . '|' . gmdate('Y-m-d');

        $arr = $ids;
        for ($i = $count - 1; $i > 0; $i--) {
            // 4 bytes of entropy per swap is more than enough for
            // candidate pools of size <= 100 (well below the cap on
            // UserSyncRepository::getRecentlyActiveUserIds).
            $hash = hash('sha256', $seedInput . '|' . $i, true);
            /** @var array{1: int} $bytes */
            $bytes = unpack('N', substr($hash, 0, 4));
            $rand  = $bytes[1];
            $j     = $rand % ($i + 1);

            $tmp     = $arr[$i];
            $arr[$i] = $arr[$j];
            $arr[$j] = $tmp;
        }
        // array_values to satisfy `list<int>` — swaps preserve density
        // but PHPStan reads the indexed writes as a possibly-sparse map.
        return array_values($arr);
    }

    /**
     * Build the §11-reused prefetch bundle for operator hydration. Same
     * shape UsersEndpoint::members assembles, so UserViewService::getSummary
     * can serve identical row data with zero N+1 across the four
     * selected user_ids.
     *
     * We intentionally instantiate the repository helpers statically
     * (matching UsersEndpoint's pattern) rather than DI-wiring all of
     * them through Plugin.php — those are leaf utilities, and the cold-
     * start endpoint already pays the constructor cost once per request.
     *
     * @param list<int> $userIds
     * @return array<string, array<int, mixed>>
     */
    private static function buildOperatorPrefetch(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $solidId = \BCC\Trust\Core\Support\ReactionTypeRegistry::solidId();
        $solidsReceivedCounts = $solidId === null
            ? []
            : (new \BCC\Trust\Core\Repositories\PeepSoReactionRepository())
                ->countReceivedByUsers($userIds, $solidId);

        return [
            'follower_counts'              => \BCC\Core\Repositories\PeepSoFollowerRepository::getFollowersCountForUsers($userIds),
            'primary_halls'                => PeepSoGroupRepository::getPrimaryHallForUsers($userIds),
            'owned_pages_counts'           => UserSyncRepository::getOwnedPageCountsForUsers($userIds),
            'owned_pages_by_type'          => \BCC\Core\Repositories\PeepSoPageRepository::getOwnedPageTypeCountsForUsers($userIds),
            'endorsements_received_counts' => (new \BCC\Trust\Core\Repositories\AttestationRepository())
                ->countActiveVouchesByTargets('user_profile', $userIds),
            'solids_received_counts'       => $solidsReceivedCounts,
            'reviews_written_counts'       => (new \BCC\Trust\Core\Repositories\VoteRepository())
                ->countByVoters($userIds),
            'disputes_signed_counts'       => \BCC\Trust\Disputes\Repositories\DisputeRepository::countByReporters($userIds),
            'wallets_verified_counts'      => \BCC\Trust\Onchain\Repositories\WalletRepository::getVerifiedCountsForUsers($userIds),
            'x_connections'                => (new \BCC\Trust\Core\Repositories\XRepository())
                ->getConnectionsForUsers($userIds),
            'github_connections'           => (new \BCC\Trust\Core\Repositories\GitHubRepository())
                ->getConnectionsForUsers($userIds),
            // Batched member-state for the rank chip (Rank redesign
            // Phase 5) — one bounded rank_state read for the 4 rows.
            'member_states'                => \BCC\Trust\Core\Plugin::instance()
                ->rankStateService()->memberStatesFor($userIds),
        ];
    }

    /**
     * Hot-posts block — 2 entries via FeedRankingService::getHotFeed.
     * Returns the fully-hydrated FeedItem[] (author / body / reactions
     * / etc.) — no extra hydration here, no shape transformation.
     *
     * @return list<array<string, mixed>>
     */
    private function composeHotPosts(): array
    {
        // Request the warm-cron page size (the only hot-feed entry that is
        // primed) and slice locally to the 2 we show. Asking for
        // HOT_POSTS_LIMIT directly keyed a separate `hot:v1:2` entry the cron
        // never warms, so cold-start rebuilt the hot feed inline on every call.
        // [audit L-B6]
        $payload = $this->feedRankingService->getHotFeed(null, FeedRankingService::HOT_WARM_LIMIT);

        $items = $payload['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
            if (count($out) >= self::HOT_POSTS_LIMIT) {
                break;
            }
        }
        return $out;
    }
}
