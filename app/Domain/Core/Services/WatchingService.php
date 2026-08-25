<?php
/**
 * Watching Service — composes the GET /bcc/v1/me/watching response.
 *
 * Per §C2: the watchlist is a UI-layer projection of PeepSo follows.
 * This service is the read-side composer plus the watch/unwatch
 * mutations.
 *
 * Scope (this file):
 *   - Read paginated watch items for a viewer
 *   - card_kind resolves to validator/project/creator when the followed
 *     user has a peepso-page (Phase 2 lookup); falls back to 'member'
 *     for member-only follows. {is_resolved=true, card_kind='member'}
 *     is a valid state for page-backed kinds outside the V1 contract
 *     (e.g., 'dao' page_type).
 *
 * Stubs still in place:
 *   - reputation_tier_at_watch → null when no bcc_watch_meta row exists yet
 *   - watched_at         → null when no bcc_watch_meta row exists yet
 *
 * Previously stubbed, now wired (kept for changelog clarity):
 *   - card_kind         → resolved via WatchingRepository::findPageInfoByUserIds
 *
 * Pagination: offset envelope per §1.5 (watchlist is a directory, not
 * a time-ordered feed).
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, Watching Phase 1)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\PeepSo\PeepSoFollowWriter;
use BCC\Trust\Core\Repositories\WatchingRepository;
use BCC\Trust\Core\Repositories\PageFollowRepository;
use BCC\Trust\Core\Repositories\WatchMetaRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Support\CardUrlMap;
use BCC\Trust\Core\Support\MemberCardPrefetcher;
use BCC\Trust\Core\Support\PageCardPrefetcher;
use BCC\Trust\Core\Support\PageTypeMap;
use BCC\Trust\Core\Support\ReputationTierMap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type WatchingItemRow from WatchingRepository
 *
 * The emitted watch-item shape (contract §4.5). `card` is the v1.76
 * additive hydration slot: absent entirely unless the request carried
 * `include=cards`, and `null` within a hydrated response when the
 * target doesn't resolve to a Card.
 *
 * @phpstan-type WatchingItem array{
 *   follow_id: int,
 *   follow_source: string,
 *   card_kind: string,
 *   is_resolved: bool,
 *   card_id: int,
 *   card_handle: string,
 *   card_slug: string|null,
 *   page_id: int|null,
 *   reputation_tier_at_watch: string|null,
 *   reputation_tier_label_at_watch: string|null,
 *   batch_id: string|null,
 *   watched_at: string|null,
 *   is_legacy: bool,
 *   links: array{card: string},
 *   actions: array{view: array{method: string, href: string, idempotent: bool, requires_auth: bool}},
 *   card?: array<string, mixed>|null
 * }
 */
final class WatchingService
{
    /**
     * Hard cap on page_size — defended at three layers (route arg
     * max, service clamp, repository clamp). Single value is the
     * source of truth; the endpoint mirrors it.
     */
    public const MAX_PAGE_SIZE = 50;

    /**
     * Hard cap on page_size when the caller asks for full-Card
     * hydration (`include=cards`, contract §4.5 / v1.76). Hydration
     * runs one batch prefetch per kind, but the per-row builders still
     * do real work, so the hydrated page is smaller than the
     * identifier-only page. Clamped SILENTLY (no 400) and echoed back
     * in `pagination.page_size`; the route arg max stays MAX_PAGE_SIZE.
     *
     * SCOPE (do not over-read this constant): it bounds the PeepSo page
     * budget only. It does NOT bound the number of cards built on page
     * 1 — getWatching prepends up to 100 bcc_page_follows rows there
     * regardless of page_size, and every returned row is hydrated. See
     * the bound note at the attachCards call site in getWatching.
     */
    public const HYDRATED_MAX_PAGE_SIZE = 24;

    /** @var list<string> */
    public const VALID_TARGET_KINDS = ['validator', 'project', 'creator', 'member'];

    // Frontend URL prefix moved to CardUrlMap (single source of truth
    // shared with CardViewService — see §C2 watching-Phase-1 corrections).
    //
    // Reputation tier → display label mapping is in
    // ReputationTierMap (Support/) — shared with CardViewService,
    // UserViewService, TierUpgradeListener, CardsSearchEndpoint.

    public function __construct(
        private readonly WatchingRepository $watchingRepo,
        private readonly WatchMetaRepository $watchMetaRepo,
        private readonly ReputationRepository $reputationRepo,
        private readonly PageFollowRepository $pageFollowRepo
    ) {
    }

