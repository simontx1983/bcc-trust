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
 *   - card_tier_at_pull → null when no bcc_pull_meta row exists yet
 *   - batch_id          → always null in V1.0 (Phase 3 batching is V2)
 *   - pulled_at         → null when no bcc_pull_meta row exists yet
 *
 * Previously stubbed, now wired (kept for changelog clarity):
 *   - card_kind         → resolved via WatchingRepository::findPageInfoByUserIds
 *
 * Pagination: offset envelope per §1.5 (watchlist is a directory, not
 * a time-ordered feed).
 *
 * Vocabulary note (release N): this service is canonical "Watching";
 * the legacy "Binder" route family in WatchingEndpoint delegates to
 * the same handlers per the additive-deprecation runway (api-contract
 * §1.1.1).
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, Watching Phase 1; renamed from BinderService 2026-05-13)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\PeepSo\PeepSoFollowWriter;
use BCC\Trust\Core\Repositories\WatchingRepository;
use BCC\Trust\Core\Repositories\PageFollowRepository;
use BCC\Trust\Core\Repositories\PullMetaRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Support\CardUrlMap;
use BCC\Trust\Core\Support\PageTypeMap;
use BCC\Trust\Core\Support\ReputationTierMap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type WatchingItemRow from WatchingRepository
 */
final class WatchingService
{
    /**
     * Hard cap on page_size — defended at three layers (route arg
     * max, service clamp, repository clamp). Single value is the
     * source of truth; the endpoint mirrors it.
     */
    public const MAX_PAGE_SIZE = 50;

    /** @var list<string> */
    public const VALID_TARGET_KINDS = ['validator', 'project', 'creator', 'member'];

    // Frontend URL prefix moved to CardUrlMap (single source of truth
    // shared with CardViewService — see §C2 watching-Phase-1 corrections).
    //
    // §C1 reputation tier ↔ card_tier ↔ display label mapping is in
    // ReputationTierMap (Support/) — shared with CardViewService,
    // UserViewService, TierUpgradeListener, CardsSearchEndpoint.

    public function __construct(
        private readonly WatchingRepository $watchingRepo,
        private readonly PullMetaRepository $pullMetaRepo,
        private readonly ReputationRepository $reputationRepo,
        private readonly PageFollowRepository $pageFollowRepo
    ) {
    }

