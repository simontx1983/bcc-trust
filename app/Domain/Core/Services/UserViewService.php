<?php
/**
 * User View Service — composes the GET /bcc/v1/users/:handle response
 * per contract §3.1 and §4.4.
 *
 * Two output variants:
 *   - is_self === true  → includes living, progression, feature_access,
 *     ux_helpers (the four own-only blocks)
 *   - is_self === false → omits the four own-only blocks entirely
 *
 * V1 wiring status (all real, no Phase-1 stubs remain):
 *   - flags                        → resolveFlags() composes V1 slugs
 *                                    from Permissions + wp_usermeta
 *                                    (suspended/shadow_limited/hidden/under_review)
 *   - wallets                      → resolveWallets() reads WalletRepository::getForUser
 *   - living                       → delegated to LivingService::compose
 *   - counts.disputes_signed       → FlagsRepository::countByFlagger
 *                                    (per §B5/§D2: signing a dispute = bcc_trust_flags row)
 *   - counts.solids_given/received → PeepSoReactionRepository::countGivenByUser /
 *                                    countReceivedByUser, gated on §D5 reaction-id
 *                                    presence (returns 0 pre-seeder)
 *   - progression.next_rank_thresholds       → resolveProgression() derives
 *                                              from RankCatalog + trust_score
 *   - progression.trust_score_recent_changes → ReputationEventRepository::getRecentForUser
 *
 * Intentional V1 design (NOT a stub):
 *   - counts.binder_size = followers_count proxy. §C2 single-graph rule:
 *     binder IS the follow set; page-resolution lights every follow as a
 *     renderable card, so a "filter to BCC kinds" would remove valid
 *     binder entries. Field stays equal to following count by design.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Permissions\Permissions;
use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Core\Repositories\PeepSoFollowerRepository;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Repositories\FlagsRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\ReputationEventRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Support\PrivacySettings;
use BCC\Trust\Core\Support\RankCatalog;
use BCC\Trust\Core\Support\ReactionTypeRegistry;
use BCC\Trust\Core\Support\ReputationTierMap;
use BCC\Trust\Core\Support\WalletAddressValidator;
use BCC\Trust\Disputes\Repositories\DisputeParticipationRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class UserViewService
{
    private VoteRepository $voteRepo;
    private ReputationRepository $reputationRepo;
    private RankService $rankService;
    private FeatureAccessService $featureAccess;
    private FlagsRepository $flagsRepo;
    private LivingService $livingService;
    private ReputationEventRepository $reputationEventRepo;
    private PeepSoReactionRepository $reactionRepo;
    private DisputeParticipationRepository $participationRepo;

    /**
     * Per-request memoization of resolveAugmentedTrustScore — UserViewService
     * is request-scoped, so this cache lives one HTTP request and dies. Same
     * user requested twice in one request (e.g. a profile view that also
     * mounts a card for the same user) reads the second from memory.
     *
     * @var array<int, int>
     */
    private array $trustScoreCache = [];

    public function __construct(
        VoteRepository $voteRepo,
        ReputationRepository $reputationRepo,
        RankService $rankService,
        FeatureAccessService $featureAccess,
        FlagsRepository $flagsRepo,
        LivingService $livingService,
        ReputationEventRepository $reputationEventRepo,
        PeepSoReactionRepository $reactionRepo,
        DisputeParticipationRepository $participationRepo
    ) {
        $this->voteRepo            = $voteRepo;
        $this->reputationRepo      = $reputationRepo;
        $this->rankService         = $rankService;
        $this->featureAccess       = $featureAccess;
        $this->flagsRepo           = $flagsRepo;
        $this->livingService       = $livingService;
        $this->reputationEventRepo = $reputationEventRepo;
        $this->reactionRepo        = $reactionRepo;
        $this->participationRepo   = $participationRepo;
    }

    /**
     * Build the User view-model. Returns null when the user_id doesn't
     * resolve to a real wp_user.
     *
     * @return array<string, mixed>|null
     */
    public function getUser(int $userId, int $viewerId): ?array
    {
        $user = get_userdata($userId);
        if ($user === false) {
            return null;
        }

        $isSelf = $userId > 0 && $userId === $viewerId;

        $tier         = $this->reputationRepo->getTier($userId);
        $card         = ReputationTierMap::resolve($tier);
        $rank         = $this->resolveRank($userId);
        $handle       = self::resolveHandle($user);
        $followCounts = PeepSoFollowerRepository::getCounts($userId);
        $locals       = $this->resolveLocals($userId);
        $primaryLocal = $this->resolvePrimaryLocal($userId, $locals);

        $privacy = self::resolvePrivacy($userId);

        // §K2: when the viewer isn't the owner, apply each toggle to the
        // outgoing fields. Owner always sees full data so the settings
        // surface can show "you've hidden X" without lying to itself.
        $displayName  = $user->display_name !== '' ? $user->display_name : $user->user_login;
        $effectiveName = (!$isSelf && $privacy['real_name_hidden'])
            ? '@' . $handle
            : $displayName;

        $payload = [
            'id'                  => $userId,
            'handle'              => $handle,
            'display_name'        => $effectiveName,
            'avatar_url'             => self::resolveAvatar($userId),
            'cover_photo_url'        => self::resolveCoverPhotoUrl($userId),
            'cover_photo_position'   => self::resolveCoverPhotoPosition($userId),
            'joined_at'              => self::toIso8601((string) $user->user_registered),
            'is_self'             => $isSelf,
            'trust_score'         => $this->resolveAugmentedTrustScore($userId),
            'reputation_tier'     => $tier,
            'card_tier'           => $card['key'],
            'tier_label'          => $card['label'],
            'rank'                => $rank['key'],
            'rank_label'          => $rank['label'],
            'is_in_good_standing' => self::isInGoodStanding($tier),
            'flags'               => self::resolveFlags($userId),
            'bio'                 => self::resolveBio($user),
            'primary_local'       => $primaryLocal,
            'locals'              => $locals,
            'wallets'             => self::resolveWallets($userId, $isSelf),
            'counts'              => $this->resolveCounts($userId, $followCounts, $isSelf, $privacy),
            'privacy'             => $privacy,
            'permissions'         => self::resolvePermissions($userId, $viewerId, $isSelf),
            // §K1 Phase A — true when the viewer is currently blocking
            // this profile's owner. Drives the "Block"/"Unblock" copy
            // swap on the profile actions strip without a separate
            // /me/blocks fetch per profile render.
            'viewer_blocking'     => self::resolveViewerBlocking($userId, $viewerId, $isSelf),
            'links'               => self::buildLinks($handle),
        ];

        if ($isSelf) {
            // §O3 living header — composed by LivingService:
            //   - streak_days (peepso_activities walker)
            //   - today (reviews + disputes_signed; solids stub)
            //   - rank_progress_pct (trust_score / NEUTRAL_THRESHOLD)
            //   - comparison (V1 stub; §O3.1 percentile aggregator deferred)
            $payload['living']         = $this->livingService->compose($userId, $rank['key']);
            $payload['progression']    = $this->resolveProgression($rank, $userId);
            $payload['feature_access'] = $this->featureAccess->getFeatureAccess($userId);
            $payload['ux_helpers']     = self::resolveUxHelpers($userId);
        }

        return self::enforceWalletPrivacyAtEgress($payload, $isSelf);
    }

    /**
     * Fail-closed egress safety net for §3.1 wallet.address. The primary
     * defense is the `$isSelf` gate inside `resolveWallets()`. This filter
     * runs after the full payload is assembled and strips `address` from
     * any wallet entry when the viewer is not the profile owner — and
     * logs the violation as a P0 contract break so a future regression
     * is loud rather than silent.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function enforceWalletPrivacyAtEgress(array $payload, bool $isSelf): array
    {
        if ($isSelf) {
            return $payload;
        }
        if (!isset($payload['wallets']) || !is_array($payload['wallets'])) {
            return $payload;
        }

        $leaked    = false;
        $sanitized = [];
        foreach ($payload['wallets'] as $wallet) {
            if (is_array($wallet) && array_key_exists('address', $wallet)) {
                unset($wallet['address']);
                $leaked = true;
            }
            $sanitized[] = $wallet;
        }

        if ($leaked) {
            \BCC\Core\Log\Logger::error(
                '[UserViewService] CONTRACT VIOLATION: wallet.address leaked to non-self viewer; stripped at egress',
                [
                    'profile_user_id' => isset($payload['id']) ? (int) $payload['id'] : 0,
                ]
            );
        }

        $payload['wallets'] = $sanitized;
        return $payload;
    }

    /**
     * Slim view-model for the /members directory list. Drops the heavy
     * blocks `getUser` carries (counts, locals, wallets, permissions,
     * privacy, viewer_blocking, plus the self-only living/progression/
     * feature_access bundles) — those are profile-page concerns. List
     * surfaces want a card-sized read-only payload.
     *
     * Privacy: still honors `real_name_hidden` — if set and viewer
     * isn't self, returns `@handle` as display_name (same rule as
     * `getUser`). The directory shouldn't leak names the user opted
     * out of.
     *
     * Returns null when $userId doesn't resolve to a real wp_user.
     *
     * @return array{
     *   id: int,
     *   handle: string,
     *   display_name: string,
     *   avatar_url: string,
     *   joined_at: string,
     *   tier_label: string|null,
     *   rank_label: string,
     *   is_in_good_standing: bool,
     *   flags: list<string>
     * }|null
     */
    public function getSummary(int $userId, int $viewerId): ?array
    {
        $user = get_userdata($userId);
        if ($user === false) {
            return null;
        }

        $isSelf  = $userId > 0 && $userId === $viewerId;
        $tier    = $this->reputationRepo->getTier($userId);
        $card    = ReputationTierMap::resolve($tier);
        $rank    = $this->resolveRank($userId);
        $handle  = self::resolveHandle($user);
        $privacy = self::resolvePrivacy($userId);

        $displayName  = $user->display_name !== '' ? $user->display_name : $user->user_login;
        $effectiveName = (!$isSelf && $privacy['real_name_hidden'])
            ? '@' . $handle
            : $displayName;

        return [
            'id'                  => $userId,
            'handle'              => $handle,
            'display_name'        => $effectiveName,
            'avatar_url'          => self::resolveAvatar($userId),
            'joined_at'           => self::toIso8601((string) $user->user_registered),
            'tier_label'          => $card['label'],
            'rank_label'          => $rank['label'],
            'is_in_good_standing' => self::isInGoodStanding($tier),
            'flags'               => self::resolveFlags($userId),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Tier & rank
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array{key: string, label: string}
     */
    private function resolveRank(int $userId): array
    {
        $derived = $this->rankService->autoDerivedRank($userId);
        $label   = RankCatalog::getLabel($derived) ?? '';
        return ['key' => $derived, 'label' => $label];
    }

    private static function isInGoodStanding(string $tier): bool
    {
        // §E1: tier ≥ neutral. Tier-fail and moderation flags are now
        // surfaced through separate channels — `is_in_good_standing`
        // tracks tier alone; `flags` (resolveFlags) carries explicit
        // moderation slugs from Permissions + wp_usermeta.
        return in_array($tier, ['neutral', 'trusted', 'elite'], true);
    }

    /**
     * §D5 — augmented trust score for the user view-model.
     *
     * NOTE:
     * trust_score includes participation bonus (read-time only).
     * reputation_score in DB remains the base truth.
     * Do NOT use trust_score for persistence or tier calculations.
     * See docs/trust-engine-coverage.md "Known divergences" section.
     *
     * The bonus is the user's clamped lifetime participation contribution
     * (see DisputeParticipationRepository::getEarnedLifetimeTrust). The
     * final score is clamped to [0, 100] as a defensive bound — the
     * repo already caps the bonus, but a future weight tweak could
     * theoretically push past 100 if base + bonus drift; the clamp here
     * keeps PageScore semantics consistent without trusting upstream.
     *
     * Memoized per request — UserViewService is request-scoped and the
     * same user can be composed multiple times in one render (profile +
     * an embedded card for the same user). Cache lifetime = HTTP request.
     */
    private function resolveAugmentedTrustScore(int $userId): int
    {
        if (isset($this->trustScoreCache[$userId])) {
            return $this->trustScoreCache[$userId];
        }

        $base  = $this->reputationRepo->getScore($userId);
        $bonus = $this->participationRepo->getEarnedLifetimeTrust($userId);

        // Round once at the end — never round base and bonus separately.
        // Mid-pipeline rounding would create ±1 jitter that reads as bugs.
        $score = $base + $bonus;
        if ($score < 0.0)   { $score = 0.0; }
        if ($score > 100.0) { $score = 100.0; }

        return $this->trustScoreCache[$userId] = (int) round($score);
    }

    /**
     * V1 contract `flags: string[]` (per api-contract-v1.md §3 / line 625).
     *
     * Allowed slugs (exhaustive — frontend lights moderation chips ONLY
     * for these values): suspended, shadow_limited, hidden, under_review.
     *
     * Sources are LOCKED — do not introduce new flag codes or new data
     * sources without a contract amendment:
     *   - suspended      ← Permissions::is_not_suspended($userId, false) === false
     *   - shadow_limited ← wp_usermeta key 'bcc_shadow_limited' (boolean-truthy)
     *   - hidden         ← wp_usermeta key 'bcc_hidden' (boolean-truthy)
     *   - under_review   ← wp_usermeta key 'bcc_under_review' (boolean-truthy)
     *
     * "Boolean-truthy" uses FILTER_VALIDATE_BOOLEAN, NOT a `(bool)` cast —
     * WP serialises meta as strings, so the literal string "false" cast
     * to bool would be true, surfacing phantom flags. metaFlag() handles
     * the {1, true, yes, on} / {0, false, no, off, ""} convention.
     *
     * Per-request memoisation: resolveFlags is invoked from both
     * UserViewService (for the user view-model) AND CardViewService
     * (for member cards + page-owner flags on every card render). The
     * static $memo dedupes repeat lookups within a single request.
     * Stale-mid-request risk is acceptable for V1 — moderation actions
     * complete before the next request anyway.
     *
     * Public so CardViewService can route page-owner / member-card flags
     * through the same composer (§A4 — single source of trust logic).
     *
     * @return list<string>
     */
    public static function resolveFlags(int $userId): array
    {
        /** @var array<int, list<string>> $memo */
        static $memo = [];

        if ($userId <= 0) {
            return [];
        }
        if (isset($memo[$userId])) {
            return $memo[$userId];
        }

        // The second arg (allowAdminBypass=false) is intentional: a
        // suspended admin's `flags` MUST still surface the slug so the
        // moderation UI is consistent with the actual account state.
        $suspended = !Permissions::is_not_suspended($userId, false);

        $shadowLimited = self::metaFlag($userId, 'bcc_shadow_limited');
        $hidden        = self::metaFlag($userId, 'bcc_hidden');
        $underReview   = self::metaFlag($userId, 'bcc_under_review');

        return $memo[$userId] = self::composeFlagSlugs(
            $suspended,
            $shadowLimited,
            $hidden,
            $underReview
        );
    }

    /**
     * Boolean-truthy meta lookup. WP stores user_meta as strings, so
     * the literal "false" / "0" / "off" cast to bool would be true —
     * use filter_var with FILTER_VALIDATE_BOOLEAN instead.
     *
     * Returns true for {1, "1", true, "true", "yes", "on"} (case-insensitive).
     * Returns false for {0, "0", "", false, "false", "no", "off", null}.
     */
    private static function metaFlag(int $userId, string $key): bool
    {
        $raw = get_user_meta($userId, $key, true);
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Pure decision function: maps four boolean signals to the V1
     * flag-slug list, in deterministic order. Extracted for testability
     * — exercised directly by tests/UserViewServiceFlagsTest.php without
     * needing WordPress / Permissions / wp_usermeta in scope.
     *
     * Order is stable (suspended → shadow_limited → hidden → under_review)
     * so frontend rendering and snapshot tests don't churn on flag-set
     * changes. Each slug appears at most once by construction.
     *
     * @return list<string>
     */
    public static function composeFlagSlugs(
        bool $suspended,
        bool $shadowLimited,
        bool $hidden,
        bool $underReview
    ): array {
        $flags = [];
        if ($suspended)     { $flags[] = 'suspended'; }
        if ($shadowLimited) { $flags[] = 'shadow_limited'; }
        if ($hidden)        { $flags[] = 'hidden'; }
        if ($underReview)   { $flags[] = 'under_review'; }
        return $flags;
    }

    /**
     * Verified wallet links for the user, slim-projected for the
     * view-model. Unverified wallets are intentionally excluded —
     * the surface should only show wallets the user has proven
     * control of via signature.
     *
     * §3.1 wallet shape. Full `address` is OWN-PROFILE ONLY — for non-
     * self viewers we emit `address_short` only (the masked display
     * form). Without this gate, `GET /bcc/v1/users/:handle` would leak
     * every member's full wallet addresses to every authenticated
     * viewer.
     *
     * @return list<array{
     *   id: int,
     *   address?: string,
     *   address_short: string,
     *   chain_slug: string,
     *   chain_name: string,
     *   is_primary: bool,
     *   verified_at: string
     * }>
     */
    private static function resolveWallets(int $userId, bool $isSelf): array
    {
        if ($userId <= 0) {
            return [];
        }

        $rows = WalletRepository::getForUser($userId, null, true);
        $items = [];
        foreach ($rows as $row) {
            $address = (string) $row->wallet_address;
            $item = [
                'id'            => (int) $row->id,
                'address_short' => WalletAddressValidator::shorten($address),
                'chain_slug'    => (string) $row->chain_slug,
                'chain_name'    => (string) $row->chain_name,
                'is_primary'    => ((int) $row->is_primary) === 1,
                'verified_at'   => self::toIso8601((string) ($row->verified_at ?? '')),
            ];
            if ($isSelf) {
                $item['address'] = $address;
            }
            $items[] = $item;
        }
        return $items;
    }

    // ──────────────────────────────────────────────────────────────────
    // Identity
    // ──────────────────────────────────────────────────────────────────

    private static function resolveHandle(\WP_User $user): string
    {
        $handle = (string) get_user_meta($user->ID, 'bcc_handle', true);
        if ($handle !== '') {
            return $handle;
        }
        return $user->user_login; // fallback for users without a bcc_handle yet
    }

    private static function resolveAvatar(int $userId): string
    {
        // get_avatar_url returns absolute URL (per WP) — matches §1.7
        // "Asset / media URLs are absolute" rule.
        $url = get_avatar_url($userId);
        return is_string($url) ? $url : '';
    }

    private static function resolveBio(\WP_User $user): string
    {
        return (string) ($user->description ?? '');
    }

    /**
     * §3.1 cover_photo_url — reads PeepSo's stored cover hash from
     * user_meta and returns the absolute URL. Returns null when no
     * custom cover is set (frontend falls back to a default treatment;
     * spec §1.7 — absolute URLs for media).
     *
     * Wraps PeepSoUser::get_cover() when PeepSo is loaded; falls back
     * to direct user_meta read if PeepSo is missing (defense — bio +
     * the rest of the profile still work without PeepSo, only the
     * cover photo URL is unavailable).
     */
    private static function resolveCoverPhotoUrl(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        if (!class_exists('\\PeepSoUser')) {
            return null;
        }
        $instance = \PeepSoUser::get_instance($userId);
        if (!$instance->has_cover()) {
            return null;
        }
        $url = $instance->get_cover();
        return $url !== '' ? $url : null;
    }

    /**
     * §3.1 cover_photo_position — `{x, y}` percentages (0–100) for
     * the cover photo crop position. Stored in PeepSo's
     * `peepso_cover_position_x` / `peepso_cover_position_y` user_meta;
     * defaults to center (50, 50) when no row exists.
     *
     * @return array{x: int, y: int}
     */
    private static function resolveCoverPhotoPosition(int $userId): array
    {
        if ($userId <= 0) {
            return ['x' => 50, 'y' => 50];
        }
        $x = get_user_meta($userId, 'peepso_cover_position_x', true);
        $y = get_user_meta($userId, 'peepso_cover_position_y', true);
        return [
            'x' => is_numeric($x) ? max(0, min(100, (int) $x)) : 50,
            'y' => is_numeric($y) ? max(0, min(100, (int) $y)) : 50,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Locals
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return list<array{id: int, slug: string, name: string, number: int|null, is_primary: bool}>
     */
    private function resolveLocals(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        // §C2 single-graph rule: membership comes from PeepSo. We don't
        // have a "list user's groups" repository method that returns
        // everything (only findUserMemberships which expects group_ids).
        // For the User view-model, we need every group the user is in,
        // not just a filtered set. So: query membership table directly
        // for the user, then bulk-resolve group display info.
        $myGroupIds = $this->collectUserGroupIds($userId);
        if ($myGroupIds === []) {
            return [];
        }

        $primaryGroupId = self::loadPrimaryGroupId($userId);
        $groupInfo      = PeepSoGroupRepository::findManyByIds($myGroupIds);

        $items = [];
        foreach ($myGroupIds as $groupId) {
            $info = $groupInfo[$groupId] ?? null;
            if ($info === null) {
                continue;
            }
            $items[] = [
                'id'         => $groupId,
                'slug'       => $info->post_name,
                'name'       => $info->post_title,
                'number'     => self::parseLocalNumber($info->post_title),
                'is_primary' => $primaryGroupId === $groupId,
            ];
        }
        return $items;
    }

    /**
     * @return list<int>
     */
    private function collectUserGroupIds(int $userId): array
    {
        // Reuse findUserMemberships with a wide group_id filter — the
        // simplest path. We pre-fetch the user's groups via WP's posts
        // join through peepso_group_members. To avoid a second helper
        // method on the bcc-core repo, we ask findUserMemberships for
        // a sentinel-wide range; if the user has 0 groups, we get 0
        // rows back. This intentionally stays inside the existing
        // repo surface.
        //
        // NOTE: if user belongs to > 200 groups, this truncates (per
        // findUserMemberships LIMIT 200). For V1 that's well above the
        // expected per-user Local count (a user is realistically in
        // 1-3 Locals).
        global $wpdb;
        $members = $wpdb->prefix . 'peepso_group_members';

        $sql = $wpdb->prepare(
            "SELECT DISTINCT gm_group_id AS group_id
               FROM {$members}
              WHERE gm_user_id = %d
                AND gm_user_status LIKE %s
              LIMIT 200",
            $userId,
            'member%'
        );

        /** @var list<object{group_id: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($sql);

        $ids = [];
        foreach ($rows ?: [] as $row) {
            $ids[] = (int) $row->group_id;
        }
        return $ids;
    }

    /**
     * @param list<array{id: int, slug: string, name: string, number: int|null, is_primary: bool}> $locals
     * @return array{id: int, slug: string, name: string, number: int|null}|null
     */
    private function resolvePrimaryLocal(int $userId, array $locals): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        foreach ($locals as $local) {
            if ($local['is_primary']) {
                return [
                    'id'     => $local['id'],
                    'slug'   => $local['slug'],
                    'name'   => $local['name'],
                    'number' => $local['number'],
                ];
            }
        }
        return null;
    }

    private static function loadPrimaryGroupId(int $userId): ?int
    {
        $value = get_user_meta($userId, 'bcc_primary_local_group_id', true);
        if (!is_numeric($value)) {
            return null;
        }
        $intVal = (int) $value;
        return $intVal > 0 ? $intVal : null;
    }

    private static function parseLocalNumber(string $title): ?int
    {
        if (preg_match('/^Local\s+(\d+)\b/u', $title, $matches) === 1) {
            return (int) $matches[1];
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────
    // Counts
    // ──────────────────────────────────────────────────────────────────

    /**
     * §K2: when the viewer isn't the owner, hidden categories collapse
     * to 0 in the publicly-visible counts. Owner always sees the truth
     * so the settings UI can surface "you've hidden X · Y items affected"
     * if we ever need it. Counts kept as ints (vs nullable) to preserve
     * the §3.1 contract shape — the privacy block is the signal.
     *
     * @param array{following: int, followers: int} $followCounts
     * @param array{
     *   binder_hidden: bool,
     *   reviews_hidden: bool,
     *   disputes_hidden: bool,
     *   delegations_hidden: bool,
     *   follower_count_hidden: bool,
     *   real_name_hidden: bool,
     *   email_hidden: bool
     * } $privacy
     * @return array{
     *   followers: int,
     *   following: int,
     *   binder_size: int,
     *   reviews_written: int,
     *   disputes_signed: int,
     *   solids_given: int,
     *   solids_received: int
     * }
     */
    private function resolveCounts(int $userId, array $followCounts, bool $isSelf, array $privacy): array
    {
        $reviewsWritten = (int) $this->voteRepo->countByVoter($userId);
        // disputes_signed: per §B5 / §D2, signing onto a dispute is
        // recorded in bcc_trust_flags (flagger_user_id). One row
        // per (user, vote) pair, so this is the count of votes the
        // user has flagged.
        $disputesSigned = $this->flagsRepo->countByFlagger($userId);

        // §C2 single-graph rule: binder IS the follow set; binder_size
        // equals the following count by design. Do not "filter to BCC
        // card kinds" — page-resolution makes every follow renderable
        // as a card, so any filter would remove valid binder entries.
        $binderSize = $followCounts['following'];

        if (!$isSelf) {
            if ($privacy['follower_count_hidden']) {
                $followCounts['followers'] = 0;
            }
            if ($privacy['binder_hidden']) {
                $binderSize = 0;
                $followCounts['following'] = 0;
            }
            if ($privacy['reviews_hidden']) {
                $reviewsWritten = 0;
            }
            if ($privacy['disputes_hidden']) {
                $disputesSigned = 0;
            }
        }

        return [
            'followers'       => $followCounts['followers'],
            'following'       => $followCounts['following'],
            'binder_size'     => $binderSize,
            'reviews_written' => $reviewsWritten,
            'disputes_signed' => $disputesSigned,
            'solids_given'    => $this->countSolidsGiven($userId),
            'solids_received' => $this->countSolidsReceived($userId),
        ];
    }

    /**
     * Lifetime count of Solid reactions the user has placed.
     * Returns 0 when the §D5 reactions haven't been seeded
     * (idFor returns null) — same contract as before the seeder
     * shipped, just data-driven now.
     */
    private function countSolidsGiven(int $userId): int
    {
        $solidId = ReactionTypeRegistry::solidId();
        if ($solidId === null) {
            return 0;
        }
        return $this->reactionRepo->countGivenByUser($userId, $solidId);
    }

    /**
     * Lifetime count of Solid reactions the user's content has received.
     */
    private function countSolidsReceived(int $userId): int
    {
        $solidId = ReactionTypeRegistry::solidId();
        if ($solidId === null) {
            return 0;
        }
        return $this->reactionRepo->countReceivedByUser($userId, $solidId);
    }

    // ──────────────────────────────────────────────────────────────────
    // Privacy, permissions, ux helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * §K2 privacy block — read from `wp_usermeta.bcc_privacy_*` via
     * PrivacySettings. Always returns a complete array; missing meta
     * defaults to false (V1 baseline: everything public per §K2).
     *
     * @return array{
     *   binder_hidden: bool,
     *   reviews_hidden: bool,
     *   disputes_hidden: bool,
     *   delegations_hidden: bool,
     *   follower_count_hidden: bool,
     *   real_name_hidden: bool,
     *   email_hidden: bool
     * }
     */
    private static function resolvePrivacy(int $userId): array
    {
        return PrivacySettings::readProfile($userId);
    }

    /**
     * Permissions for what the *viewer* can do TO this user — distinct
     * from §O5 feature gates (those live in feature_access). For V1:
     *
     *   can_follow      — true for any other authed viewer
     *   can_message     — false (V1 has no DM system)
     *   can_block       — true for any other authed viewer
     *   can_edit_profile — true only on own profile
     *
     * @return array<string, array{allowed: bool, unlock_hint: string|null}>
     */
    private static function resolvePermissions(int $userId, int $viewerId, bool $isSelf): array
    {
        $isAuthed   = $viewerId > 0;
        $isOther    = $isAuthed && !$isSelf;

        return [
            'can_follow'       => ['allowed' => $isOther, 'unlock_hint' => null],
            'can_message'      => ['allowed' => false,    'unlock_hint' => null],
            'can_block'        => ['allowed' => $isOther, 'unlock_hint' => null],
            'can_edit_profile' => ['allowed' => $isSelf,  'unlock_hint' => null],
        ];
    }

    /**
     * §K1 Phase A — viewer-relative "is this viewer blocking the profile
     * owner?" flag. Anonymous viewers and self-views are always false.
     */
    private static function resolveViewerBlocking(int $userId, int $viewerId, bool $isSelf): bool
    {
        if ($isSelf || $viewerId <= 0 || $userId <= 0) {
            return false;
        }
        return PeepSoBlockRepository::isBlocking($viewerId, $userId);
    }

    /**
     * @return array{show_helpers: bool}
     */
    private static function resolveUxHelpers(int $userId): array
    {
        $familiar = (bool) get_user_meta($userId, 'bcc_ui_familiar', true);
        return ['show_helpers' => !$familiar];
    }

    // ──────────────────────────────────────────────────────────────────
    // Progression (own only)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Phase 1 progression block. Real progression thresholds depend on
     * a ranks-threshold service that hasn't shipped — for now we
     * surface current rank + next rank from the catalog with empty
     * threshold/event arrays.
     *
     * @param array{key: string, label: string} $rank
     * @return array{
     *   current_rank: string,
     *   current_rank_label: string,
     *   next_rank: string|null,
     *   next_rank_label: string|null,
     *   next_rank_thresholds: list<array{metric: string, label: string, current: int, required: int}>,
     *   trust_score_recent_changes: list<array{delta: int, reason: string, at: string}>
     * }
     */
    private function resolveProgression(array $rank, int $userId): array
    {
        // Auto-derived ranks only have one promotion path (Apprentice
        // → Journeyman per §E2). Foreman+ are admin-conferred and
        // therefore have no auto next_rank.
        $next       = null;
        $thresholds = [];

        if ($rank['key'] === RankCatalog::RANK_APPRENTICE) {
            $next = RankCatalog::RANK_JOURNEYMAN;
            // V1 promotion rule (per RankService::autoDerivedRank):
            //   Apprentice → Journeyman = reach reputation_tier neutral
            //   = trust_score >= BCC_TRUST_NEUTRAL_SCORE (50).
            //
            // The §N11 example shape used `reviews_written` / `days_active`
            // — those are aspirational thresholds for a future activity-
            // based promotion model. V1 ships what V1 actually checks:
            // a single trust_score threshold. When activity-based
            // promotion lands (post-V1), thresholds[] grows additional
            // entries; the contract field stays the same.
            $score = (int) round($this->reputationRepo->getScore($userId));
            $thresholds[] = [
                'metric'   => 'trust_score',
                'label'    => 'Trust score',
                'current'  => $score,
                'required' => (int) BCC_TRUST_NEUTRAL_SCORE,
            ];
        }
        // Journeyman+ has no auto-promotion target; thresholds stays [].

        return [
            'current_rank'               => $rank['key'],
            'current_rank_label'         => $rank['label'],
            'next_rank'                  => $next,
            'next_rank_label'            => $next !== null ? RankCatalog::getLabel($next) : null,
            'next_rank_thresholds'       => $thresholds,
            'trust_score_recent_changes' => $this->resolveTrustScoreRecentChanges($userId),
        ];
    }

    /**
     * Last few reputation-change events for a user, shaped per §N11.
     * Sourced from bcc_reputation_events written by
     * ReputationCalculatorService::recalculateUserReputation when the
     * recalc actually shifted the score (above EVENT_NOISE_FLOOR).
     *
     * @return list<array{delta: int, reason: string, at: string}>
     */
    private function resolveTrustScoreRecentChanges(int $userId): array
    {
        $rows = $this->reputationEventRepo->getRecentForUser($userId, 5);
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                // Round to int — the contract surfaces score points,
                // not sub-point precision. Internal float deltas are
                // preserved in the row for audit.
                'delta'  => (int) round((float) $row->delta),
                'reason' => (string) $row->reason,
                'at'     => self::toIso8601((string) $row->created_at),
            ];
        }
        return $items;
    }

    // ──────────────────────────────────────────────────────────────────
    // Misc
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private static function buildLinks(string $handle): array
    {
        $base = '/u/' . $handle;
        return [
            'self'     => $base,
            'binder'   => $base . '/binder',
            'reviews'  => $base . '/reviews',
            'activity' => $base . '/activity',
            'disputes' => $base . '/disputes',
            'network'  => $base . '/network',
            'blog'     => $base . '/blog',
        ];
    }

    private static function toIso8601(string $mysqlDatetime): string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
