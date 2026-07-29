<?php

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\VoteRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single canonical resolver for the feature_access view-model (§O5 + §O5.1)
 * and the per-action permissions block (§N7). Replaces ad-hoc gate logic
 * scattered across EndorsementService, VoteEligibilityChecker,
 * QuestProgressService, and DisputeController.
 *
 * The contract (§2.1, §2.6) requires that every gate the viewer might
 * eventually unlock is rendered, even when allowed=false, with a plain-
 * English unlock_hint. This service is the single producer of that pair.
 *
 * Permission stacking (§O5+D2): when an O5 level gate AND a D2
 * reputation/wallet gate both apply to a feature, this service resolves
 * all gates and emits a single allowed boolean plus an unlock_hint
 * describing whichever sub-gate is closer to resolution (level → tier →
 * wallet, by typical unlock distance).
 *
 * Threshold semantics (§O5):
 *   Level 1 (New)     — default for all signups
 *   Level 2 (Active)  — requires 5+ pulls
 *   Level 3 (Veteran) — requires Level-2 thresholds AND 3+ reviews AND an
 *                       account at least 30 days old
 *
 * Promotion is cumulative: Level 3 implies Level 2's thresholds also met.
 *
 * Thresholds are admin-tunable via `wp_options('bcc_level_thresholds')`
 * with safe defaults below — call sites NEVER inline thresholds.
 *
 * Sources for the counters — READ THESE BEFORE TREATING THE GATE AS MERIT.
 * Every one is self-dealt; none requires sustained or corroborated
 * participation, and none can be failed by behaving badly:
 *
 *   - pulls            ← peepso_user_followers, the viewer's OWN following
 *                        count (watchlist is the UI projection per §C2).
 *                        Entirely self-controlled — following 5 accounts
 *                        costs nothing and involves no other party.
 *   - reviews_written  ← bcc_trust_votes (VoteRepository::countByVoter).
 *                        Counts VOTE ROWS, not written review prose. The
 *                        name is historical; if a review CPT ships, update
 *                        countReviewsWritten() and rename this key too.
 *   - account_age_days ← time since wp_users.user_registered. This is
 *                        ACCOUNT AGE, not active days: it accrues while the
 *                        user is dormant and can never decrease. Named
 *                        `days_active` until contract v1.58, which was a
 *                        lie the progress UI repeated back to users.
 *
 * Consequence, stated plainly so nobody re-derives it: a user who follows
 * 5 accounts and casts 3 votes on day one is promoted to level 3 on day 30
 * without logging in again. Level 3 gates open_dispute, see_signal_details,
 * see_trust_breakdown and feed_tab_signals — so this ladder is currently a
 * tenure check standing in front of adjudication powers. Strengthening it
 * needs outcome data (see the reliability engine); do not paper over it by
 * nudging these numbers.
 *
 * Note (2026-05-14): `floor_visits` was removed from the LEVEL_ACTIVE
 * gate. Visiting the Floor is passive consumption — it isn't a signal
 * we want to use as a permission gate. Pulls (a real choice the user
 * made) is the only LEVEL_ACTIVE requirement now.
 *
 * Per-user override (§O5 admin escape hatch):
 *   Setting `bcc_feature_override_{feature_key}` = "1" on a user_meta row
 *   bypasses the level/tier/wallet gate for that ONE feature. Used to
 *   hand-grant access (e.g. trusted operator who hasn't earned the
 *   organic gate yet, or staff for testing). Set via wp-cli:
 *
 *     wp user meta add <user_id> bcc_feature_override_write_review 1
 *
 *   The override surface is per-feature + per-user so a single granted
 *   permission can't accidentally widen to "ignore all gates" — every
 *   feature requires its own explicit meta row.
 */
final class FeatureAccessService
{
    public const LEVEL_NEW     = 1;
    public const LEVEL_ACTIVE  = 2;
    public const LEVEL_VETERAN = 3;