    /**
     * Build the watchlist view-model for a viewer.
     *
     * @return array{
     *   items: list<array{
     *     follow_id: int,
     *     card_kind: string,
     *     is_resolved: bool,
     *     card_id: int,
     *     card_handle: string,
     *     card_tier_at_pull: string|null,
     *     tier_label_at_pull: string|null,
     *     batch_id: string|null,
     *     pulled_at: string|null,
     *     is_legacy: bool,
     *     links: array{card: string},
     *     actions: array{view: array{method: string, href: string}}
     *   }>,
     *   pagination: array{page: int, page_size: int, total: int, total_pages: int}
     * }
     */
    public function getWatching(int $userId, int $page, int $pageSize): array
    {
        $page     = max(1, $page);
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
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
     *   card_kind: string,
     *   is_resolved: bool,
     *   card_id: int,
     *   card_handle: string,
     *   card_slug: string|null,
     *   page_id: int|null,
     *   card_tier_at_pull: string|null,
     *   tier_label_at_pull: string|null,
     *   batch_id: string|null,
     *   pulled_at: string|null,
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

        $pulledAt = $row->pulled_at !== null && $row->pulled_at !== ''
            ? self::toIso8601($row->pulled_at)
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
        //    true  → no bcc_pull_meta sidecar row exists for this
        //            follow. The follow pre-dates the V1 pull pipeline
        //            OR was created via PeepSo's native UI. There is
        //            no real pulled_at timestamp.
        //
        //  Legacy items MUST NOT be surfaced as "recent pulls" in any
        //  UI or feed. They are historical follows with no real pull
        //  moment; treating them as recent activity falsifies history.
        // ────────────────────────────────────────────────────────────
        $isLegacy = $pulledAt === null;

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
        // bcc_pull_meta.tier_at_pull stores card_tier values
        // (legendary/rare/uncommon/common/null) per resolveCardTierForUser
        // — never reputation_tier. Renamed at the API boundary so the
        // field name reflects what's in it; tier_label_at_pull is the
        // pre-rendered display string per §A2.
        $cardTierAtPull = $row->tier_at_pull;
        $tierLabelAtPull = ReputationTierMap::toCardTierLabel($cardTierAtPull);

        return [
            'follow_id'          => (int) $row->follow_id,
            // follow_source discriminates rows that came from the
            // PeepSo user→user graph ('peepso') vs. our page-scoped
            // pre-claim follow store ('page'). The frontend echoes
            // it back on DELETE /me/watching/{id} (or the legacy
            // /me/binder/{id}) so the server routes
            // the unpull to the right table without an ID collision.
            'follow_source'      => 'peepso',
            'card_kind'          => $cardKind,
            'is_resolved'        => $isResolved,
            'card_id'            => (int) $row->card_user_id,
            'card_handle'        => $handle,
            'card_slug'          => $slug,
            'page_id'            => $pageInfo !== null ? (int) $pageInfo->page_id : null,
            'card_tier_at_pull'  => $cardTierAtPull,
            'tier_label_at_pull' => $tierLabelAtPull,
            'batch_id'           => $row->batch_id,
            'pulled_at'          => $pulledAt,
            'is_legacy'          => $isLegacy,
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
     *     tier_at_pull: string|null,
     *     created_at: string
     * } $row
     * @return array{
     *   follow_id: int,
     *   card_kind: string,
     *   is_resolved: bool,
     *   card_id: int,
     *   card_handle: string,
     *   card_slug: string|null,
     *   page_id: int|null,
     *   card_tier_at_pull: string|null,
     *   tier_label_at_pull: string|null,
     *   batch_id: string|null,
     *   pulled_at: string|null,
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

        $tierAtPull = $row->tier_at_pull;
        $tierLabel  = ReputationTierMap::toCardTierLabel($tierAtPull);

        $createdAt = self::toIso8601($row->created_at);

        return [
            // The id here is the bcc_page_follows row id — disambiguated
            // from peepso follow IDs by the follow_source field below.
            // Server uses both (id + source) to route DELETE.
            'follow_id'          => (int) $row->id,
            'follow_source'      => 'page',
            'card_kind'          => $cardKind,
            'is_resolved'        => true,
            // For page-follows, card_id is the page's wp_post ID so the
            // frontend's "{kind}-{id}" lookup against the cards-list
            // response (where Card.id is the wp_post ID) matches the
            // same key. Members never go through this code path.
            'card_id'            => $pageId,
            'card_handle'        => $slug ?? (string) $pageId,
            'card_slug'          => $slug,
            'page_id'            => $pageId,
            'card_tier_at_pull'  => $tierAtPull,
            'tier_label_at_pull' => $tierLabel,
            'batch_id'           => null,
            'pulled_at'          => $createdAt !== '' ? $createdAt : null,
            // Page-follows are always a real pull moment — never legacy
            // (legacy means "PeepSo follow with no BCC pull record").
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
     * on the same placeholder returns status='already_pulled' the second
     * time and does NOT re-fire `bcc_card_pulled` / `bcc_card_watched`.
     *
     * Validates the target shape (peepso-page, publish, author=0)
     * before writing — anything else still surfaces as
     * `bcc_not_found` so we don't silently start following
     * member/post/comment rows via the wrong path.
     *
     * @return array{
     *   status: 'pulled'|'already_pulled',
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

        // tier_at_pull for placeholders: read from the page's read-model
        // row when present; null otherwise. Matches the existing
        // resolveCardTierForUser pattern but page-scoped.
        $tierAtPull = self::resolveCardTierForPage($targetId);

        $result = $this->pageFollowRepo->insertOrFind($viewerId, $targetId, $targetKind, $tierAtPull);
        if ($result['id'] === 0) {
            return ['error' => 'bcc_internal_error', 'message' => 'Failed to record follow.'];
        }

        if ($result['inserted']) {
            // Dual-emit during release N (additive-deprecation runway):
            //   - bcc_card_pulled  (legacy event, kept for back-compat;
            //     dropped in release N+1)
            //   - bcc_card_watched (new canonical event)
            // Subscribers MUST attach to exactly ONE of the two to avoid
            // double-processing. The Plugin.php registrations still bind
            // to bcc_card_pulled during release N.
            do_action('bcc_card_pulled',  $viewerId, $result['id'], $targetKind, $targetId);
            do_action('bcc_card_watched', $viewerId, $result['id'], $targetKind, $targetId);
        }

        $row = $this->pageFollowRepo->findById($result['id']);
        if ($row === null) {
            return ['error' => 'bcc_internal_error', 'message' => 'Watch recorded but item not retrievable.'];
        }

        return [
            'status' => $result['inserted'] ? 'pulled' : 'already_pulled',
            'item'   => self::buildItemFromPageFollow($row, $post),
        ];
    }

    /**
     * Map a placeholder page's current read-model tier to a card_tier
     * for tier_at_pull. Returns null when the read-model row hasn't
     * projected yet — same null semantics buildItem already handles.
     */
    private static function resolveCardTierForPage(int $pageId): ?string
    {
        global $wpdb;
        $rmTable = \BCC\Trust\Core\Database\TableRegistry::pageReadModel();

        $tier = $wpdb->get_var($wpdb->prepare(
            "SELECT reputation_tier FROM {$rmTable} WHERE page_id = %d LIMIT 1",
            $pageId
        ));

        if ($tier === null || $tier === '') {
            return null;
        }
        return ReputationTierMap::toCardTier((string) $tier);
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
    // Vocabulary note (release N): public methods named watch/unwatch
    // for canonical clarity. Legacy callers can continue to invoke
    // pull/unpull via the deprecated WatchingEndpoint route family,
    // which delegates to these methods.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Watch a card (add it to the viewer's watchlist).
     *
     * Per §C2: watching = creating a peepso_user_followers row +
     * writing a bcc_pull_meta sidecar. The follow itself is the
     * source of truth; bcc_pull_meta carries the BCC-specific extras
     * (tier_at_pull, batch_id, visibility).
     *
     * Idempotent: if the viewer is already following the resolved
     * target, returns the existing item with status='already_pulled'
     * and does NOT re-fire the bcc_card_pulled / bcc_card_watched
     * events or rewrite tier_at_pull (preserves historical record of
     * the original watch).
     *
     * @return array{
     *   status: 'pulled'|'already_pulled',
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

        $existingMeta = $this->pullMetaRepo->find($followId);
        $alreadyPulled = $existingMeta !== null;

        if (!$alreadyPulled) {
            // First-time watch: write the sidecar with the followee's
            // current card_tier preserved. tier_at_pull is the
            // *card_tier* (legendary/rare/...) per the schema docblock,
            // not the reputation_tier — map at write time.
            $cardTier = self::resolveCardTierForUser($followeeId);
            $this->pullMetaRepo->insert($followId, $cardTier, null /* batch_id — owned by WatchBatchAggregator */);
            // Dual-emit during release N (additive-deprecation runway):
            //   - bcc_card_pulled  (legacy, dropped in release N+1)
            //   - bcc_card_watched (new canonical)
            // Subscribers MUST attach to exactly ONE of the two to
            // avoid double-processing. Plugin.php registrations still
            // bind to bcc_card_pulled during release N.
            do_action('bcc_card_pulled',  $viewerId, $followId, $targetKind, $targetId);
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
            'status' => $alreadyPulled ? 'already_pulled' : 'pulled',
            'item'   => self::buildItem($row, $userPage),
        ];
    }

    /**
     * Unwatch (remove a card from the viewer's watchlist).
     *
     * Sets uf_follow=0 on the existing PeepSo row (preserves uf_id
     * for audit) and DELETEs the bcc_pull_meta sidecar (per §C2 cascade).
     *
     * Returns success only when the viewer actually owned the follow
     * — cross-user unwatch attempts get 'bcc_not_found' to avoid
     * leaking ownership info.
     *
     * @return array{status: 'unpulled', follow_id: int}|array{error: string, message: string}
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

            do_action('bcc_card_unpulled', $viewerId, $followId, 0);
            return ['status' => 'unpulled', 'follow_id' => $followId];
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

        // §C2 cascade: bcc_pull_meta does not outlive its follow.
        $this->pullMetaRepo->delete($followId);

        do_action('bcc_card_unpulled', $viewerId, $followId, $followeeId);

        return ['status' => 'unpulled', 'follow_id' => $followId];
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
     * Map the followee's current reputation_tier → card_tier per §C1.
     * Returns null for risky tier (entity hidden from card UI per §C1).
     */
    private function resolveCardTierForUser(int $userId): ?string
    {
        return ReputationTierMap::toCardTier($this->reputationRepo->getTier($userId));
    }
}
