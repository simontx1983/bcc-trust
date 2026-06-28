<?php
/**
 * Member Profile Composer — produces the rich shape the
 * /u/[handle] page consumes per `MemberProfile` (TypeScript).
 *
 * Why a separate composer instead of bloating UserViewService:
 *   - `UserViewService::getUser` returns the canonical User view-model
 *     per contract §3.1 — narrow, stable, used by other surfaces (feed
 *     authors, member cards, lookups). Keeping it clean preserves §A4
 *     single-source-of-trust.
 *   - The /u/[handle] page wants more: hero card, identity strip,
 *     stats strip, shift log, activity breakdown, live-shift events,
 *     tab counts. These are render-side composition, not core trust
 *     state. They belong above UserViewService, not inside it.
 *   - Other surfaces that DON'T need the rich shape (feed author
 *     rendering, search suggestions) keep using the basic User view-
 *     model directly.
 *
 * V1 scope:
 *   - Cheap fields composed from existing services / counts: standing,
 *     identity_meta, stats, tabs, bio reshape, user_id alias.
 *   - Expensive aggregates resolved through dedicated services:
 *     `card` via CardViewService, `shift_log` via ShiftLogService,
 *     `activity_breakdown` + `live_shift` via PeepSoActivityRepository.
 *   - `reviews` and `disputes` ship as empty arrays. Lazy-load
 *     endpoints land later — the profile page's tabs surface them
 *     conditionally.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, profile-page rich shape)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Support\MemberCardPrefetcher;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberProfileComposer
{
    /**
     * §A2: server-rendered "Pulled by", "Trust", "Reviews" strip values.
     * Stat keys + labels are stable so the frontend can apply per-key
     * styling without reading values.
     *
     * @var array<int, array{key: string, label: string, platform: string|null}>
     */
    private const STAT_DEFINITIONS = [
        ['key' => 'trust',       'label' => 'Trust',       'platform' => 'BCC'],
        ['key' => 'pulled_by',   'label' => 'Watched by',  'platform' => 'PEEPSO'],
        ['key' => 'reviews',     'label' => 'Reviews',     'platform' => 'BCC'],
        ['key' => 'solids',      'label' => 'Solids',      'platform' => 'PEEPSO'],
        ['key' => 'disputes',    'label' => 'Disputes',    'platform' => 'BCC'],
        // Single source of truth — vocabulary unified on "Watching".
        // Frontend reads `key` only.
        ['key' => 'watching',    'label' => 'Watching',    'platform' => 'BCC'],
    ];

    /**
     * Mapping from peepso_activities `act_module_id` strings to the
     * five §N9 activity-breakdown bucket keys. Unknown modules are
     * silently dropped — the breakdown is "what BCC does," not "every
     * row PeepSo ever logged."
     *
     * @var array<string, string>
     */
    private const MODULE_TO_BREAKDOWN_KEY = [
        'status'      => 'posts',
        'review'      => 'reviews',
        'watch_batch' => 'pulls',
        'page_claim'  => 'posts',
        // Disputes get their own count from FlagsRepository (signed-by-user
        // semantics differ from peepso_activities rows).
    ];

    /**
     * Per-bucket display config. Order is canonical — frontend renders
     * in this order regardless of which buckets have data.
     *
     * @var array<int, array{
     *   key: string,
     *   label: string,
     *   description: string,
     *   tone: string
     * }>
     */
    private const BREAKDOWN_DEFINITIONS = [
        ['key' => 'pulls',     'label' => 'Pulls',     'description' => 'Cards added to the watchlist.', 'tone' => 'verified'],
        ['key' => 'reviews',   'label' => 'Reviews',   'description' => 'Long-form reviews on pages.', 'tone' => 'ink'],
        ['key' => 'reactions', 'label' => 'Reactions', 'description' => 'Solids, vouches, stand-behinds.', 'tone' => 'safety'],
        ['key' => 'disputes',  'label' => 'Disputes',  'description' => 'Disputes signed.', 'tone' => 'weld'],
        ['key' => 'posts',     'label' => 'Posts',     'description' => 'Status posts on the Floor.', 'tone' => 'ink'],
    ];

    /** Live-shift cap — how many recent acts to surface in the hero panel. */
    private const LIVE_SHIFT_LIMIT = 8;

    /**
     * Module → human verb mapping for the live-shift labels. Falls
     * back to a generic "Activity" for unknown modules.
     *
     * @var array<string, string>
     */
    private const MODULE_TO_LIVE_VERB = [
        'status'     => 'Posted on the Floor',
        'review'     => 'Wrote a review',
        'watch_batch' => 'Watched cards',
        'page_claim' => 'Claimed a page',
    ];

    public function __construct(
        private readonly UserViewService $userViewService,
        private readonly CardViewService $cardViewService,
        private readonly ShiftLogService $shiftLogService,
        private readonly AttestationService $attestationService
    ) {
    }

    /**
     * Compose the full member-profile view-model. Returns null when the
     * user_id doesn't resolve to a real wp_user.
     *
     * @return array<string, mixed>|null
     */
    public function compose(int $userId, int $viewerId): ?array
    {
        $base = $this->userViewService->getUser($userId, $viewerId);
        if ($base === null) {
            return null;
        }

        $isSelf = isset($base['is_self']) && $base['is_self'] === true;
        $handle = isset($base['handle']) && is_string($base['handle']) ? $base['handle'] : '';
        $displayName = isset($base['display_name']) && is_string($base['display_name'])
            ? $base['display_name']
            : '';

        // ── Add bio_block alongside the §3.1 string bio. The string
        //   form stays in `bio` so contract-compliant readers (the
        //   simple profile page, search snippets) work unchanged; rich
        //   consumers read `bio_block` for the paragraph + signature
        //   shape per §A2.
        $bioRaw = isset($base['bio']) && is_string($base['bio']) ? $base['bio'] : '';
        $base['bio_block'] = self::shapeBio($bioRaw, $displayName, $isSelf);

        // ── Add user_id alias — frontend reads it; contract uses id.
        $base['user_id'] = isset($base['id']) ? (int) $base['id'] : $userId;

        // ── §J.6 viewer_attestation — does the viewer currently have a
        //   vouch / stand_behind cast against THIS operator? Drives the
        //   FE AttestationActionCluster's cast-state copy ("VOUCHED" /
        //   "STANDING BEHIND") and Stand Behind slot availability.
        //   Profile-scoped attestations target_kind=user_profile per
        //   §J.1. Anon viewers get null (service returns null for
        //   viewerId<=0); the FE treats undefined and null identically
        //   per the §4.20 contract.
        $base['viewer_attestation'] = $viewerId > 0
            ? $this->attestationService->getViewerAttestation($viewerId, 'user_profile', $userId)
            : null;

        // ── §J.6 attestation permissions — extend the base
        //   permissions block (which UserViewService::resolvePermissions
        //   produced with can_follow / can_message / can_block /
        //   can_edit_profile) with the four §J.1 attestation actions.
        //   Service handles anon ("Sign in to vouch for operators."),
        //   self-target (hidden via allowed=false + unlock_hint=null
        //   per §N7), and tier-gating ("Reach Neutral standing to
        //   vouch.") uniformly. can_dispute is intentionally omitted
        //   on profile surfaces in Phase 1 — profile-scoped disputes
        //   ship in Phase 1.5 per §J.1; per §N7 absent permission =
        //   FE hides the action.
        $attestationPerms = $this->attestationService->getViewerActionPermissions(
            $viewerId,
            $userId
        );
        if (isset($base['permissions']) && is_array($base['permissions'])) {
            $base['permissions']['can_vouch']        = $attestationPerms['can_vouch'];
            $base['permissions']['can_stand_behind'] = $attestationPerms['can_stand_behind'];
            $base['permissions']['can_report']       = $attestationPerms['can_report'];
        }

        // ── Hero card — the canonical member card. Mirror the members-list
        //   path: prime a 1-element MemberCardPrefetcher and feed it to
        //   getMemberCardForList, so the card's getSummary delegation reuses one
        //   batched fetch instead of re-running the ~11 single-user dossier
        //   queries getUser() already issued above. Same card, fewer queries
        //   (Phase 7 dedup; behavior pinned by the /users/:handle golden master).
        $card = null;
        if ($handle !== '') {
            $cardPrefetch = MemberCardPrefetcher::primeFor([$userId], $viewerId);
            $card = $this->cardViewService->getMemberCardForList($userId, $viewerId, $cardPrefetch);
        }
        $base['card'] = $card ?? self::buildPlaceholderCard($userId, $base);

        // ── Standing (Good Standing ribbon).
        $base['standing'] = self::buildStanding($base);

        // ── Identity strip facts.
        $base['identity_meta'] = self::buildIdentityMeta($base);

        // ── Stats strip.
        $base['stats'] = self::buildStats($base);

        // ── Shift log — translate ShiftLogService's cells into the
        //   profile-page shape (days + summary + month_ticks).
        $shift = $this->shiftLogService->getShiftLog($userId, 52);
        $base['shift_log'] = self::shapeShiftLog($shift);

        // ── Activity breakdown — keyed buckets per BREAKDOWN_DEFINITIONS.
        $base['activity_breakdown'] = self::buildActivityBreakdown(
            $shift['totals_by_kind'] ?? [],
            $base
        );

        // ── Live shift — recent act rows the user authored.
        $base['live_shift'] = self::buildLiveShift($userId);

        // ── Tabs — counts for the on-file strip.
        $base['tabs'] = self::buildTabs($base);

        // ── Reviews / disputes — empty for V1; lazy endpoints land later.
        //   The page only consumes them inside their tabs (which show
        //   "Nothing yet" when empty).
        $base['reviews']  = [];
        $base['disputes'] = [];

        // ── PR-11b — identity verification status. Surfaced on
        //   /me/progression as the VERIFIED IDENTITY section, also
        //   consumed by the §3.1 verified panel on /u/[handle]. Same
        //   shape as the parallel block in UserViewService::getProfile
        //   so the contract is stable across surfaces. wallets_verified
        //   counts the already-resolved `wallets` array; github + x
        //   resolve via per-user repo lookups (cheap; single user).
        $walletCount = isset($base['wallets']) && is_array($base['wallets'])
            ? count($base['wallets'])
            : 0;
        $base['verifications'] = self::buildVerifications($userId, $walletCount);

        return $base;
    }

    /**
     * §3.1 verifications block. Reads from the canonical per-provider
     * repositories that UserViewService already uses. The shape mirrors
     * UserViewService::getProfile's `verifications` field so the wire
     * contract is consistent across endpoints.
     *
     * `profile_completeness` is the PeepSo profile-fields fill
     * percentage (0–100). Same primitive QuestValidator uses
     * (`profile_fields_stats.completeness`), shared so both surfaces
     * read from the same number. Surfaced on /me/progression as a
     * 4th row in the VERIFIED IDENTITY section per PR-11b.
     *
     * @return array{
     *   x_verified: bool,
     *   x_username: string|null,
     *   github_verified: bool,
     *   github_username: string|null,
     *   wallets_verified: int,
     *   profile_completeness: int
     * }
     */
    private static function buildVerifications(int $userId, int $walletCount): array
    {
        $githubVerified = false;
        $githubUsername = null;
        if (class_exists(\BCC\Trust\Core\Repositories\GitHubRepository::class)) {
            $githubConnections = (new \BCC\Trust\Core\Repositories\GitHubRepository())
                ->getConnectionsForUsers([$userId]);
            $githubConnection  = $githubConnections[$userId] ?? null;
            if (is_array($githubConnection)
                && isset($githubConnection['verified_at'])
                && is_string($githubConnection['verified_at'])
            ) {
                $githubVerified = true;
                $githubUsername = isset($githubConnection['provider_username'])
                    && is_string($githubConnection['provider_username'])
                    ? $githubConnection['provider_username']
                    : null;
            }
        }

        $xVerified = false;
        $xUsername = null;
        if (class_exists(\BCC\Trust\Core\Repositories\XRepository::class)) {
            $xConnections = (new \BCC\Trust\Core\Repositories\XRepository())
                ->getConnectionsForUsers([$userId]);
            $xConnection  = $xConnections[$userId] ?? null;
            if (is_array($xConnection)
                && isset($xConnection['verified_at'])
                && is_string($xConnection['verified_at'])
            ) {
                $xVerified = true;
                $xUsername = isset($xConnection['provider_username'])
                    && is_string($xConnection['provider_username'])
                    ? $xConnection['provider_username']
                    : null;
            }
        }

        return [
            'x_verified'           => $xVerified,
            'x_username'           => $xUsername,
            'github_verified'      => $githubVerified,
            'github_username'      => $githubUsername,
            'wallets_verified'     => $walletCount,
            'profile_completeness' => self::resolveProfileCompleteness($userId),
        ];
    }

    /**
     * Resolve the PeepSo profile-completeness percentage (0–100).
     *
     * Mirrors `QuestValidator::validateCompleteProfile`'s PeepSo path —
     * uses `PeepSoUser::get_instance($userId)->profile_fields` to
     * fetch the canonical stats. Falls back to 0 on any failure
     * (PeepSo absent, plugin disabled, exception during field load).
     *
     * Defensive: this is fire-and-forget. A failure here should NOT
     * propagate out of the profile composer — the rest of the page
     * still works even when this signal is unavailable.
     */
    private static function resolveProfileCompleteness(int $userId): int
    {
        if (!class_exists('PeepSoUser') || !class_exists('PeepSoProfileFields')) {
            return 0;
        }
        try {
            $peepsoUser = \PeepSoUser::get_instance($userId);
            $fields     = $peepsoUser->profile_fields;
            $fields->load_fields();
            $stats = $fields->profile_fields_stats ?? [];
            if (!is_array($stats)) {
                return 0;
            }
            $completeness = isset($stats['completeness']) ? (int) $stats['completeness'] : 0;
            return max(0, min(100, $completeness));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Bio
    // ──────────────────────────────────────────────────────────────────

    /**
     * Convert the plain-string bio into the {paragraphs, signature_line,
     * is_editable} shape the page consumes. Empty bios get a single
     * empty paragraph so the renderer doesn't have to special-case
     * `paragraphs[0]?.split(...)` returning undefined.
     *
     * @return array{paragraphs: list<string>, signature_line: string, is_editable: bool}
     */
    private static function shapeBio(string $raw, string $displayName, bool $isSelf): array
    {
        $trimmed = trim($raw);
        $paragraphs = $trimmed === ''
            ? ['']
            : (preg_split('/\n\s*\n/', $trimmed) ?: [$trimmed]);
        // Filter empty trailing paragraphs but keep at least one.
        $paragraphs = array_values(array_filter(
            array_map(static fn(string $p): string => trim($p), $paragraphs),
            static fn(string $p): bool => $p !== ''
        ));
        if ($paragraphs === []) {
            $paragraphs = [''];
        }

        $signatureLine = $displayName !== ''
            ? sprintf('Signed, %s', $displayName)
            : '';

        return [
            'paragraphs'     => $paragraphs,
            'signature_line' => $signatureLine,
            'is_editable'    => $isSelf,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Card placeholder (when CardViewService returns null)
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function buildPlaceholderCard(int $userId, array $base): array
    {
        $handle = isset($base['handle']) && is_string($base['handle']) ? $base['handle'] : '';
        $name = isset($base['display_name']) && is_string($base['display_name']) && $base['display_name'] !== ''
            ? $base['display_name']
            : $handle;
        $tier = isset($base['reputation_tier']) && is_string($base['reputation_tier'])
            ? $base['reputation_tier']
            : 'neutral';
        $cardTier = isset($base['card_tier']) ? $base['card_tier'] : null;
        $tierLabel = isset($base['tier_label']) ? $base['tier_label'] : null;
        $rankLabel = isset($base['rank_label']) ? $base['rank_label'] : null;
        $trustScore = isset($base['trust_score']) ? (int) $base['trust_score'] : 0;

        return [
            'id'                  => $userId,
            'name'                => $name,
            'handle'              => $handle,
            'card_kind'           => 'member',
            'trust_score'         => $trustScore,
            'reputation_tier'     => $tier,
            'card_tier'           => $cardTier,
            'tier_label'          => $tierLabel,
            'rank_label'          => $rankLabel,
            'is_in_good_standing' => isset($base['is_in_good_standing']) && $base['is_in_good_standing'] === true,
            'flags'               => isset($base['flags']) && is_array($base['flags']) ? $base['flags'] : [],
            'is_claimed'          => true,
            'claim_target'        => null,
            'viewer_has_reviewed' => false,
            'crest'               => [
                'initials'         => self::initials($name),
                'monogram_color'   => '#1a0f3e',
                'background_kind'  => 'tier',
                'background_value' => $cardTier ?? 'common',
                'image_url'        => null,
            ],
            'stats'               => [],
            'permissions'         => [
                'can_review'         => ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'card_unavailable'],
                'can_dispute'        => ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'card_unavailable'],
                'can_watch'          => ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'card_unavailable'],
                'can_endorse'        => ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'card_unavailable'],
                'can_post_as_entity' => false,
                'can_edit_bio'       => false,
            ],
            'social_proof'        => null,
            'links'               => [
                'self' => '/u/' . $handle,
            ],
        ];
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $initials .= strtoupper(mb_substr($part, 0, 1));
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }
        return $initials !== '' ? $initials : '??';
    }

    // ──────────────────────────────────────────────────────────────────
    // Standing
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $base
     * @return array{is_in_good_standing: bool, since_label: string, facts: list<string>}
     */
    private static function buildStanding(array $base): array
    {
        $isGood = isset($base['is_in_good_standing']) && $base['is_in_good_standing'] === true;
        $joinedAt = isset($base['joined_at']) && is_string($base['joined_at']) ? $base['joined_at'] : '';

        $sinceLabel = $joinedAt !== ''
            ? 'Since ' . self::formatJoinedYear($joinedAt)
            : '';

        $facts = [];
        $tier = isset($base['reputation_tier']) && is_string($base['reputation_tier'])
            ? $base['reputation_tier']
            : 'neutral';
        if ($tier !== '') {
            $facts[] = 'Reputation: ' . ucfirst($tier);
        }

        $rankLabel = isset($base['rank_label']) && is_string($base['rank_label']) && $base['rank_label'] !== ''
            ? $base['rank_label']
            : null;
        if ($rankLabel !== null) {
            $facts[] = 'Rank: ' . $rankLabel;
        }

        $flags = isset($base['flags']) && is_array($base['flags']) ? $base['flags'] : [];
        if ($flags === []) {
            $facts[] = 'No active moderation flags';
        }

        return [
            'is_in_good_standing' => $isGood,
            'since_label'         => $sinceLabel,
            'facts'               => array_slice($facts, 0, 3),
        ];
    }

    private static function formatJoinedYear(string $iso): string
    {
        $epoch = strtotime($iso);
        if ($epoch === false) {
            return '';
        }
        return gmdate('Y', $epoch);
    }

    // ──────────────────────────────────────────────────────────────────
    // Identity strip
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $base
     * @return list<array{label: string, value: string}>
     */
    private static function buildIdentityMeta(array $base): array
    {
        $items = [];

        $joinedAt = isset($base['joined_at']) && is_string($base['joined_at']) ? $base['joined_at'] : '';
        if ($joinedAt !== '') {
            $items[] = ['label' => 'Joined', 'value' => self::formatJoinedDate($joinedAt)];
        }

        $primaryLocal = isset($base['primary_local']) && is_array($base['primary_local'])
            ? $base['primary_local']
            : null;
        if ($primaryLocal !== null && isset($primaryLocal['name']) && is_string($primaryLocal['name'])) {
            $items[] = ['label' => 'Primary Local', 'value' => $primaryLocal['name']];
        }

        $wallets = isset($base['wallets']) && is_array($base['wallets']) ? $base['wallets'] : [];
        if (count($wallets) > 0) {
            $items[] = [
                'label' => 'Wallets',
                'value' => sprintf(
                    '%d linked',
                    count($wallets)
                ),
            ];
        }

        $rankLabel = isset($base['rank_label']) && is_string($base['rank_label']) && $base['rank_label'] !== ''
            ? $base['rank_label']
            : null;
        if ($rankLabel !== null) {
            $items[] = ['label' => 'Rank', 'value' => $rankLabel];
        }

        return $items;
    }

    private static function formatJoinedDate(string $iso): string
    {
        $epoch = strtotime($iso);
        if ($epoch === false) {
            return '';
        }
        return gmdate('M Y', $epoch);
    }

    // ──────────────────────────────────────────────────────────────────
    // Stats strip
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $base
     * @return list<array{
     *   key: string,
     *   label: string,
     *   value: string,
     *   delta: string|null,
     *   delta_tone: string|null,
     *   platform: string|null
     * }>
     */
    private static function buildStats(array $base): array
    {
        $counts = isset($base['counts']) && is_array($base['counts']) ? $base['counts'] : [];
        $trustScore = isset($base['trust_score']) ? (int) $base['trust_score'] : 0;

        $values = [
            'trust'     => (string) $trustScore,
            'pulled_by' => self::numberOrDash($counts['followers'] ?? null),
            'reviews'   => self::numberOrDash($counts['reviews_written'] ?? null),
            'solids'    => self::numberOrDash($counts['solids_received'] ?? null),
            'disputes'  => self::numberOrDash($counts['disputes_signed'] ?? null),
            'watching'  => self::numberOrDash($counts['watching_size'] ?? null),
        ];

        $stats = [];
        foreach (self::STAT_DEFINITIONS as $def) {
            $stats[] = [
                'key'        => $def['key'],
                'label'      => $def['label'],
                'value'      => $values[$def['key']],
                'delta'      => null,
                'delta_tone' => null,
                'platform'   => $def['platform'],
            ];
        }
        return $stats;
    }

    private static function numberOrDash(mixed $raw): string
    {
        if ($raw === null) {
            return '0';
        }
        if (is_int($raw)) {
            return number_format($raw);
        }
        if (is_numeric($raw)) {
            return number_format((int) $raw);
        }
        return '0';
    }

    // ──────────────────────────────────────────────────────────────────
    // Shift log shape (days + summary + month_ticks)
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array{
     *   weeks: int,
     *   as_of: string,
     *   cells: list<array{date: string, count: int, intensity: string, kinds: list<string>}>,
     *   totals_by_kind: array<string, int>
     * } $shift
     * @return array{days: list<array{date: string, level: int, tooltip: string}>, summary: string, month_ticks: list<string>}
     */
    private static function shapeShiftLog(array $shift): array
    {
        $intensityToLevel = [
            'none' => 0,
            'low'  => 1,
            'med'  => 2,
            'high' => 3,
            'peak' => 4,
        ];

        $days = [];
        $totalActions = 0;
        foreach ($shift['cells'] as $cell) {
            $count = (int) $cell['count'];
            $totalActions += $count;
            $intensity = (string) ($cell['intensity'] ?? 'none');
            $level = $intensityToLevel[$intensity] ?? 0;
            $days[] = [
                'date'    => (string) $cell['date'],
                'level'   => $level,
                'tooltip' => self::shiftTooltip((string) $cell['date'], $count),
            ];
        }

        // Cells came in newest-first; reverse so the grid reads left→right
        // as oldest→newest (matches the prototype's reading order).
        $days = array_reverse($days);

        $weeks = (int) ($shift['weeks'] ?? 52);
        $summary = sprintf(
            '%s action%s over the last %d week%s',
            number_format($totalActions),
            $totalActions === 1 ? '' : 's',
            $weeks,
            $weeks === 1 ? '' : 's'
        );

        return [
            'days'        => $days,
            'summary'     => $summary,
            'month_ticks' => self::buildMonthTicks($days),
        ];
    }

    private static function shiftTooltip(string $date, int $count): string
    {
        $epoch = strtotime($date . ' UTC');
        $datePart = $epoch !== false ? gmdate('M j', $epoch) : $date;
        if ($count === 0) {
            return $datePart . ' · no activity';
        }
        return sprintf('%s · %d action%s', $datePart, $count, $count === 1 ? '' : 's');
    }

    /**
     * Sample 12 evenly-spaced month labels across the day grid. Returned
     * as ["Jan", "Feb", "Mar", ...] left-to-right.
     *
     * @param list<array{date: string, level: int, tooltip: string}> $days
     * @return list<string>
     */
    private static function buildMonthTicks(array $days): array
    {
        $count = count($days);
        if ($count === 0) {
            return [];
        }
        $ticks = [];
        $seen = [];
        for ($i = 0; $i < 12; $i++) {
            $idx = (int) floor(($i / 12) * $count);
            if ($idx >= $count) {
                $idx = $count - 1;
            }
            $date = $days[$idx]['date'] ?? '';
            $epoch = $date !== '' ? strtotime($date . ' UTC') : false;
            $label = $epoch !== false ? gmdate('M', $epoch) : '';
            // Suppress duplicates (e.g. December appearing twice when 13
            // months span 52 weeks). Frontend tolerates fewer than 12.
            if ($label !== '' && !isset($seen[$label . $i])) {
                $seen[$label . $i] = true;
                $ticks[] = $label;
            }
        }
        return $ticks;
    }

    // ──────────────────────────────────────────────────────────────────
    // Activity breakdown
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string, int> $totalsByKind  Output from PeepSoActivityRepository::aggregateByModule.
     * @param array<string, mixed> $base
     * @return list<array{
     *   key: string,
     *   label: string,
     *   description: string,
     *   count: int,
     *   delta_label: string|null,
     *   tone: string
     * }>
     */
    private static function buildActivityBreakdown(array $totalsByKind, array $base): array
    {
        // Roll up activity-row totals into the canonical bucket keys.
        $buckets = ['pulls' => 0, 'reviews' => 0, 'reactions' => 0, 'disputes' => 0, 'posts' => 0];
        foreach ($totalsByKind as $module => $count) {
            $key = self::MODULE_TO_BREAKDOWN_KEY[$module] ?? null;
            if ($key !== null) {
                $buckets[$key] += (int) $count;
            }
        }

        // Reactions + disputes don't live in peepso_activities — pull
        // from the `counts` block which already has them aggregated
        // server-side.
        $counts = isset($base['counts']) && is_array($base['counts']) ? $base['counts'] : [];
        $buckets['reactions'] = (int) ($counts['solids_given'] ?? 0);
        $buckets['disputes']  = (int) ($counts['disputes_signed'] ?? 0);

        $items = [];
        foreach (self::BREAKDOWN_DEFINITIONS as $def) {
            $items[] = [
                'key'         => $def['key'],
                'label'       => $def['label'],
                'description' => $def['description'],
                'count'       => $buckets[$def['key']],
                'delta_label' => null,
                'tone'        => $def['tone'],
            ];
        }
        return $items;
    }

    // ──────────────────────────────────────────────────────────────────
    // Live shift
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return list<array{id: string, label: string, ago: string, metric: string|null}>
     */
    private static function buildLiveShift(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $rows = PeepSoActivityRepository::getActivities(
            [$userId],
            null,
            null,
            null,
            self::LIVE_SHIFT_LIMIT
        );

        $now = time();
        $events = [];
        foreach ($rows as $row) {
            $module = isset($row->act_module_id) && is_string($row->act_module_id)
                ? $row->act_module_id
                : '';
            $verb = self::MODULE_TO_LIVE_VERB[$module] ?? 'Activity';
            $time = isset($row->act_time) && is_string($row->act_time) ? $row->act_time : '';

            $events[] = [
                'id'     => 'act_' . (isset($row->act_id) ? (string) $row->act_id : '0'),
                'label'  => $verb,
                'ago'    => self::relativeTime($time, $now),
                'metric' => null,
            ];
        }
        return $events;
    }

    private static function relativeTime(string $mysqlTime, int $now): string
    {
        if ($mysqlTime === '') {
            return '';
        }
        $epoch = strtotime($mysqlTime . ' UTC');
        if ($epoch === false) {
            return '';
        }
        $diff = max(0, $now - $epoch);
        if ($diff < 60) {
            return $diff . 's';
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60) . 'm';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . 'h';
        }
        return (int) floor($diff / 86400) . 'd';
    }

    // ──────────────────────────────────────────────────────────────────
    // Tabs
    // ──────────────────────────────────────────────────────────────────

    /**
     * §K2: each tab carries a `hidden` flag so the frontend can render
     * a "private" placeholder instead of a count. Owner always sees
     * `hidden=false` (per UserViewService — privacy is viewer-relative,
     * applied to counts there; here we propagate the same signal to the
     * tab strip so the "Reviews · Private" pattern can render).
     *
     * @param array<string, mixed> $base
     * @return list<array{key: string, label: string, count: int, hidden: bool}>
     */
    private static function buildTabs(array $base): array
    {
        $counts  = isset($base['counts']) && is_array($base['counts']) ? $base['counts'] : [];
        $privacy = isset($base['privacy']) && is_array($base['privacy']) ? $base['privacy'] : [];
        $isSelf  = isset($base['is_self']) && $base['is_self'] === true;

        $hideFor = static function (string $key) use ($privacy, $isSelf): bool {
            if ($isSelf) {
                return false;
            }
            return isset($privacy[$key]) && $privacy[$key] === true;
        };

        // Vocabulary unified on "Watching" — single source of truth
        // (frontend reads `key`). Privacy reads `watching_hidden`.
        $watchHidden = $hideFor('watching_hidden');

        return [
            ['key' => 'watching', 'label' => 'Watching', 'count' => (int) ($counts['watching_size'] ?? 0), 'hidden' => $watchHidden],
            ['key' => 'reviews',  'label' => 'Reviews',  'count' => (int) ($counts['reviews_written'] ?? 0),  'hidden' => $hideFor('reviews_hidden')],
            ['key' => 'activity', 'label' => 'Activity', 'count' => 0,                                         'hidden' => false],
            ['key' => 'disputes', 'label' => 'Disputes', 'count' => (int) ($counts['disputes_signed'] ?? 0),  'hidden' => $hideFor('disputes_hidden')],
            ['key' => 'network',  'label' => 'Network',  'count' => (int) ($counts['following'] ?? 0),        'hidden' => $hideFor('delegations_hidden')],
        ];
    }
}
