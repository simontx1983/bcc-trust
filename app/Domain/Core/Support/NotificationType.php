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
 *   - REACTION       — Solid/Vouch/Stand-behind on your post
 *   - REVIEW         — review on a page you own
 *   - CARD_PULLED    — someone pulled (followed) your validator/etc
 *   - RANK_UP        — you earned a new rank
 *   - ENDORSE        — someone endorsed a page you own (V1.5 follow-up)
 *   - WELCOME        — first-touch system notification on signup
 *                     (V2 Phase 2 retention slice — proves the bell
 *                     channel works within seconds of opting in;
 *                     idempotent via `bcc_welcomed` user_meta).
 *   - MENTION        — someone @-tagged you in a post or comment
 *                     (original-write only — edits do not re-dispatch;
 *                     dedup'd to one bell row per (post, mentioner,
 *                     mentionee) by MentionExtractor::extractUserIds'
 *                     unique-id contract).
 *   - LOCAL_POST     — someone posted in the Local you've designated
 *                     as your primary (`bcc_primary_local_group_id`).
 *                     Fan-out to all primary-members; async via
 *                     AsyncDispatcher; bell coalesced to one row per
 *                     (recipient, group) per 5-min window via
 *                     transient; push coalesced via existing
 *                     PushDispatcher debounce.
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
    public const REACTION    = 'bcc_reaction';
    public const REVIEW      = 'bcc_review';
    public const CARD_PULLED = 'bcc_card_pulled';
    public const RANK_UP     = 'bcc_rank_up';
    public const ENDORSE     = 'bcc_endorse';
    public const WELCOME     = 'bcc_welcome';
    public const MENTION     = 'bcc_mention';
    public const LOCAL_POST  = 'bcc_local_post';

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
        self::CARD_PULLED,
        self::RANK_UP,
        self::ENDORSE,
        self::WELCOME,
        self::MENTION,
        self::LOCAL_POST,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