    /**
     * Default thresholds per §O5. Each level's requirements are AND-ed
     * (must satisfy all). LEVEL_VETERAN reqs are layered on top of
     * LEVEL_ACTIVE reqs (cumulative).
     *
     * Callers should never read this constant directly — go through
     * getLevelThresholds() so admin overrides apply.
     *
     * @var array<int, array{label: string, pulls?: int, reviews_written?: int, account_age_days?: int}>
     */
    private const DEFAULT_THRESHOLDS = [
        self::LEVEL_NEW => [
            'label' => 'New',
        ],
        self::LEVEL_ACTIVE => [
            'label' => 'Active',
            'pulls' => 5,
        ],
        self::LEVEL_VETERAN => [
            'label'           => 'Veteran',
            'reviews_written' => 3,
            'account_age_days'     => 30,
        ],
    ];

    /**
     * Per §2.6, these are the canonical feature keys. Each maps to its
     * minimum level + any §D2 reputation/wallet stacking. Anything not
     * level-gated (e.g. anonymous reads) does not appear here.
     *
     * @var array<string, array{min_level: int, requires_wallet?: bool, requires_min_tier?: string}>
     */
    private const FEATURE_REQUIREMENTS = [
        'write_review'          => ['min_level' => self::LEVEL_ACTIVE,  'requires_min_tier' => 'neutral'],   // §D2: rep ≥ neutral
        'vouch_reaction'        => ['min_level' => self::LEVEL_ACTIVE],
        'open_dispute'          => ['min_level' => self::LEVEL_VETERAN, 'requires_wallet' => true,  'requires_min_tier' => 'neutral'], // §D2
        'see_signal_details'    => ['min_level' => self::LEVEL_VETERAN],
        'see_trust_breakdown'   => ['min_level' => self::LEVEL_VETERAN],
        'feed_tab_signals'      => ['min_level' => self::LEVEL_VETERAN],
    ];

    /**
     * Reputation tier ordering — used by tierAtLeast() to evaluate the
     * §D2 minimum-tier gates without per-call lookups.
     *
     * @var array<string, int>
     */
    private const TIER_RANK = [
        'risky'   => 0,
        'caution' => 1,
        'neutral' => 2,
        'trusted' => 3,
        'elite'   => 4,
    ];

    private VoteRepository $voteRepo;
    private ReputationRepository $reputationRepo;

    public function __construct(
        VoteRepository $voteRepo,
        ReputationRepository $reputationRepo
    ) {
        $this->voteRepo       = $voteRepo;
        $this->reputationRepo = $reputationRepo;
    }

    /**
     * Full feature_access block per §2.6. Used on the viewer's own User
     * view-model. Anonymous viewers should not call this — pass a real
     * user_id or use canPerform() with explicit feature keys.
     *
     * @return array{
     *   level: int,
     *   level_label: string,
     *   next_level: ?int,
     *   next_level_label: ?string,
     *   next_level_thresholds: list<array{metric: string, label: string, current: int, required: int}>,
     *   features: array<string, array{allowed: bool, unlock_hint: ?string}>
     * }
     */
    public function getFeatureAccess(int $userId): array
    {
        $stats = $this->getUserStats($userId);
        $level = $this->resolveLevel($stats);

        $thresholds = $this->getLevelThresholds();
        $nextLevel  = $level < self::LEVEL_VETERAN ? $level + 1 : null;
        $nextLabel  = $nextLevel !== null ? ($thresholds[$nextLevel]['label'] ?? null) : null;

        $features = [];
        foreach (array_keys(self::FEATURE_REQUIREMENTS) as $feature) {
            // Per-user override short-circuits the gate. Documented at
            // the class level; intentionally per-feature so a single
            // grant can't widen accidentally.
            if (self::hasFeatureOverride($userId, $feature)) {
                $features[$feature] = ['allowed' => true, 'unlock_hint' => null];
                continue;
            }
            $features[$feature] = $this->resolveFeature($feature, $level, $stats);
        }

        return [
            'level'                 => $level,
            'level_label'           => $thresholds[$level]['label'] ?? '',
            'next_level'            => $nextLevel,
            'next_level_label'      => $nextLabel,
            'next_level_thresholds' => $this->renderNextLevelThresholds($level, $stats),
            'features'              => $features,
        ];
    }

