<?php
/**
 * NotificationType — canonical type slugs for §I1 notifications.
 *
 * PeepSo's `peepso_notifications.not_type` column accepts any short
 * string — its only internal use is the `<type>_notification` opt-out
 * key in user meta. That gives BCC freedom to use stable, grep-able
 * slugs that double as the §I2 event taxonomy.
 *
 * Why a class of constants instead of a string-union enum:
 *   - PHP 8.1 enums would be cleaner but break the bcc-core +
 *     bcc-trust autoload contract on installs still on 8.0 (the
 *     plugin headers declare PHP 7.4 minimum). String constants are
 *     the safest path that still gives PHPStan-checkable callers.
 *   - Frontend mirrors this set as a TypeScript union — names match
 *     1:1 so cross-stack searches stay easy.
 *
 * V1 type catalogue (per §I2 launch checklist):
 *   - REACTION       — Solid/Vouch/Back on your post
 *   - REVIEW         — review on a page you own
 *   - CARD_WATCHED   — someone watched (followed) your validator/etc
 *   - RANK_UP        — you earned a new rank
 *   - WELCOME        — first-touch system notification on signup
 *                     (V2 Phase 2 retention slice — proves the bell
 *                     channel works within seconds of opting in;
 *                     idempotent via `bcc_welcomed` user_meta).
 *   - MENTION        — someone @-tagged you in a post or comment
 *                     (original-write only — edits do not re-dispatch;
 *                     dedup'd to one bell row per (post, mentioner,
 *                     mentionee) by MentionExtractor::extractUserIds'
 *                     unique-id contract).
 *   - HALL_POST      — someone posted in the Hall you've designated
 *                     as your primary (`bcc_primary_hall_group_id`).
 *                     Fan-out to all primary-members; async via
 *                     AsyncDispatcher; bell coalesced to one row per
 *                     (recipient, group) per 5-min window via
 *                     transient; push coalesced via existing
 *                     PushDispatcher debounce.
 *   - COMMENT_RECEIVED — someone commented on your post (post-author
 *                     is the recipient). Original-write only — comment
 *                     edits do not re-dispatch (no edit hook exists).
 *                     Bell coalesced to one row per (recipient, post)
 *                     per 5-min window via transient; push coalesced
 *                     via existing PushDispatcher debounce.
 *
 * Deferred (per §P): follow-posts. Each will extend this catalogue
 * when its dispatcher subscriber lands.
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04, §I1)
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class NotificationType
{
    public const REACTION                         = 'bcc_reaction';
    public const REVIEW                           = 'bcc_review';
    public const CARD_WATCHED                     = 'bcc_card_watched';
    public const RANK_UP                          = 'bcc_rank_up';
    public const RANK_DEMOTED                     = 'bcc_rank_demoted';
    public const WELCOME                          = 'bcc_welcome';
    public const MENTION                          = 'bcc_mention';
    public const HALL_POST                        = 'bcc_hall_post';
    public const COMMENT_RECEIVED                 = 'bcc_comment_received';

    /**
     * Rank redesign Phase 8 — recovery + misconduct notices (§14.2 /
     * §15 / §12.3). All self-notifications (from = to = the member),
     * bell-only (deliberately NO push types — none of these are
     * "you really need to know right now" events), plain deadline-
     * framed copy with zero cadence-pressure patterns (§2.7).
     *
     *   - RANK_RECOVERY_STARTED  — §14.1 grace began; privileges paused
     *   - RANK_RECOVERY_REMINDER — 30- and 7-day recovery marks
     *   - RANK_DECAY_WARNING     — §12.3 inactivity decay is active
     *                              (30-day usermeta re-notify gate)
     *   - RANK_FINDING_ISSUED    — a §15 misconduct finding was recorded
     *   - RANK_APPEAL_OUTCOME    — §15.5 reconsideration (upheld /
     *                              reduced / remanded)
     *   - RANK_FINDING_REVERSED  — §15.5 reversal + restoration statement
     */
    public const RANK_RECOVERY_STARTED            = 'bcc_rank_recovery_started';
    public const RANK_RECOVERY_REMINDER           = 'bcc_rank_recovery_reminder';
    public const RANK_DECAY_WARNING               = 'bcc_rank_decay_warning';
    public const RANK_FINDING_ISSUED              = 'bcc_rank_finding_issued';
    public const RANK_APPEAL_OUTCOME              = 'bcc_rank_appeal_outcome';
    public const RANK_FINDING_REVERSED            = 'bcc_rank_finding_reversed';

    /**
     * V2 Trust Attestation Layer event taxonomy (§I1, locked 2026-05-13).
     * Four discrete event types so the recipient can independently
     * toggle each in prefs and the FE can render distinct copy per
     * event. Self-attest is structurally skipped at dispatch time.
     *
     * Asymmetric posture: positive events (vouch/stand_behind received,
     * reaffirmed) read aspirationally; revoke reads neutrally — no
     * stigma copy per the §J.3.2 asymmetric-display rule.
     */
    public const ATTESTATION_VOUCH_RECEIVED         = 'bcc_attestation_vouch_received';
    public const ATTESTATION_STAND_BEHIND_RECEIVED  = 'bcc_attestation_stand_behind_received';
    public const ATTESTATION_REVOKED                = 'bcc_attestation_revoked';
    public const ATTESTATION_REAFFIRMED             = 'bcc_attestation_reaffirmed';

    /**
     * PR-8b — divergence-state warning. Fired by the daily
     * PolarizationTransitionNotifier when a target transitions INTO
     * `polarizing` or `disputed` (per §J.7 / §J.8). 24h coalescing per
     * (recipient, target_kind, target_id, new_state) so the same
     * transition doesn't spam the bell across cron ticks. Deep-links
     * to /me/reliability where the §J.5 explainer body sits.
     */
    public const DIVERGENCE_STATE_WARNING           = 'bcc_divergence_state_warning';

    /**
     * Collection-stances slice — a holder community the user WAITLISTED
     * (explicit opt-in via the stance panel) has been verified +
     * provisioned. Fired once per (user, collection):
     * CollectionSignalRepository::notified_at stamps delivery so
     * provisioning re-runs can't re-notify.
     */
    public const HOLDER_COMMUNITY_LIVE              = 'bcc_holder_community_live';

    /**
     * Whitelist of valid type slugs. Used by the read-side validation
     * (NotificationViewService) to reject corrupt rows rather than
     * surface garbage to the frontend.
     *
     * @var list<string>
     */
    public const ALL = [
        self::REACTION,
        self::REVIEW,
        self::CARD_WATCHED,
        self::RANK_UP,
        // RANK_DEMOTED was dispatched but missing here from its Phase 5
        // introduction — NotificationViewService silently rejected every
        // demotion bell row. Fixed Rank Phase 8; the generic
        // NotificationTypeRegistrationTest now makes this class of bug
        // structurally impossible.
        self::RANK_DEMOTED,
        self::RANK_RECOVERY_STARTED,
        self::RANK_RECOVERY_REMINDER,
        self::RANK_DECAY_WARNING,
        self::RANK_FINDING_ISSUED,
        self::RANK_APPEAL_OUTCOME,
        self::RANK_FINDING_REVERSED,
        self::WELCOME,
        self::MENTION,
        self::HALL_POST,
        self::COMMENT_RECEIVED,
        self::ATTESTATION_VOUCH_RECEIVED,
        self::ATTESTATION_STAND_BEHIND_RECEIVED,
        self::ATTESTATION_REVOKED,
        self::ATTESTATION_REAFFIRMED,
        self::DIVERGENCE_STATE_WARNING,
        self::HOLDER_COMMUNITY_LIVE,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
