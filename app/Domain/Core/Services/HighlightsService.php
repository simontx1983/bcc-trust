<?php
/**
 * Highlights Service — §O2 / §O2.1 implementation.
 *
 * The "what to care about RIGHT NOW" strip atop the feed. Three slots,
 * strict priority order, max one item per slot, empty slots collapse:
 *
 *   slot 1 — negative event affecting the viewer (slashing, uptime
 *            drop on a followed validator, dispute against an entity
 *            in binder)
 *   slot 2 — positive milestone for the viewer (rank promotion, card
 *            tier bump, milestone reactions on own content)
 *   slot 3 — high-signal external event (followed creator drop,
 *            project release, trending Legendary)
 *
 * §O2.1 invariants (LOCKED):
 *   - Predictable order. Slot 1 always above slot 2; slot 2 above slot 3.
 *   - Never re-shuffled to chase engagement.
 *   - Empty slots collapse — no padding.
 *   - One item per slot, max.
 *
 * V1.0 scaffolding: all three slot scorers are stubs that return null.
 * The architecture, contract shape, and dismissal pipeline are
 * production-ready; data fills in as the underlying aggregators land
 * (bcc_onchain_signals deltas for slot 1, bcc_user_ranks events for
 * slot 2, followed-entity activity for slot 3). No API shape change
 * when scorers come online — just data flips from null to populated.
 *
 * Dismissal storage: wp_usermeta.bcc_highlights_dismissed_until — a
 * JSON map of {highlight_id: epoch_expiry}. Filtering happens in
 * getHighlights() before items are returned.
 *
 * V1.0 dismissal TTL by slot (proxy until §O2 state-bound semantics ship):
 *   - negative → 30 days (proxy for "dismissed until state changes")
 *   - positive → 24 hours
 *   - external → 24 hours
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §O2)
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Support\RankCatalog;

if (!defined('ABSPATH')) {
    exit;
}

final class HighlightsService
{
    public const SLOT_NEGATIVE = 'negative';
    public const SLOT_POSITIVE = 'positive';
    public const SLOT_EXTERNAL = 'external';

    /**
     * LivingService is the canonical source of per-user activity signal
     * (`today` counters, streak, network percentile). The POSITIVE slot
     * reuses that computation rather than duplicating queries — each
     * highlight is a presentation of data the user already has on
     * their living-header block.
     *
     * Nullable to keep existing call sites that construct the service
     * bare working (the resolver falls back to its V1.0 null stub).
     * Plugin.php wires the live dependency.
     *
     * RankService is consulted only to fetch the current auto-derived
     * rank label so the welcome highlight for cold users can reference
     * it by name without re-deriving on the frontend.
     */
    public function __construct(
        private readonly ?LivingService $livingService = null,
        private readonly ?RankService $rankService = null
    ) {
    }

    /** @var list<string> */
    public const VALID_SLOTS = [self::SLOT_NEGATIVE, self::SLOT_POSITIVE, self::SLOT_EXTERNAL];

    /** TTL by slot, in seconds. */
    private const DISMISSAL_TTL = [
        self::SLOT_NEGATIVE => 2_592_000, // 30 days (state-bound proxy)
        self::SLOT_POSITIVE => 86_400,    // 24 hours
        self::SLOT_EXTERNAL => 86_400,    // 24 hours
    ];

    /** wp_usermeta key for the dismissal JSON map. */
    private const DISMISSED_META_KEY = 'bcc_highlights_dismissed_until';

    /**
     * Build the highlight strip for a viewer. Always returns {items}
     * with 0–3 entries in §O2.1 priority order.
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function getHighlights(int $viewerId): array
    {
        if ($viewerId <= 0) {
            return ['items' => []];
        }

        $dismissed = self::loadDismissedMap($viewerId);
        $now       = time();

        $items = [];

        // Slot 1 — negative. ALWAYS first; never reordered (§O2.1).
        $candidate = $this->resolveNegativeSlot($viewerId);
        if ($candidate !== null && !self::isDismissed($candidate['id'], $dismissed, $now)) {
            $items[] = $candidate;
        }

        // Slot 2 — positive milestone for the viewer.
        $candidate = $this->resolvePositiveSlot($viewerId);
        if ($candidate !== null && !self::isDismissed($candidate['id'], $dismissed, $now)) {
            $items[] = $candidate;
        }

        // Slot 3 — high-signal external event.
        $candidate = $this->resolveExternalSlot($viewerId);
        if ($candidate !== null && !self::isDismissed($candidate['id'], $dismissed, $now)) {
            $items[] = $candidate;
        }

        return ['items' => $items];
    }

    /**
     * Dismiss a highlight by id. Stores expiry timestamp (per-slot TTL)
     * in wp_usermeta. Idempotent — re-dismissing extends the expiry.
     *
     * Returns:
     *   - success path: {status: 'dismissed', id, expires_at: ISO8601}
     *   - error path:   {error: 'bcc_invalid_request', message: ...}
     *
     * @return array<string, mixed>
     */
    public function dismiss(int $viewerId, string $highlightId): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        if ($highlightId === '' || strlen($highlightId) > 128) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid highlight id.'];
        }

        $slot = self::extractSlotFromId($highlightId);
        if ($slot === null) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid highlight id.'];
        }

        $ttl       = self::DISMISSAL_TTL[$slot];
        $expiresAt = time() + $ttl;

        $map              = self::loadDismissedMap($viewerId);
        $map[$highlightId] = $expiresAt;
        $map               = self::pruneExpired($map, time());

        update_user_meta($viewerId, self::DISMISSED_META_KEY, wp_json_encode($map));

        return [
            'status'     => 'dismissed',
            'id'         => $highlightId,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Slot scorers — V1.0 stubs (data sources not yet wired)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Slot 1 — negative event affecting the viewer.
     *
     * V1.0 STUB: returns null in production. Implementation roadmap:
     *   - JOIN bcc_onchain_signals deltas with viewer's binder to find
     *     uptime drops on followed validators (≥ 1% over 24h)
     *   - bcc_trust_flags filed against entities the viewer pulled
     *   - slashing events on followed validators
     *   - score by urgency × personal-relevance; pick the top 1
     *
     * @return array<string, mixed>|null
     */
    private function resolveNegativeSlot(int $viewerId): ?array
    {
        if (self::demoEnabled()) {
            return self::demoHighlight(
                self::SLOT_NEGATIVE,
                $viewerId,
                'uptime_drop',
                'A validator you follow just dropped uptime',
                'Demo entry — replace once the bcc_onchain_signals delta scorer ships.'
            );
        }
        return null;
    }

    /**
     * Slot 2 — positive milestone for the viewer.
     *
     * V1.5 implementation (2026-05-13): reuses the LivingService signals
     * already composed for the user's profile header. No new queries.
     * Returns the strongest non-null signal in priority order:
     *
     *   1. Reviews written today        — highest-effort daily action
     *   2. Disputes signed today        — civic-duty action
     *   3. Solids received today        — passive recognition
     *   4. Top X% this week comparison  — network gravity
     *   5. Streak ≥ 3 days              — sustained-presence cue
     *   6. Cold-user welcome            — "first shift open" for users
     *                                     with no streak and no `today`
     *                                     activity (i.e. fresh accounts)
     *
     * Returns null when all six are unavailable (e.g. mid-tenure users
     * having a quiet day — better to show nothing than fabricate signal).
     *
     * Dependency: requires `livingService` to have been injected. When
     * the service is bare-constructed (legacy code paths, tests), the
     * resolver returns null — same behaviour as the V1.0 stub.
     *
     * @return array<string, mixed>|null
     */
    private function resolvePositiveSlot(int $viewerId): ?array
    {
        if (self::demoEnabled()) {
            return self::demoHighlight(
                self::SLOT_POSITIVE,
                $viewerId,
                'rank_promoted',
                'You just hit Journeyman',
                'Demo entry — replace once bcc_user_ranks promotion events flow.'
            );
        }

        if ($this->livingService === null || $this->rankService === null) {
            return null;
        }

        $rankKey = $this->rankService->autoDerivedRank($viewerId);
        $rankLabel = RankCatalog::getLabel($rankKey) ?? 'Member';

        $living = $this->livingService->compose($viewerId, $rankKey);

        $today = $living['today'];
        $todayKey = gmdate('Y-m-d');

        // Priority 1 — reviews written today.
        if ($today['reviews'] > 0) {
            return self::positiveHighlight(
                $viewerId,
                'reviews_today',
                $todayKey,
                $today['reviews'] === 1
                    ? 'You wrote a review today.'
                    : sprintf('You wrote %d reviews today.', $today['reviews']),
                "It's on the record. Your weight built a little."
            );
        }

        // Priority 2 — disputes signed today (panel duty).
        if ($today['disputes_signed'] > 0) {
            return self::positiveHighlight(
                $viewerId,
                'disputes_signed_today',
                $todayKey,
                $today['disputes_signed'] === 1
                    ? 'You signed a dispute today.'
                    : sprintf('You signed %d disputes today.', $today['disputes_signed']),
                'Panel duty logged. The floor relies on calls like yours.'
            );
        }

        // Priority 3 — solids received (recognition for prior work).
        if ($today['solids_received'] > 0) {
            return self::positiveHighlight(
                $viewerId,
                'solids_today',
                $todayKey,
                $today['solids_received'] === 1
                    ? '1 solid landed on your work today.'
                    : sprintf('%d solids landed on your work today.', $today['solids_received']),
                'Someone read what you wrote and put their name on it.'
            );
        }

        // Priority 4 — top-percentile network comparison (already
        // soft-phrased server-side; null for bottom 50%).
        if (is_array($living['comparison']) && isset($living['comparison']['headline'])) {
            $headline = (string) $living['comparison']['headline'];
            $asOf = (string) ($living['comparison']['as_of'] ?? $todayKey);
            return self::positiveHighlight(
                $viewerId,
                'network_percentile',
                $asOf,
                $headline . '.',
                'The floor sees the shifts you put in.'
            );
        }

        // Priority 5 — sustained streak (3+ days). Re-uses the existing
        // streak counter; doesn't fabricate one. Cool-down: dismissals
        // re-apply within the same 24h window.
        $streakDays = (int) $living['streak_days'];
        if ($streakDays >= 3) {
            return self::positiveHighlight(
                $viewerId,
                'streak',
                $todayKey,
                sprintf('%d-day shift run.', $streakDays),
                'You keep showing up. The floor remembers.'
            );
        }

        // Priority 6 — cold-user welcome. Fires for fresh accounts with
        // no streak and a quiet day. Stable per-user (not per-day) so
        // dismissing it once retires it for the 24h TTL; on day 2 it
        // surfaces again only if the user is STILL fully quiet.
        if ($streakDays === 0 && $today['reviews'] === 0 && $today['disputes_signed'] === 0 && $today['solids_received'] === 0) {
            return self::positiveHighlight(
                $viewerId,
                'first_shift_open',
                $todayKey,
                sprintf('First shift open. %s rank.', $rankLabel),
                'Anything you do here shows up in this slot. Cards on your watch list, reviews you write, disputes you sign.'
            );
        }

        return null;
    }

    /**
     * Build a contract-compliant POSITIVE-slot highlight payload. The
     * id is keyed by `viewer + category + scope` so dismissals stick
     * within the slot's TTL (24h) but the next day's signal re-surfaces
     * with a fresh id.
     *
     * The scope segment is the per-signal natural cohort key:
     *   - daily signals (reviews/disputes/solids/streak): YYYY-MM-DD
     *   - percentile: ISO week the comparison was computed for
     *   - welcome: YYYY-MM-DD (renews per UTC day; users get one welcome
     *     reminder per day until they actually do something)
     *
     * @return array<string, mixed>
     */
    private static function positiveHighlight(
        int $viewerId,
        string $category,
        string $scope,
        string $title,
        string $body
    ): array {
        $id = sprintf('h-%s-%s-%s-%d', self::SLOT_POSITIVE, $category, $scope, $viewerId);
        return [
            'id'       => $id,
            'slot'     => self::SLOT_POSITIVE,
            'category' => $category,
            'title'    => $title,
            'body'     => $body,
            'cta'      => [
                'label' => 'View profile',
                'href'  => '/u/' . self::handleFromUser($viewerId),
            ],
            'actions'  => [
                'dismiss' => [
                    'method'        => 'POST',
                    'href'          => '/wp-json/bcc/v1/me/highlights/' . $id . '/dismiss',
                    'idempotent'    => true,
                    'requires_auth' => true,
                ],
            ],
        ];
    }

    /**
     * Resolve a user's handle for CTA href construction. Falls back to
     * an empty path when the handle is missing — the frontend renders
     * the CTA verbatim, so an empty handle results in /u/ which the
     * server-side route handler returns 404 for. That's acceptable for
     * the cold-edge case (very fresh accounts mid-onboarding) where
     * the highlight is itself sparse.
     */
    private static function handleFromUser(int $userId): string
    {
        $handle = get_user_meta($userId, 'bcc_handle', true);
        return is_string($handle) ? $handle : '';
    }

    /**
     * Slot 3 — high-signal external event (followed entity).
     *
     * V1.0 STUB: returns null in production. Implementation roadmap:
     *   - peepso_activities for followed creators with module=nft (drops)
     *   - peepso_activities for followed projects with module=project_drop
     *   - trending Legendary entities entering the viewer's interest
     *     graph (binder + onchain signals)
     *
     * @return array<string, mixed>|null
     */
    private function resolveExternalSlot(int $viewerId): ?array
    {
        if (self::demoEnabled()) {
            return self::demoHighlight(
                self::SLOT_EXTERNAL,
                $viewerId,
                'creator_drop',
                'A creator you follow just dropped a piece',
                'Demo entry — replace once followed-entity activity scorer ships.'
            );
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────
    // Dev-mode demo helpers
    //
    // Production sites NEVER define BCC_HIGHLIGHTS_DEMO; the stubs
    // return null and the strip stays empty until real scorers land.
    // For end-to-end frontend testing or live debugging, define the
    // constant in wp-config or a debug plugin:
    //
    //   define('BCC_HIGHLIGHTS_DEMO', true);
    //
    // and each slot will return a contract-compliant placeholder so
    // the frontend can render the strip's full layout.
    // ──────────────────────────────────────────────────────────────────

    private static function demoEnabled(): bool
    {
        return defined('BCC_HIGHLIGHTS_DEMO') && constant('BCC_HIGHLIGHTS_DEMO') === true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function demoHighlight(
        string $slot,
        int $viewerId,
        string $category,
        string $title,
        string $body
    ): array {
        $id = sprintf('h-%s-demo-%d', $slot, $viewerId);
        return [
            'id'       => $id,
            'slot'     => $slot,
            'category' => $category,
            'title'    => $title,
            'body'     => $body,
            'cta'      => [
                'label' => 'View',
                'href'  => '/',
            ],
            'actions'  => [
                'dismiss' => [
                    'method'        => 'POST',
                    'href'          => '/wp-json/bcc/v1/me/highlights/' . $id . '/dismiss',
                    'idempotent'    => true,
                    'requires_auth' => true,
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Dismissal storage helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array<string, int>  highlight_id => epoch expiry
     */
    private static function loadDismissedMap(int $viewerId): array
    {
        $raw = get_user_meta($viewerId, self::DISMISSED_META_KEY, true);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_int($value)) {
                $map[$key] = $value;
            } elseif (is_string($key) && is_numeric($value)) {
                $map[$key] = (int) $value;
            }
        }
        return $map;
    }

    /**
     * @param array<string, int> $map
     */
    private static function isDismissed(string $highlightId, array $map, int $now): bool
    {
        if (!isset($map[$highlightId])) {
            return false;
        }
        return $map[$highlightId] > $now;
    }

    /**
     * Drop expired entries on every write so the map doesn't grow
     * unbounded across a user's lifetime.
     *
     * @param array<string, int> $map
     * @return array<string, int>
     */
    private static function pruneExpired(array $map, int $now): array
    {
        $pruned = [];
        foreach ($map as $id => $expiry) {
            if ($expiry > $now) {
                $pruned[$id] = $expiry;
            }
        }
        return $pruned;
    }

    /**
     * Extract the slot from a highlight id of the form `h-{slot}-{rest}`.
     * Returns null when the id doesn't conform to that format or the
     * slot isn't a known value.
     */
    private static function extractSlotFromId(string $id): ?string
    {
        if (!str_starts_with($id, 'h-')) {
            return null;
        }
        $rest = substr($id, 2);
        $sep  = strpos($rest, '-');
        if ($sep === false || $sep === 0) {
            return null;
        }
        $slot = substr($rest, 0, $sep);
        return in_array($slot, self::VALID_SLOTS, true) ? $slot : null;
    }
}
