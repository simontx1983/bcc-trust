<?php
/**
 * Binder Service — composes the GET /bcc/v1/me/binder response.
 *
 * Per §C2: the binder is a UI-layer projection of PeepSo follows.
 * This service is the read-side composer; mutation services
 * (pull/unpull/batch) ship in Phase 2/3 alongside their endpoints.
 *
 * Scope (this file):
 *   - Read paginated binder items for a viewer
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
 *   - card_kind         → resolved via BinderRepository::findPageInfoByUserIds
 *
 * Pagination: offset envelope per §1.5 (binder is a directory, not
 * a time-ordered feed).
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, Binder Phase 1)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\PeepSo\PeepSoFollowWriter;
use BCC\Trust\Core\Repositories\BinderRepository;
use BCC\Trust\Core\Repositories\PullMetaRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Support\CardUrlMap;
use BCC\Trust\Core\Support\PageTypeMap;
use BCC\Trust\Core\Support\ReputationTierMap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type BinderItemRow from BinderRepository
 */
final class BinderService
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
    // shared with CardViewService — see §C2 binder-Phase-1 corrections).
    //
    // §C1 reputation tier ↔ card_tier ↔ display label mapping is in
    // ReputationTierMap (Support/) — shared with CardViewService,
    // UserViewService, TierUpgradeListener, CardsSearchEndpoint.

    public function __construct(
        private readonly BinderRepository $binderRepo,
        private readonly PullMetaRepository $pullMetaRepo,
        private readonly ReputationRepository $reputationRepo
    ) {
    }

    /**
     * Build the binder view-model for a viewer.
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
    public function getBinder(int $userId, int $page, int $pageSize): array
    {
        $page     = max(1, $page);
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $offset   = ($page - 1) * $pageSize;

        // ────────────────────────────────────────────────────────────
        //  Query budget (LOCKED — do not exceed 3 queries per binder
        //  request without an explicit contract amendment):
        //
        //    1. countItemsForUser     (pagination total)
        //    2. findItemsForUser      (cross-table cursor read)
        //    3. findPageInfoByUserIds (Phase 2 reverse lookup)
        //
        //  Adding a 4th would re-introduce N+1 via the back door.
        //  If a follow-up needs more data, fold it into one of the
        //  existing queries or pre-cache; do not append a new query.
        // ────────────────────────────────────────────────────────────
        $total      = $this->binderRepo->countItemsForUser($userId);
        $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 0;

        $rows = $this->binderRepo->findItemsForUser($userId, $offset, $pageSize);

        // Phase 2: bulk-resolve page-info for all followed users in
        // this page. Users without a peepso-page stay as 'member';
        // users with one flip to validator / project / creator and
        // gain is_resolved=true. One query for the whole feed page;
        // no N+1.
        $followeeUserIds = [];
        foreach ($rows as $row) {
            $followeeUserIds[] = (int) $row->card_user_id;
        }
        $followeeUserIds = array_values(array_unique($followeeUserIds));
        $pageInfo = $this->binderRepo->findPageInfoByUserIds($followeeUserIds);

        $items = [];
        foreach ($rows as $row) {
            $userPage = $pageInfo[(int) $row->card_user_id] ?? null;
            $items[] = self::buildItem($row, $userPage);
        }

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
     * @param BinderItemRow $row
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

        // Identifier rule (locked per binder Phase-1 correction):
        // member uses bcc_handle, page kinds use post_name (slug).
        // Phase 2 fills $slug from page resolution; resolveCardIdentifier
        // flips the identifier automatically — no field/shape change.
        $identifier = self::resolveCardIdentifier($cardKind, $handle, $slug);

        $cardLink   = CardUrlMap::frontendUrl($cardKind, $identifier);
        $cardApiUrl = CardUrlMap::cardApiUrl($cardKind, $identifier);

        // ────────────────────────────────────────────────────────────
        //  Renderable invariant (LOCKED): every binder item MUST carry
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
     * Pick the canonical identifier for a card_kind. The kind dictates
     * whether the second URL segment is a bcc_handle (member) or a
     * post_name slug (page-backed kinds).
     *
     * Locked structure (binder Phase-1 correction). Phase 2 supplies
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
    // Phase 2 mutations (pull / unpull) — single-graph rule via
    // PeepSoFollowWriter. NO batching here (Phase 3 owns the §C3
    // 10-minute rolling-window aggregator).
    // ──────────────────────────────────────────────────────────────────

    /**
     * Pull a card into the viewer's binder.
     *
     * Per §C2: pulling = creating a peepso_user_followers row +
     * writing a bcc_pull_meta sidecar. The follow itself is the
     * source of truth; bcc_pull_meta carries the BCC-specific extras
     * (tier_at_pull, batch_id, visibility).
     *
     * Idempotent: if the viewer is already following the resolved
     * target, returns the existing item with status='already_pulled'
     * and does NOT re-fire the bcc_card_pulled event or rewrite
     * tier_at_pull (preserves historical record of the original pull).
     *
     * @return array{
     *   status: 'pulled'|'already_pulled',
     *   item: array<string, mixed>
     * }|array{error: string, message: string}
     */
    public function pull(int $viewerId, string $targetKind, int $targetId): array
    {
        if (!in_array($targetKind, self::VALID_TARGET_KINDS, true)) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid target_kind.'];
        }
        if ($viewerId <= 0 || $targetId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'viewer and target_id are required.'];
        }

        $followeeId = self::resolveFollowee($targetKind, $targetId);
        if ($followeeId === 0) {
            return ['error' => 'bcc_not_found', 'message' => 'Target not found.'];
        }

        if ($followeeId === $viewerId) {
            return ['error' => 'bcc_invalid_request', 'message' => 'You cannot pull yourself.'];
        }

        $followId = PeepSoFollowWriter::follow($viewerId, $followeeId);
        if ($followId === 0) {
            return ['error' => 'bcc_internal_error', 'message' => 'Failed to record follow.'];
        }

        $existingMeta = $this->pullMetaRepo->find($followId);
        $alreadyPulled = $existingMeta !== null;

        if (!$alreadyPulled) {
            // First-time pull: write the sidecar with the followee's
            // current card_tier preserved. tier_at_pull is the
            // *card_tier* (legendary/rare/...) per the schema docblock,
            // not the reputation_tier — map at write time.
            $cardTier = self::resolveCardTierForUser($followeeId);
            $this->pullMetaRepo->insert($followId, $cardTier, null /* batch_id — Phase 3 */);
            do_action('bcc_card_pulled', $viewerId, $followId, $targetKind, $targetId);
        }

        $row = $this->binderRepo->findItemByFollowId($viewerId, $followId);
        if ($row === null) {
            // Should not happen: we just inserted the follow + meta.
            // If it does, the writer failed and surfacing the row
            // wouldn't help anyway — return an honest error.
            return ['error' => 'bcc_internal_error', 'message' => 'Pull recorded but item not retrievable.'];
        }

        // Phase 2: resolve the followee's page info so the returned
        // binder item carries the right card_kind / is_resolved /
        // identifier per the locked contract — same shape as items
        // from GET /me/binder.
        $pageInfo = $this->binderRepo->findPageInfoByUserIds([(int) $row->card_user_id]);
        $userPage = $pageInfo[(int) $row->card_user_id] ?? null;

        return [
            'status' => $alreadyPulled ? 'already_pulled' : 'pulled',
            'item'   => self::buildItem($row, $userPage),
        ];
    }

    /**
     * Unpull (remove a card from the viewer's binder).
     *
     * Sets uf_follow=0 on the existing PeepSo row (preserves uf_id
     * for audit) and DELETEs the bcc_pull_meta sidecar (per §C2 cascade).
     *
     * Returns success only when the viewer actually owned the follow
     * — cross-user unpull attempts get 'bcc_not_found' to avoid
     * leaking ownership info.
     *
     * @return array{status: 'unpulled', follow_id: int}|array{error: string, message: string}
     */
    public function unpull(int $viewerId, int $followId): array
    {
        if ($viewerId <= 0 || $followId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'follow_id is required.'];
        }

        // Read the followee under the ownership predicate before
        // mutating — needed for the bcc_card_unpulled event payload
        // and as the existence check.
        $followeeId = $this->binderRepo->getFolloweeForOwner($viewerId, $followId);
        if ($followeeId === 0) {
            return ['error' => 'bcc_not_found', 'message' => 'Follow not found in your binder.'];
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
