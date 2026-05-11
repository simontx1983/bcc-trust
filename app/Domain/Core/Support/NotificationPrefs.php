<?php
/**
 * NotificationPrefs — central reader/writer for §I1 notification toggles.
 *
 * Two surfaces controlled by these flags:
 *
 *   - bell_<type>     — gate per-event in NotificationDispatcher::dispatch.
 *                       When false, the dispatcher skips writing to
 *                       peepso_notifications for that event type. Default
 *                       true (V1 baseline: all bell events on).
 *   - email_digest    — opt in to the weekly email digest cron. Default
 *                       false (§I1: "off by default, opt-in").
 *
 * Storage mirrors PrivacySettings:
 *   - One wp_usermeta row per flag, namespaced `bcc_notif_pref_*`
 *   - Stored as "1" / "0" strings (FILTER_VALIDATE_BOOLEAN-clean)
 *   - Missing rows fall back to per-flag default
 *
 * Why per-key rather than a single JSON blob: matches the existing
 * PrivacySettings idiom, supports atomic single-flag writes (the
 * unsubscribe endpoint flips just `email_digest` without touching
 * bell), and keeps wpdb queries trivial.
 *
 * @package BCC\Trust\Core\Support
 * @since V1.5 (§I1 notification preferences)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class NotificationPrefs
{
    /**
     * §I1 bell event types — must match NotificationType::ALL.
     * Listed here explicitly so the schema doesn't drift if
     * NotificationType gains internal-only types later.
     *
     * @var list<string>
     */
    public const BELL_TYPES = [
        NotificationType::REACTION,
        NotificationType::REVIEW,
        NotificationType::CARD_PULLED,
        NotificationType::RANK_UP,
        NotificationType::ENDORSE,
        NotificationType::WELCOME,
        NotificationType::MENTION,
    ];

    /**
     * V2 Phase 1 push event types — intentionally narrower than the
     * bell taxonomy (per the locked plan: "bell shows everything;
     * push is 'you really need to know'"). Identifiers are short
     * forms since they're scoped to the `push.events` sub-object;
     * the dispatcher maps them to the underlying source hooks.
     *
     * @var list<string>
     */
    public const PUSH_TYPES = [
        'review',
        'endorse',
        'dispute_outcome',
        'panelist_selected',
        'mention',
    ];

    /** wp_usermeta key prefix. Renaming requires a migration. */
    private const META_PREFIX = 'bcc_notif_pref_';

    /**
     * Per-flag defaults.
     *   - email_digest off (§I1 V1 baseline: opt-in)
     *   - all bell events on (§I1 V1 baseline)
     *   - push master OFF until the user explicitly enables it (a
     *     browser permission prompt fires; no silent dispatch)
     *   - all push events ON when push is first enabled (P1.C3 —
     *     anti-noise carries the load via debounce + aggregation)
     */
    private const DEFAULTS = [
        'email_digest'                     => false,
        'bell_bcc_reaction'                => true,
        'bell_bcc_review'                  => true,
        'bell_bcc_card_pulled'             => true,
        'bell_bcc_rank_up'                 => true,
        'bell_bcc_endorse'                 => true,
        'bell_bcc_welcome'                 => true,
        'bell_bcc_mention'                 => true,
        'push_master'                      => false,
        'push_event_review'                => true,
        'push_event_endorse'               => true,
        'push_event_dispute_outcome'       => true,
        'push_event_panelist_selected'     => true,
        'push_event_mention'               => true,
    ];

    /**
     * Read every flag for the GET /me/notification-prefs response shape.
     *
     * @return array{
     *   email_digest: bool,
     *   bell: array<string, bool>,
     *   push: array{enabled: bool, events: array<string, bool>}
     * }
     */
    public static function readAll(int $userId): array
    {
        $bell = [];
        foreach (self::BELL_TYPES as $type) {
            $bell[$type] = self::flag($userId, 'bell_' . $type);
        }
        $pushEvents = [];
        foreach (self::PUSH_TYPES as $type) {
            $pushEvents[$type] = self::flag($userId, 'push_event_' . $type);
        }
        return [
            'email_digest' => self::flag($userId, 'email_digest'),
            'bell'         => $bell,
            'push'         => [
                'enabled' => self::flag($userId, 'push_master'),
                'events'  => $pushEvents,
            ],
        ];
    }

    /**
     * Bell-pref gate used by NotificationDispatcher::dispatch. Returns
     * true when the user wants this event type in their bell. Defaults
     * to true on missing meta (§I1 V1 baseline).
     */
    public static function isBellEnabled(int $userId, string $eventType): bool
    {
        if ($userId <= 0) {
            return true; // fail-open for system-side dispatch (no recipient context)
        }
        if (!in_array($eventType, self::BELL_TYPES, true)) {
            // Unknown type — write through. Future event types should
            // be added to BELL_TYPES before their dispatcher subscribes.
            return true;
        }
        return self::flag($userId, 'bell_' . $eventType);
    }

    /**
     * Email-digest opt-in gate used by the weekly cron.
     */
    public static function isEmailDigestEnabled(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return self::flag($userId, 'email_digest');
    }

    /**
     * Push-pref gate used by the V2 Phase 1 PushDispatcher::enqueue.
     * Returns true only when BOTH (master ON) AND (per-event ON). An
     * unknown event type fails closed so a runaway dispatcher subscriber
     * can never trigger a push for an event the user couldn't toggle.
     */
    public static function isPushEnabled(int $userId, string $eventType): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if (!in_array($eventType, self::PUSH_TYPES, true)) {
            return false;
        }
        if (!self::flag($userId, 'push_master')) {
            return false;
        }
        return self::flag($userId, 'push_event_' . $eventType);
    }

    /**
     * Master-toggle gate. Used by the subscription-registration endpoint
     * to refuse new device registrations when the user has push disabled
     * at the account level.
     */
    public static function isPushMasterEnabled(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return self::flag($userId, 'push_master');
    }

    /**
     * Atomic single-flag setter for the master toggle. Used by the
     * subscription-registration endpoint to flip push_master = true on
     * first successful subscribe, and by the master-disable path to
     * flip it back to false alongside the subscription teardown.
     */
    public static function setPushMaster(int $userId, bool $enabled): void
    {
        if ($userId <= 0) {
            return;
        }
        self::writeFlag($userId, 'push_master', $enabled);
    }

    /**
     * Write a partial set of flags. Accepts the nested shape
     * `{email_digest, bell: {...}, push: {enabled, events: {...}}}` —
     * unknown keys silently dropped.
     *
     * @param array{
     *   email_digest?: bool,
     *   bell?: array<string, bool>,
     *   push?: array{enabled?: bool, events?: array<string, bool>}
     * } $partial
     */
    public static function writePartial(int $userId, array $partial): void
    {
        if ($userId <= 0) {
            return;
        }

        if (array_key_exists('email_digest', $partial)) {
            self::writeFlag($userId, 'email_digest', (bool) $partial['email_digest']);
        }

        if (isset($partial['bell']) && is_array($partial['bell'])) {
            foreach ($partial['bell'] as $type => $value) {
                if (!is_string($type) || !in_array($type, self::BELL_TYPES, true)) {
                    continue;
                }
                self::writeFlag($userId, 'bell_' . $type, (bool) $value);
            }
        }

        if (isset($partial['push']) && is_array($partial['push'])) {
            $push = $partial['push'];
            if (array_key_exists('enabled', $push)) {
                self::writeFlag($userId, 'push_master', (bool) $push['enabled']);
            }
            if (isset($push['events']) && is_array($push['events'])) {
                foreach ($push['events'] as $type => $value) {
                    if (!is_string($type) || !in_array($type, self::PUSH_TYPES, true)) {
                        continue;
                    }
                    self::writeFlag($userId, 'push_event_' . $type, (bool) $value);
                }
            }
        }
    }

    /**
     * Atomic single-flag setter. Used by the unsubscribe endpoint to
     * flip just `email_digest` without round-tripping through the full
     * partial-write surface.
     */
    public static function setEmailDigest(int $userId, bool $enabled): void
    {
        if ($userId <= 0) {
            return;
        }
        self::writeFlag($userId, 'email_digest', $enabled);
    }

    /**
     * @return list<int> wp_user IDs with email_digest = true. Used by
     *   the weekly digest cron to enumerate recipients without scanning
     *   the full users table.
     */
    public static function findEmailDigestOptIns(int $limit = 5000): array
    {
        global $wpdb;
        $key = self::META_PREFIX . 'email_digest';

        /** @var list<numeric-string>|null $rows */
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
              WHERE meta_key = %s AND meta_value = %s
              LIMIT %d",
            $key,
            '1',
            $limit
        ));

        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $value) {
            $userId = (int) $value;
            if ($userId > 0) {
                $out[] = $userId;
            }
        }
        return $out;
    }

    /**
     * Per-flag default lookup (used by readAll + flag()).
     */
    private static function defaultFor(string $key): bool
    {
        return self::DEFAULTS[$key] ?? false;
    }

    /**
     * Read one flag, applying the per-flag default when no meta row exists.
     */
    private static function flag(int $userId, string $key): bool
    {
        if ($userId <= 0) {
            return self::defaultFor($key);
        }
        // get_user_meta returns "" when the row is absent — distinct from
        // an explicit "0" write. Apply the default only on absence.
        $raw = get_user_meta($userId, self::META_PREFIX . $key, true);
        if ($raw === '' || $raw === false || $raw === null) {
            return self::defaultFor($key);
        }
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    private static function writeFlag(int $userId, string $key, bool $value): void
    {
        update_user_meta($userId, self::META_PREFIX . $key, $value ? '1' : '0');
    }
}