    /**
     * Build the watchlist view-model for a viewer.
     *
     * `$includeCards` is the §4.5 `include=cards` switch: every item
     * gains a `card` key (full §3.2 Card view-model, or null when the
     * target is unhydratable).
     *
     * What hydration does and does not change (stated precisely — an
     * earlier version of this docblock over-claimed full parity):
     *   - `total` is IDENTICAL with and without `include=cards`. It is
     *     computed below from $peepsoTotal + $pageFollowCount and
     *     hydration never touches it.
     *   - Hydration never drops, filters or reorders rows FROM A
     *     RETURNED PAGE. An unhydratable target stays in place with
     *     `card: null`.
     *   - Requesting page_size > 24 with `include=cards` returns a
     *     SMALLER page (clamped to HYDRATED_MAX_PAGE_SIZE), so both
     *     `items` length and `total_pages` differ from the same
     *     unclamped identifier-only request. That is the clamp, not
     *     row dropping.
     *
     * @return array{
     *   items: list<WatchingItem>,
     *   pagination: array{page: int, page_size: int, total: int, total_pages: int}
     * }
     */
    public function getWatching(int $userId, int $page, int $pageSize, bool $includeCards = false): array
    {
        $page = max(1, $page);
        // Clamp BEFORE the offset math so the LIMIT, the offset and the
        // echoed pagination.page_size can never disagree.
        $pageSize = self::clampPageSize($pageSize, $includeCards);
        $offset   = ($page - 1) * $pageSize;

        // ────────────────────────────────────────────────────────────
        //  Two-source watchlist (V1.6+):
        //    1. PeepSo user→user follows (the historical source — claimed
        //       pages + member follows go here)
        //    2. bcc_page_follows         (pre-claim placeholder pages,
        //       drained on claim — see PeepSoIntegration::onPageClaimed)
        //
        //  Page-follows accrue slowly (one per unclaimed validator a
        //  viewer cares about) and migrate out as operators claim. V1
        //  always fetches the full set (cap 100) and prepends them on
        //  page 1 only — pagination still anchors on the PeepSo side
        //  for the bulk of follows. When page_follows exceed page 1's
        //  budget, the overflow visibly drops; this is a known V1
        //  limitation that becomes a non-issue post-claim drainage.
        //
        //  Query budget remains capped: countItemsForUser +
        //  findItemsForUser + findPageInfoByUserIds (PeepSo side) +
        //  findByUserId + bulk get_post (page-follow side) = 5 queries.
        //  Acceptable for the directory UX which fires once per session.
        // ────────────────────────────────────────────────────────────

        // ── Page-follows (placeholder pages, pre-claim) ──────────────
        $pageFollowItems = [];
        $pageFollowCount = 0;
        if ($page === 1) {
            $pageFollowRows = $this->pageFollowRepo->findByUserId($userId, 100, 0);
            $pageFollowCount = count($pageFollowRows);

            if ($pageFollowCount > 0) {
                $pageIds = array_map(fn($r) => (int) $r->page_id, $pageFollowRows);
                _prime_post_caches($pageIds, false, false);
                foreach ($pageFollowRows as $pfRow) {
                    $post = get_post((int) $pfRow->page_id);
                    if ($post instanceof \WP_Post && $post->post_type === 'peepso-page') {
                        $pageFollowItems[] = self::buildItemFromPageFollow($pfRow, $post);
                    }
                }
            }
        } else {
            // page > 1 — still need the count for accurate totals.
            $pageFollowCount = $this->pageFollowRepo->countByUserId($userId);
        }

        // ── PeepSo follows (legacy source) ───────────────────────────
        $peepsoTotal = $this->watchingRepo->countItemsForUser($userId);

        // Leave room on page 1 for the page-follow items above. Page 2+
        // uses the full pageSize budget for PeepSo follows.
        $peepsoBudget = $page === 1
            ? max(0, $pageSize - count($pageFollowItems))
            : $pageSize;

        $rows = $peepsoBudget > 0
            ? $this->watchingRepo->findItemsForUser($userId, $offset, $peepsoBudget)
            : [];

        $followeeUserIds = [];
        foreach ($rows as $row) {
            $followeeUserIds[] = (int) $row->card_user_id;
        }
        $followeeUserIds = array_values(array_unique($followeeUserIds));
        $pageInfo = $this->watchingRepo->findPageInfoByUserIds($followeeUserIds);

        $peepsoItems = [];
        foreach ($rows as $row) {
            $userPage = $pageInfo[(int) $row->card_user_id] ?? null;
            $peepsoItems[] = self::buildItem($row, $userPage);
        }

        $items = array_merge($pageFollowItems, $peepsoItems);

        // ────────────────────────────────────────────────────────────
        //  LOAD-BEARING FOR THE ADDITIVE CONTRACT (§4.5 / v1.76):
        //  this `if` is the ONE line that makes `card` absent rather
        //  than null on an identifier-only request. attachBuiltCards
        //  writes a `card` key on EVERY row it walks (null included),
        //  so the absent-vs-null distinction lives here and nowhere
        //  else. Do not hoist, inline or "simplify" this guard.
        //
        //  HYDRATION BOUND ON PAGE 1 (accepted, deliberate):
        //  page 1 prepends up to 100 bcc_page_follows rows on top of
        //  the PeepSo budget, so the real hydrated bound there is
        //  min(100, N_pageFollows) + pageSize — ~124 Cards worst case,
        //  not the 24 the clamp implies. We do NOT bound the
        //  page-follow fetch to $pageSize in hydrated mode: page
        //  follows render on page 1 only, so the overflow would become
        //  unreachable and rows would silently vanish from the user's
        //  watchlist. Losing rows from the UI is worse than the build
        //  cost. The cost is batched CPU/serialization, not queries:
        //  the prefetch IN() lists are hard-capped at 100 by
        //  PageFollowRepository::findByUserId and there is no per-row
        //  query on a prefetch miss. Page follows also accrue slowly
        //  and drain on claim (PeepSoIntegration::onPageClaimed).
        // ────────────────────────────────────────────────────────────
        if ($includeCards) {
            $items = $this->attachCards($items, $userId);
        }

        $total      = $peepsoTotal + $pageFollowCount;
        $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 0;

        return [
            'items' => $items,
            'pagination' => [
                'page'        => $page,
                'page_size'   => $pageSize,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    /**
     * Effective page_size cap for a request. Pure — no WP, no DB — so
     * the hydrated/identifier-only split is unit-testable without a
     * live site.
     */
    private static function clampPageSize(int $pageSize, bool $includeCards): int
    {
        $max = $includeCards ? self::HYDRATED_MAX_PAGE_SIZE : self::MAX_PAGE_SIZE;
        return max(1, min($max, $pageSize));
    }

    // ──────────────────────────────────────────────────────────────────
    // Full-Card hydration (§4.5 `include=cards`, v1.76)
    //
    // Three phases, deliberately separated so the two that need no I/O
    // stay pure statics:
    //   1. group   — items → hydration targets      (pure)
    //   2. prefetch+build — one batch per kind      (I/O)
    //   3. attach  — targets + built cards → items  (pure)
    //
    // Reuses the canonical list-hydration seam (MemberCardPrefetcher /
    // PageCardPrefetcher + CardViewService::get{Member,Page}CardForList)
    // that /members, /cards and the followers lists already run on — no
    // parallel card builder lives here.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Derive the hydration target for every item, reading ONLY fields
     * the item already carries. Pure: no DB, no WP.
     *
     * Grouping rules (LOCKED with the §4.5 field semantics):
     *   - card_kind 'member' AND follow_source 'peepso' → member target
     *     on `card_id`. `card_id` is the followee user_id ONLY on peepso
     *     rows (see the card_id contract note on buildItem);
     *     buildItemFromPageFollow sets it to the page's wp_post ID
     *     instead, so hydrating a member card from a page row would
     *     resolve user #<post_id> — a wrong-entity leak. The premise
     *     that page rows are never 'member' is upheld three call-frames
     *     away by watchPlaceholderPage's
     *     `in_array($targetKind, ['validator','project','creator'])`
     *     guard, which is the only writer of bcc_page_follows.card_kind.
     *     Checking follow_source here makes that invariant local and
     *     enforced rather than assumed: a member-kind page row yields NO
     *     target and ships with card = null.
     *     Deliberately NOT gated on `is_resolved`: {is_resolved: true,
     *     card_kind: 'member'} is the valid "page-backed but no contract
     *     kind" state (e.g. a dao page) and hydrates as the owner's
     *     member card.
     *   - card_kind validator/project/creator      → page target on
     *     `page_id` (NOT card_id — for peepso rows card_id is the user).
     *     A null/absent page_id yields no target rather than a guess.
     *   - anything else                            → no target (the row
     *     still ships, with card = null).
     *
     * `member_ids` / `page_ids` are deduped for the prefetch IN() lists;
     * two rows pointing at the same target both resolve to the same
     * built card at attach time.
     *
     * @param list<array<string, mixed>> $items
     * @return array{
     *   member_targets: array<int, int>,
     *   page_targets: array<int, array{kind: string, page_id: int}>,
     *   member_ids: list<int>,
     *   page_ids: list<int>
     * }
     */
    private static function groupHydrationTargets(array $items): array
    {
        /** @var array<int, int> $memberTargets */
        $memberTargets = [];
        /** @var array<int, array{kind: string, page_id: int}> $pageTargets */
        $pageTargets = [];
        /** @var array<int, true> $memberIds */
        $memberIds = [];
        /** @var array<int, true> $pageIds */
        $pageIds = [];

        foreach ($items as $index => $item) {
            $kind = isset($item['card_kind']) ? (string) $item['card_kind'] : '';

            if ($kind === 'member') {
                // Defensive: only peepso rows carry a user_id in
                // card_id. A member-kind page row (which
                // watchPlaceholderPage's kind guard prevents from ever
                // being written) would otherwise hydrate the member
                // card for user #<post_id>. No target → card: null.
                $source = isset($item['follow_source']) ? (string) $item['follow_source'] : '';
                if ($source !== 'peepso') {
                    continue;
                }

                $userId = isset($item['card_id']) ? (int) $item['card_id'] : 0;
                if ($userId > 0) {
                    $memberTargets[$index] = $userId;
                    $memberIds[$userId]    = true;
                }
                continue;
            }

            if (!in_array($kind, ['validator', 'project', 'creator'], true)) {
                continue;
            }

            $pageId = isset($item['page_id']) ? (int) $item['page_id'] : 0;
            if ($pageId <= 0) {
                continue;
            }
            $pageTargets[$index] = ['kind' => $kind, 'page_id' => $pageId];
            $pageIds[$pageId]    = true;
        }

        return [
            'member_targets' => $memberTargets,
            'page_targets'   => $pageTargets,
            'member_ids'     => array_keys($memberIds),
            'page_ids'       => array_keys($pageIds),
        ];
    }

    /**
     * Walk the items in their original order and append the built card
     * as the LAST key of each. Pure: takes already-built card maps, so
     * it is unit-testable without CardViewService.
     *
     * Every item gets a `card` key — null when no target was derived or
     * the builder returned null. Nothing is filtered: count and order
     * are identical to the input.
     *
     * This seam UNCONDITIONALLY writes `card` on every row it walks, so
     * it can never produce the identifier-only shape. "Key absent unless
     * `include=cards`" is owned solely by the `if ($includeCards)` guard
     * in getWatching — see the note there.
     *
     * @param list<WatchingItem> $items
     * @param array{
     *   member_targets: array<int, int>,
     *   page_targets: array<int, array{kind: string, page_id: int}>,
     *   member_ids: list<int>,
     *   page_ids: list<int>
     * } $grouped
     * @param array<int, array<string, mixed>>    $memberCards user_id => Card
     * @param array<string, array<string, mixed>> $pageCards   "kind:page_id" => Card
     * @return list<WatchingItem>
     */
    private static function attachBuiltCards(
        array $items,
        array $grouped,
        array $memberCards,
        array $pageCards
    ): array {
        $out = [];
        foreach ($items as $index => $item) {
            $card = null;
            if (isset($grouped['member_targets'][$index])) {
                $card = $memberCards[$grouped['member_targets'][$index]] ?? null;
            } elseif (isset($grouped['page_targets'][$index])) {
                $target = $grouped['page_targets'][$index];
                $card   = $pageCards[self::pageCardKey($target['kind'], $target['page_id'])] ?? null;
            }
            // Assigned last so `card` is the final key of the item —
            // the contract shows it as the trailing field.
            $item['card'] = $card;
            $out[]        = $item;
        }
        return $out;
    }

    private static function pageCardKey(string $kind, int $pageId): string
    {
        return $kind . ':' . $pageId;
    }

    /**
     * Hydrate a page of watch items with full Card view-models.
     *
     * One MemberCardPrefetcher batch + one PageCardPrefetcher batch at
     * most (each skipped when its list is empty), then per-target
     * builders that read from the bundle. CardViewService is resolved
     * here rather than injected so the identifier-only path never
     * constructs it.
     *
     * @param list<WatchingItem> $items
     * @return list<WatchingItem>
     */
    private function attachCards(array $items, int $viewerId): array
    {
        if ($items === []) {
            return $items;
        }

        $grouped = self::groupHydrationTargets($items);

        if ($grouped['member_ids'] === [] && $grouped['page_ids'] === []) {
            // Nothing hydratable on this page — still emit `card: null`
            // on every row so the shape is uniform.
            return self::attachBuiltCards($items, $grouped, [], []);
        }

        $cardView = \BCC\Trust\Core\Plugin::instance()->cardViewService();

        /** @var array<int, array<string, mixed>> $memberCards */
        $memberCards = [];
        if ($grouped['member_ids'] !== []) {
            $memberBundle = MemberCardPrefetcher::primeFor($grouped['member_ids'], $viewerId);
            foreach ($grouped['member_ids'] as $memberId) {
                $card = $cardView->getMemberCardForList($memberId, $viewerId, $memberBundle);
                if ($card !== null) {
                    $memberCards[$memberId] = $card;
                }
            }
        }

        /** @var array<string, array<string, mixed>> $pageCards */
        $pageCards = [];
        if ($grouped['page_ids'] !== []) {
            // Dedupe (kind, page_id) pairs — two rows watching the same
            // page build the card once.
            /** @var array<string, array{kind: string, page_id: int}> $uniqueTargets */
            $uniqueTargets = [];
            foreach ($grouped['page_targets'] as $target) {
                $uniqueTargets[self::pageCardKey($target['kind'], $target['page_id'])] = $target;
            }

            $pageBundle = PageCardPrefetcher::primeFor($grouped['page_ids'], $viewerId);
            foreach ($uniqueTargets as $key => $target) {
                // getPageCardForList's internal _bcc_page_type check
                // returns null on a kind/page mismatch — the null card
                // for an unhydratable row falls out for free.
                $card = $cardView->getPageCardForList(
                    $target['kind'],
                    $target['page_id'],
                    $viewerId,
                    $pageBundle
                );
                if ($card !== null) {
                    $pageCards[$key] = $card;
                }
            }
        }

        return self::attachBuiltCards($items, $grouped, $memberCards, $pageCards);
    }

    /**
     * @param WatchingItemRow $row
     * @param object{user_id: int|numeric-string, page_id: int|numeric-string, page_slug: string, page_type: string}|null $pageInfo
     *        Phase-2 page resolution. Null when the followed user has
     *        no peepso-page (item stays 'member'); non-null with a
     *        recognized page_type promotes the item to the matching
     *        kind. Unrecognized page_type values stay 'member' but
     *        with is_resolved=true (we know they have a page; we just
     *        don't have a contract kind for it yet).
     * @return array{
     *   follow_id: int,
     *   follow_source: string,
     *   card_kind: string,
     *   is_resolved: bool,
     *   card_id: int,
     *   card_handle: string,
     *   card_slug: string|null,
     *   page_id: int|null,
     *   reputation_tier_at_watch: string|null,
     *   reputation_tier_label_at_watch: string|null,
     *   batch_id: string|null,
     *   watched_at: string|null,
     *   is_legacy: bool,
     *   links: array{card: string},
     *   actions: array{view: array{method: string, href: string, idempotent: bool, requires_auth: bool}}
     * }
     */
    private static function buildItem(object $row, ?object $pageInfo): array
    {
        // Handle resolution: bcc_handle preferred (per §B6), user_login
        // fallback for accounts that haven't picked a handle yet.
        $handle = $row->card_handle !== null && $row->card_handle !== ''
            ? $row->card_handle
            : $row->user_login;

        $watchedAt = $row->watched_at !== null && $row->watched_at !== ''
            ? self::toIso8601($row->watched_at)
            : null;

        // ────────────────────────────────────────────────────────────
        //  is_resolved + card_kind contract (LOCKED — do not change):
        //
        //    is_resolved = page-backed (we found a peepso-page for the
        //                  followed user), regardless of whether the
        //                  page_type is in the V1 contract.
        //
        //    card_kind   = mapped contract kind (validator / project /
        //                  creator) when the page_type is recognized;
        //                  'member' otherwise.
        //
        //  This means {is_resolved=true, card_kind='member'} is a
        //  valid, intentional state — "page-backed but we don't have
        //  a contract kind for it yet" (e.g., a 'dao' page in V1).
        //  Frontend renders these as generic; future contract
        //  additions can light up the kind without code changes here.
        // ────────────────────────────────────────────────────────────
        $cardKind   = 'member';
        $isResolved = false;
        $slug       = null;

        if ($pageInfo !== null) {
            $isResolved = true;
            $resolvedKind = PageTypeMap::kindForPageType((string) $pageInfo->page_type);
            if ($resolvedKind !== null) {
                $cardKind = $resolvedKind;
                $slug     = $pageInfo->page_slug !== '' ? $pageInfo->page_slug : null;
            }
        }

        // ────────────────────────────────────────────────────────────
        //  is_legacy contract (LOCKED — do not violate in UI/feed):
        //
        //    true  → no bcc_watch_meta sidecar row exists for this
        //            follow. The follow pre-dates the V1 watch pipeline
        //            OR was created via PeepSo's native UI. There is
        //            no real watched_at timestamp.
        //
        //  Legacy items MUST NOT be surfaced as "recent watches" in any
        //  UI or feed. They are historical follows with no real watch
        //  moment; treating them as recent activity falsifies history.
        // ────────────────────────────────────────────────────────────
        $isLegacy = $watchedAt === null;

        // Identifier rule (locked per watching Phase-1 correction):
        // member uses bcc_handle, page kinds use post_name (slug).
        // Phase 2 fills $slug from page resolution; resolveCardIdentifier
        // flips the identifier automatically — no field/shape change.
        $identifier = self::resolveCardIdentifier($cardKind, $handle, $slug);

        $cardLink   = CardUrlMap::frontendUrl($cardKind, $identifier);
        $cardApiUrl = CardUrlMap::cardApiUrl($cardKind, $identifier);

        // ────────────────────────────────────────────────────────────
        //  Renderable invariant (LOCKED): every watch item MUST carry
        //  enough to render a card link + fetch the card view-model:
        //    - card_kind     (always set, even at the 'member' default)
        //    - card_id + card_handle (both populated below)
        //    - links.card    (frontend route)
        //    - actions.view  (API endpoint with method/href + meta)
        //  Do not introduce a code path that elides any of these.
        //
        //  Identifier-per-kind rule (frontend never guesses):
        //    - card_handle   → user identity (bcc_handle); ALWAYS set
        //    - card_slug     → routing slug (post_name); set when
        //                      page-backed AND kind is recognized;
        //                      null otherwise
        //    - rule:         member → use card_handle in URLs
        //                    validator/project/creator → use card_slug
        //  card_id semantics (LOCKED): always the followee user_id,
        //  even when page-backed. Do not switch to post_id later;
        //  the frontend uses card_id as a stable React key anchored
        //  to the underlying follow relationship, not the page.
        // ────────────────────────────────────────────────────────────
        // bcc_watch_meta.tier_at_watch stores REPUTATION tier values
        // (risky/caution/neutral/trusted/elite) as of v1.57 — it previously
        // stored the retired card rarity slugs, and `watch_tier_vocabulary_v1`
        // rewrote the historical rows. Emitted under the
        // reputation_tier_at_watch field name; the label is pre-rendered
        // per §A2.
        $tierAtWatch      = $row->tier_at_watch;
        $tierLabelAtWatch = $tierAtWatch !== null && $tierAtWatch !== ''
            ? ReputationTierMap::toReputationTierLabel((string) $tierAtWatch)
            : null;

        return [
            'follow_id'           => (int) $row->follow_id,
            // follow_source discriminates rows that came from the
            // PeepSo user→user graph ('peepso') vs. our page-scoped
            // pre-claim follow store ('page'). The frontend echoes
            // it back on DELETE /me/watching/{id} so the server routes
            // the unwatch to the right table without an ID collision.
            'follow_source'       => 'peepso',
            'card_kind'           => $cardKind,
            'is_resolved'         => $isResolved,
            'card_id'             => (int) $row->card_user_id,
            'card_handle'         => $handle,
            'card_slug'           => $slug,
            'page_id'             => $pageInfo !== null ? (int) $pageInfo->page_id : null,
            'reputation_tier_at_watch'       => $tierAtWatch,
            'reputation_tier_label_at_watch' => $tierLabelAtWatch,
            'batch_id'            => $row->batch_id,
            'watched_at'          => $watchedAt,
            'is_legacy'           => $isLegacy,
            'links' => [
                'card' => $cardLink,
            ],
            'actions' => [
                'view' => [
                    'method'        => 'GET',
                    'href'          => $cardApiUrl,
                    // /cards/:kind/:id is GET + anonymous-OK per
                    // CardsEndpoint, so the read action is safe to
                    // retry and doesn't require auth.
                    'idempotent'    => true,
                    'requires_auth' => false,
                ],
            ],
        ];
    }

    /**
     * Build a watch item from a `bcc_page_follows` row + the target
     * peepso-page post. Mirrors `buildItem` exactly except for the
     * `follow_source` discriminator (page, not peepso) and the data
     * sources for handle / slug / page_id. The frontend never has to
     * know which source produced the item — fields are uniform.
     *
     * @param object{
     *     id: numeric-string,
     *     user_id: numeric-string,
     *     page_id: numeric-string,
     *     card_kind: string,
     *     tier_at_watch: string|null,
     *     created_at: string
     * } $row
     * @return array{
     *   follow_id: int,
     *   follow_source: string,
     *   card_kind: string,
     *   is_resolved: bool,
     *   card_id: int,
     *   card_handle: string,
     *   card_slug: string|null,
     *   page_id: int|null,
     *   reputation_tier_at_watch: string|null,
     *   reputation_tier_label_at_watch: string|null,
     *   batch_id: string|null,
     *   watched_at: string|null,
     *   is_legacy: bool,
     *   links: array{card: string},
     *   actions: array{view: array{method: string, href: string, idempotent: bool, requires_auth: bool}}
     * }
     */
    private static function buildItemFromPageFollow(object $row, \WP_Post $page): array
    {
        $cardKind = (string) $row->card_kind;
        $slug     = $page->post_name !== '' ? $page->post_name : null;
        $pageId   = (int) $page->ID;

        $identifier = self::resolveCardIdentifier($cardKind, $slug ?? '', $slug);
        $cardLink   = CardUrlMap::frontendUrl($cardKind, $identifier);
        $cardApiUrl = CardUrlMap::cardApiUrl($cardKind, $identifier);

        $tierAtWatch = $row->tier_at_watch;
        $tierLabel   = $tierAtWatch !== null && $tierAtWatch !== '' ? ReputationTierMap::toReputationTierLabel((string) $tierAtWatch) : null;

        $createdAt = self::toIso8601($row->created_at);

        return [
            // The id here is the bcc_page_follows row id — disambiguated
            // from peepso follow IDs by the follow_source field below.
            // Server uses both (id + source) to route DELETE.
            'follow_id'           => (int) $row->id,
            'follow_source'       => 'page',
            'card_kind'           => $cardKind,
            'is_resolved'         => true,
            // For page-follows, card_id is the page's wp_post ID so the
            // frontend's "{kind}-{id}" lookup against the cards-list
            // response (where Card.id is the wp_post ID) matches the
            // same key. Members never go through this code path.
            'card_id'             => $pageId,
            'card_handle'         => $slug ?? (string) $pageId,
            'card_slug'           => $slug,
            'page_id'             => $pageId,
            'reputation_tier_at_watch'       => $tierAtWatch,
            'reputation_tier_label_at_watch' => $tierLabel,
            'batch_id'            => null,
            'watched_at'          => $createdAt !== '' ? $createdAt : null,
            // Page-follows are always a real watch moment — never legacy
            // (legacy means "PeepSo follow with no BCC watch record").
            'is_legacy'          => false,
            'links' => [
                'card' => $cardLink,
            ],
            'actions' => [
                'view' => [
                    'method'        => 'GET',
                    'href'          => $cardApiUrl,
                    'idempotent'    => true,
                    'requires_auth' => false,
                ],
            ],
        ];
    }

    /**
     * Record a follow on a system-minted placeholder page (post_author=0)
     * via the BCC page-follows table. Idempotent — calling watch twice
     * on the same placeholder returns status='already_watching' the second
     * time and does NOT re-fire `bcc_card_watched`.
     *
     * Validates the target shape (peepso-page, publish, author=0)
     * before writing — anything else still surfaces as
     * `bcc_not_found` so we don't silently start following
     * member/post/comment rows via the wrong path.
     *
     * @return array{
     *   status: 'watched'|'already_watching',
     *   item: array<string, mixed>
     * }|array{error: string, message: string}
     */
    private function watchPlaceholderPage(int $viewerId, string $targetKind, int $targetId): array
    {
        // Page-kind only — member cards have a user, not a page.
        if (!in_array($targetKind, ['validator', 'project', 'creator'], true)) {
            return ['error' => 'bcc_not_found', 'message' => 'Target not found.'];
        }

        $post = get_post($targetId);
        if (!$post instanceof \WP_Post
            || $post->post_type !== 'peepso-page'
            || $post->post_status !== 'publish'
            || (int) $post->post_author !== 0
        ) {
            // Not a placeholder page — return the same not-found shape
            // the PeepSo path would return so callers can't probe.
            return ['error' => 'bcc_not_found', 'message' => 'Target not found.'];
        }

        // tier_at_watch for placeholders: read from the page's read-model
        // row when present; null otherwise. Matches the existing
        // resolveTierForUser pattern but page-scoped.
        $tierAtWatch = $this->resolveTierForPage($targetId);

        $result = $this->pageFollowRepo->insertOrFind($viewerId, $targetId, $targetKind, $tierAtWatch);
        if ($result['id'] === 0) {
            return ['error' => 'bcc_internal_error', 'message' => 'Failed to record follow.'];
        }

        if ($result['inserted']) {
            // Canonical watch event — subscribers attach here.
            do_action('bcc_card_watched', $viewerId, $result['id'], $targetKind, $targetId);
        }

        $row = $this->pageFollowRepo->findById($result['id']);
        if ($row === null) {
            return ['error' => 'bcc_internal_error', 'message' => 'Watch recorded but item not retrievable.'];
        }

        return [
            'status' => $result['inserted'] ? 'watched' : 'already_watching',
            'item'   => self::buildItemFromPageFollow($row, $post),
        ];
    }

    /**
     * A placeholder page's current read-model reputation tier, for
     * tier_at_watch. Returns null when the read-model row hasn't projected
     * yet — same null semantics buildItem already handles.
     *
     * Since v1.57 this is a pass-through rather than a mapping: the column
     * stores the reputation tier directly.
     */
    private function resolveTierForPage(int $pageId): ?string
    {
        $tier = \BCC\Trust\Core\Plugin::instance()
            ->pageReadModelRepository()
            ->getReputationTier($pageId);

        if ($tier === null || $tier === '') {
            return null;
        }
        return $tier;
    }

    /**
     * Pick the canonical identifier for a card_kind. The kind dictates
     * whether the second URL segment is a bcc_handle (member) or a
     * post_name slug (page-backed kinds).
     *
     * Locked structure (watching Phase-1 correction). Phase 2 supplies
     * a non-null $slug from page resolution; Phase 1 always passes
     * null. The match shape stays stable across phases — only the
     * value flips.
     */
    private static function resolveCardIdentifier(string $kind, string $handle, ?string $slug): string
    {
        return match ($kind) {
            'member'                          => $handle,
            'validator', 'project', 'creator' => $slug ?? $handle,
            default                           => $handle,
        };
    }

    private static function toIso8601(string $mysqlDatetime): string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    // ──────────────────────────────────────────────────────────────────
    // Mutations (watch / unwatch) — single-graph rule via
    // PeepSoFollowWriter. NO batching here (the §C3 10-minute
    // rolling-window aggregator owns that, see WatchBatchAggregator).
    //
    // ──────────────────────────────────────────────────────────────────

    /**
     * Watch a card (add it to the viewer's watchlist).
     *
     * Per §C2: watching = creating a peepso_user_followers row +
     * writing a bcc_watch_meta sidecar. The follow itself is the
     * source of truth; bcc_watch_meta carries the BCC-specific extras
     * (tier_at_watch, batch_id, watched_at).
     *
     * Idempotent: if the viewer is already following the resolved
     * target, returns the existing item with status='already_watching'
     * and does NOT re-fire the bcc_card_watched event or rewrite
     * tier_at_watch (preserves historical record of the original watch).
     *
     * @return array{
     *   status: 'watched'|'already_watching',
     *   item: array<string, mixed>
     * }|array{error: string, message: string}
     */
    public function watch(int $viewerId, string $targetKind, int $targetId): array
    {
        if (!in_array($targetKind, self::VALID_TARGET_KINDS, true)) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid target_kind.'];
        }
        if ($viewerId <= 0 || $targetId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'viewer and target_id are required.'];
        }

        $followeeId = self::resolveFollowee($targetKind, $targetId);
        if ($followeeId === 0) {
            // Pre-claim fallback: system-minted placeholder pages have
            // post_author=0, so the PeepSo user→user follow path can't
            // resolve a followee. Record the follow in our own page-
            // scoped table instead; on claim, PeepSoIntegration::on
            // PageClaimed migrates these into real PeepSo follows.
            return $this->watchPlaceholderPage($viewerId, $targetKind, $targetId);
        }

        if ($followeeId === $viewerId) {
            return ['error' => 'bcc_invalid_request', 'message' => 'You cannot watch yourself.'];
        }

        $followId = PeepSoFollowWriter::follow($viewerId, $followeeId);
        if ($followId === 0) {
            return ['error' => 'bcc_internal_error', 'message' => 'Failed to record follow.'];
        }

        $existingMeta = $this->watchMetaRepo->find($followId);
        $alreadyWatching = $existingMeta !== null;

        if (!$alreadyWatching) {
            // First-time watch: write the sidecar with the followee's
            // current reputation_tier preserved (v1.57 — the column used to
            // hold the retired card rarity slug, which lost `risky` entirely).
            $tierAtWatch = $this->resolveTierForUser($followeeId);
            $this->watchMetaRepo->insert($followId, $tierAtWatch, null /* batch_id — owned by WatchBatchAggregator */);
            // Canonical watch event — subscribers attach here.
            do_action('bcc_card_watched', $viewerId, $followId, $targetKind, $targetId);
        }

        $row = $this->watchingRepo->findItemByFollowId($viewerId, $followId);
        if ($row === null) {
            // Should not happen: we just inserted the follow + meta.
            // If it does, the writer failed and surfacing the row
            // wouldn't help anyway — return an honest error.
            return ['error' => 'bcc_internal_error', 'message' => 'Watch recorded but item not retrievable.'];
        }

        // Resolve the followee's page info so the returned watch
        // item carries the right card_kind / is_resolved /
        // identifier per the locked contract — same shape as items
        // from GET /me/watching.
        $pageInfo = $this->watchingRepo->findPageInfoByUserIds([(int) $row->card_user_id]);
        $userPage = $pageInfo[(int) $row->card_user_id] ?? null;

        return [
            'status' => $alreadyWatching ? 'already_watching' : 'watched',
            'item'   => self::buildItem($row, $userPage),
        ];
    }

    /**
     * Unwatch (remove a card from the viewer's watchlist).
     *
     * Sets uf_follow=0 on the existing PeepSo row (preserves uf_id
     * for audit) and DELETEs the bcc_watch_meta sidecar (per §C2 cascade).
     *
     * Returns success only when the viewer actually owned the follow
     * — cross-user unwatch attempts get 'bcc_not_found' to avoid
     * leaking ownership info.
     *
     * @return array{status: 'unwatched', follow_id: int}|array{error: string, message: string}
     */
    public function unwatch(int $viewerId, int $followId, string $source = 'peepso'): array
    {
        if ($viewerId <= 0 || $followId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'follow_id is required.'];
        }

        // Source = 'page' → look up + delete in the page-follow table.
        // The IDs in bcc_page_follows are auto-increment and overlap with
        // PeepSo follow IDs, so the discriminator is load-bearing — we
        // can't deduce the table from the id alone.
        if ($source === 'page') {
            $row = $this->pageFollowRepo->findById($followId);
            if ($row === null || (int) $row->user_id !== $viewerId) {
                return ['error' => 'bcc_not_found', 'message' => 'Follow not found in your watchlist.'];
            }
            $deleted = $this->pageFollowRepo->deleteById($followId);
            if ($deleted === false || $deleted === 0) {
                return ['error' => 'bcc_internal_error', 'message' => 'Failed to remove follow.'];
            }

            return ['status' => 'unwatched', 'follow_id' => $followId];
        }

        // Default path — PeepSo user→user unfollow.
        $followeeId = $this->watchingRepo->getFolloweeForOwner($viewerId, $followId);
        if ($followeeId === 0) {
            return ['error' => 'bcc_not_found', 'message' => 'Follow not found in your watchlist.'];
        }

        $unfollowed = PeepSoFollowWriter::unfollow($viewerId, $followeeId);
        if (!$unfollowed) {
            return ['error' => 'bcc_internal_error', 'message' => 'Failed to remove follow.'];
        }

        // §C2 cascade: bcc_watch_meta does not outlive its follow.
        $this->watchMetaRepo->delete($followId);

        return ['status' => 'unwatched', 'follow_id' => $followId];
    }

    /**
     * Resolve a {target_kind, target_id} pair to the actual user_id
     * to follow. PeepSo follows are user→user; for page-cards the
     * follow goes to the page's post_author.
     *
     * Returns 0 when the target can't be resolved (post missing,
     * wrong post_type, user missing, etc.).
     */
    private static function resolveFollowee(string $targetKind, int $targetId): int
    {
        if ($targetKind === 'member') {
            $user = get_userdata($targetId);
            return $user !== false ? (int) $user->ID : 0;
        }

        // Page kinds: target_id is a peepso-page post_id; followee = post_author.
        $post = get_post($targetId);
        if (!$post instanceof \WP_Post
            || $post->post_type !== 'peepso-page'
            || $post->post_status !== 'publish'
        ) {
            return 0;
        }
        return (int) $post->post_author;
    }

    /**
     * The followee's current reputation tier, snapshotted at watch time.
     *
     * Was a reputation_tier → card_tier mapping until v1.57, which meant a
     * risky followee snapshotted as NULL and their watch tile rendered with
     * no tier at all. All five tiers now round-trip.
     */
    private function resolveTierForUser(int $userId): string
    {
        return $this->reputationRepo->getTier($userId);
    }
}