    /**
     * Single-feature check. Used by per-action permission resolvers on
     * Card and FeedItem view-models. Returns the same {allowed,
     * unlock_hint} shape as feature_access.features.<key>.
     *
     * @return array{allowed: bool, unlock_hint: ?string}
     */
    public function canPerform(int $userId, string $featureKey): array
    {
        // Per-request memoisation: card-list hydration asks the same
        // (viewer, action) pair once per card (e.g. 24× write_review on
        // a directory page). The answer is constant
        // within a request, so dedupe repeat lookups — same precedent
        // as UserViewService::resolveFlags' static memo. No reset
        // needed: mutations that would change the answer complete
        // before the next request.
        /** @var array<string, array{allowed: bool, unlock_hint: ?string}> $memo */
        static $memo = [];

        $memoKey = $userId . ':' . $featureKey;
        if (isset($memo[$memoKey])) {
            return $memo[$memoKey];
        }

        if (!isset(self::FEATURE_REQUIREMENTS[$featureKey])) {
            // Unknown features fail closed — never accidentally grant.
            return $memo[$memoKey] = ['allowed' => false, 'unlock_hint' => null];
        }

        if ($userId <= 0) {
            return $memo[$memoKey] = ['allowed' => false, 'unlock_hint' => null];
        }

        // Per-user override — see class-level doc. Checked before the
        // (more expensive) stats + level computation. The override only
        // bypasses the level/tier/wallet gate; unknown features still
        // fail closed above so an override on a nonexistent feature
        // never grants anything.
        if (self::hasFeatureOverride($userId, $featureKey)) {
            return $memo[$memoKey] = ['allowed' => true, 'unlock_hint' => null];
        }

        $stats = $this->getUserStats($userId);
        $level = $this->resolveLevel($stats);
        return $memo[$memoKey] = $this->resolveFeature($featureKey, $level, $stats);
    }

    /**
     * Read the per-user override meta for one feature. "1" / true / 1
     * are all treated as bypass; anything else (including missing) is
     * the no-op default. Filter `bcc_feature_override_value` lets
     * callers transform the raw meta value before truthiness — useful
     * if a future admin UI stores e.g. JSON instead of a flat bool.
     */
    private static function hasFeatureOverride(int $userId, string $featureKey): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $raw = get_user_meta($userId, 'bcc_feature_override_' . $featureKey, true);
        /** @var mixed $filtered */
        $filtered = apply_filters('bcc_feature_override_value', $raw, $userId, $featureKey);
        return filter_var($filtered, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The integer level (1/2/3) for a user, public for surfaces that show
     * the level chip without needing the full feature_access payload.
     */
    public function getLevel(int $userId): int
    {
        if ($userId <= 0) {
            return self::LEVEL_NEW;
        }
        return $this->resolveLevel($this->getUserStats($userId));
    }

    /**
     * Batched level resolver — the integer level (1/2/3) for many users
     * in a bounded set of queries. For hot list surfaces (feed/comment
     * author chips via AuthorBadgeResolver) that need rank-from-level
     * without N× full getFeatureAccess() computations.
     *
     * Reuses the same count sources as the per-user path, batched:
     *   pulls   ← PeepSoFollowerRepository::getFollowingCountForUsers
     *   reviews ← VoteRepository::countByVoters
     *   days    ← user_registered (WP user cache primed once)
     *
     * Wallet + reputation-tier stats are intentionally omitted — they
     * gate individual features, not the level (resolveLevel reads only
     * pulls/reviews/days). Users absent from a count map default to 0.
     *
     * @param list<int> $userIds Bounded by caller (page-size capped).
     * @return array<int, int> user_id → level (1|2|3)
     */
    public function getLevelsForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $clean = [];
        foreach ($userIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $clean[$intId] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $ids = array_keys($clean);

        $pulls   = $this->countPullsForUsers($ids);
        $reviews = $this->voteRepo->countByVoters($ids);

        // Prime the WP user cache once so each per-id account_age_days read
        // below is a cache hit rather than a separate query.
        if (function_exists('cache_users')) {
            cache_users($ids);
        }

        $out = [];
        foreach ($ids as $uid) {
            $out[$uid] = $this->resolveLevel([
                'pulls'           => (int) ($pulls[$uid] ?? 0),
                'reviews_written' => (int) ($reviews[$uid] ?? 0),
                'account_age_days'     => $this->countAccountAgeDays($uid),
                'has_wallet'      => false,     // unused by resolveLevel
                'reputation_tier' => 'neutral', // unused by resolveLevel
            ]);
        }
        return $out;
    }

    /**
     * Batched pulls (= follows per §C2) for a set of users via the
     * bcc-core batch sibling. Falls back to the per-user path only when
     * bcc-core isn't loaded at all (same defensive class_exists posture
     * as countPulls — both plugins ship together, so the method is
     * guaranteed present whenever the class is).
     *
     * @param list<int> $userIds
     * @return array<int, int> user_id → following count
     */
    private function countPullsForUsers(array $userIds): array
    {
        if (class_exists('\\BCC\\Core\\Repositories\\PeepSoFollowerRepository')) {
            return \BCC\Core\Repositories\PeepSoFollowerRepository::getFollowingCountForUsers($userIds);
        }

        $out = [];
        foreach ($userIds as $uid) {
            $out[(int) $uid] = $this->countPulls((int) $uid);
        }
        return $out;
    }

    /**
     * Admin-tunable thresholds. Reads wp_options('bcc_level_thresholds')
     * and merges over the defaults so partial overrides are safe.
     *
     * @return array<int, array{label: string, pulls?: int, reviews_written?: int, account_age_days?: int}>
     */
    public function getLevelThresholds(): array
    {
        $stored = get_option('bcc_level_thresholds', []);
        if (!is_array($stored) || $stored === []) {
            return self::DEFAULT_THRESHOLDS;
        }

        // Build the typed result explicitly per-field so PHPStan can verify
        // each shape entry. array_merge would widen each array element to
        // `mixed` and break the declared return type.
        $result = [];
        foreach (self::DEFAULT_THRESHOLDS as $lvl => $base) {
            $override = $stored[$lvl] ?? null;
            if (!is_array($override)) {
                $result[$lvl] = $base;
                continue;
            }

            $entry = $base;
            if (isset($override['label']) && is_string($override['label'])) {
                $entry['label'] = $override['label'];
            }
            if (isset($override['pulls']) && is_int($override['pulls'])) {
                $entry['pulls'] = $override['pulls'];
            }
            if (isset($override['reviews_written']) && is_int($override['reviews_written'])) {
                $entry['reviews_written'] = $override['reviews_written'];
            }
            if (isset($override['account_age_days']) && is_int($override['account_age_days'])) {
                $entry['account_age_days'] = $override['account_age_days'];
            }
            $result[$lvl] = $entry;
        }
        return $result;
    }

    /**
     * @return array{pulls: int, reviews_written: int, account_age_days: int, has_wallet: bool, reputation_tier: string}
     */
    private function getUserStats(int $userId): array
    {
        return [
            'pulls'           => $this->countPulls($userId),
            'reviews_written' => $this->countReviewsWritten($userId),
            'account_age_days'     => $this->countAccountAgeDays($userId),
            'has_wallet'      => $this->hasVerifiedWallet($userId),
            'reputation_tier' => $this->getReputationTier($userId),
        ];
    }

    /**
     * Cumulative level resolver. Level 3 requires Level-2 thresholds also
     * met (a user with 100 reviews but 0 pulls is NOT Level 3).
     *
     * @param array{pulls: int, reviews_written: int, account_age_days: int, has_wallet: bool, reputation_tier: string} $stats
     */
    private function resolveLevel(array $stats): int
    {
        $thresholds = $this->getLevelThresholds();
        $activeReq  = $thresholds[self::LEVEL_ACTIVE]  ?? [];
        $veteranReq = $thresholds[self::LEVEL_VETERAN] ?? [];

        $atActive = $stats['pulls'] >= (int) ($activeReq['pulls'] ?? 0);

        $atVeteran = $atActive
                  && $stats['reviews_written'] >= (int) ($veteranReq['reviews_written'] ?? 0)
                  && $stats['account_age_days']     >= (int) ($veteranReq['account_age_days']     ?? 0);

        if ($atVeteran) {
            return self::LEVEL_VETERAN;
        }
        if ($atActive) {
            return self::LEVEL_ACTIVE;
        }
        return self::LEVEL_NEW;
    }

    /**
     * Resolve a single feature gate, applying §O5 + §D2 stacking. Returns
     * allowed=true only when ALL gates (level, wallet if needed, tier if
     * needed) pass. The unlock_hint describes whichever sub-gate is
     * closer to resolution.
     *
     * @param array{pulls: int, reviews_written: int, account_age_days: int, has_wallet: bool, reputation_tier: string} $stats
     * @return array{allowed: bool, unlock_hint: ?string}
     */
    private function resolveFeature(string $featureKey, int $currentLevel, array $stats): array
    {
        $req          = self::FEATURE_REQUIREMENTS[$featureKey];
        $minLevel     = $req['min_level'];
        $needsWallet  = $req['requires_wallet']   ?? false;
        $needsMinTier = $req['requires_min_tier'] ?? null;

        $levelOk  = $currentLevel >= $minLevel;
        $walletOk = !$needsWallet || $stats['has_wallet'];
        $tierOk   = $needsMinTier === null
                 || self::tierAtLeast($stats['reputation_tier'], $needsMinTier);

        if ($levelOk && $walletOk && $tierOk) {
            return ['allowed' => true, 'unlock_hint' => null];
        }

        return [
            'allowed'     => false,
            'unlock_hint' => $this->composeUnlockHint(
                $minLevel,
                $currentLevel,
                $needsWallet,
                $needsMinTier,
                $stats
            ),
        ];
    }

    /**
     * Pick the hint that describes the most-actionable next step.
     * Priority: level (typically the closer gate) → tier → wallet.
     *
     * @param array{pulls: int, reviews_written: int, account_age_days: int, has_wallet: bool, reputation_tier: string} $stats
     */
    private function composeUnlockHint(
        int $minLevel,
        int $currentLevel,
        bool $needsWallet,
        ?string $needsMinTier,
        array $stats
    ): ?string {
        if ($currentLevel < $minLevel) {
            $thresholds = $this->getLevelThresholds();
            $target     = $thresholds[$minLevel] ?? [];

            if ($minLevel === self::LEVEL_ACTIVE) {
                return sprintf(
                    'Keep tabs on %d cards to unlock this.',
                    (int) ($target['pulls'] ?? 0)
                );
            }

            if ($minLevel === self::LEVEL_VETERAN) {
                // "stay active N days" was false — the gate is account age
                // (see countAccountAgeDays), which accrues without the user
                // doing anything. Say what is actually required.
                return sprintf(
                    'Write %d reviews. Unlocks once your account is %d days old.',
                    (int) ($target['reviews_written'] ?? 0),
                    (int) ($target['account_age_days'] ?? 0)
                );
            }
        }

        if ($needsMinTier !== null
            && !self::tierAtLeast($stats['reputation_tier'], $needsMinTier)
        ) {
            return 'Earn neutral reputation or better to unlock this.';
        }

        if ($needsWallet && !$stats['has_wallet']) {
            return 'Link a wallet to unlock this.';
        }

        return null;
    }

    /**
     * Render the metrics that gate the NEXT level (not cumulative). For a
     * Level-1 viewer, shows the pulls requirement; for a
     * Level-2 viewer, shows the reviews/account_age_days requirements.
     *
     * @param array{pulls: int, reviews_written: int, account_age_days: int, has_wallet: bool, reputation_tier: string} $stats
     * @return list<array{metric: string, label: string, current: int, required: int}>
     */
    private function renderNextLevelThresholds(int $currentLevel, array $stats): array
    {
        $thresholds = $this->getLevelThresholds();

        if ($currentLevel === self::LEVEL_NEW) {
            $req = $thresholds[self::LEVEL_ACTIVE] ?? [];
            return [
                [
                    'metric'   => 'pulls',
                    'label'    => 'Pulls',
                    'current'  => (int) $stats['pulls'],
                    'required' => (int) ($req['pulls'] ?? 0),
                ],
            ];
        }

        if ($currentLevel === self::LEVEL_ACTIVE) {
            $req = $thresholds[self::LEVEL_VETERAN] ?? [];
            return [
                [
                    'metric'   => 'reviews_written',
                    'label'    => 'Reviews',
                    'current'  => (int) $stats['reviews_written'],
                    'required' => (int) ($req['reviews_written'] ?? 0),
                ],
                [
                    // Label feeds two surfaces: a metric row (current/required)
                    // and LivingService's "%d more %s" sentence. "Days active"
                    // was false — nothing here requires activity — and "Days
                    // since joining" reads badly as "24 more days since
                    // joining". Plain "Days" is honest in both.
                    'metric'   => 'account_age_days',
                    'label'    => 'Days',
                    'current'  => (int) $stats['account_age_days'],
                    'required' => (int) ($req['account_age_days'] ?? 0),
                ],
            ];
        }

        return []; // Veteran is the top level reachable by auto-promotion.
    }

    private function countPulls(int $userId): int
    {
        // Pulls = follows on PeepSo's graph (§C2: watchlist is the UI projection).
        // Defensive class_exists in case bcc-core isn't loaded; if absent the
        // gate naturally fails closed because pulls=0 < 5.
        if (class_exists('\\BCC\\Core\\Repositories\\PeepSoFollowerRepository')) {
            $counts = \BCC\Core\Repositories\PeepSoFollowerRepository::getCounts($userId);
            return (int) ($counts['following'] ?? 0);
        }
        return 0;
    }

    private function countReviewsWritten(int $userId): int
    {
        // Phase 1: count vote rows authored by this user. The "review" post
        // type in the contract maps to bcc_trust_votes today; if a separate
        // review CPT is added in V2, this is the single place to update.
        return (int) $this->voteRepo->countByVoter($userId);
    }

    /**
     * Days since the account was registered — NOT days the user was active.
     *
     * Deliberately named for what it measures. This accrues while the user
     * is dormant and never decreases, so it is a tenure floor, not an
     * engagement signal. A real active-day counter would need a distinct
     * per-day activity record; that does not exist today and inventing one
     * here would be worse than admitting the gap.
     */
    private function countAccountAgeDays(int $userId): int
    {
        $registered = get_userdata($userId);
        if ($registered === false || empty($registered->user_registered)) {
            return 0;
        }
        $registeredTs = strtotime($registered->user_registered . ' UTC');
        if ($registeredTs === false) {
            return 0;
        }
        $diff = time() - $registeredTs;
        return $diff > 0 ? (int) floor($diff / DAY_IN_SECONDS) : 0;
    }

    private function hasVerifiedWallet(int $userId): bool
    {
        // VerificationRepository (bcc_trust_user_verifications) doesn't
        // know about wallet links — that lives in bcc_wallet_links via
        // WalletLinkReadInterface. WalletVerificationReadService composes
        // the two; route the check through it. Same instantiation
        // pattern as VoteEligibilityChecker since the service is
        // stateless and has no DI seam.
        return (new \BCC\Trust\Core\Application\WalletVerificationReadService())
            ->hasVerifiedWallet($userId);
    }

    private function getReputationTier(int $userId): string
    {
        $tier = $this->reputationRepo->getTier($userId);
        return $tier !== '' ? $tier : 'neutral';
    }

    /**
     * Compare a user's current tier against a required minimum.
     * Pure helper — no DB, no service calls.
     */
    private static function tierAtLeast(string $current, string $required): bool
    {
        $currentRank  = self::TIER_RANK[$current]  ?? self::TIER_RANK['neutral'];
        $requiredRank = self::TIER_RANK[$required] ?? self::TIER_RANK['neutral'];
        return $currentRank >= $requiredRank;
    }
}
