<?php
/**
 * Push Subscription Repository
 *
 * CRUD for the bcc_push_subscriptions table — VAPID subscriptions
 * per user/device for V2 Phase 1 web push.
 *
 * Per §P1.D1 (no parallel framework) this is the only place the
 * subscriptions table is read or written. The PushDispatcher consumes
 * the repo; nothing else.
 *
 * Endpoints are matched by SHA-256 hash to keep the unique index
 * dbDelta-safe across MySQL row formats — see schema-push-subscriptions.php.
 *
 * @package BCC\Trust\Core\Repositories
 * @since V2 Phase 1
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type PushSubscriptionRow object{
 *     id: numeric-string,
 *     user_id: numeric-string,
 *     endpoint: string,
 *     endpoint_hash: string,
 *     p256dh_key: string,
 *     auth_key: string,
 *     user_agent: string|null,
 *     created_at: string,
 *     last_used_at: string|null
 * }
 */
final class PushSubscriptionRepository
{
    /** Explicit column list — must match schema-push-subscriptions.php. */
    private const COLUMNS = 'id, user_id, endpoint, endpoint_hash, p256dh_key, auth_key, user_agent, created_at, last_used_at';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::pushSubscriptions();
    }

    /**
     * Insert a new subscription, or refresh an existing one identified
     * by (user_id, endpoint_hash). Returns the row id on success.
     *
     * "Refresh" semantics: if a subscription with the same endpoint
     * already exists for this user, we update the keys + user_agent +
     * last_used_at instead of inserting a duplicate. This handles the
     * `pushsubscriptionchange` browser event where a stale subscription
     * is rotated to a new key set under the same endpoint.
     *
     * Returns 0 on DB error, and when another registration of the same
     * endpoint holds its lock past LOCK_WAIT_SECONDS.
     *
     * F-76 HANDOVER: an endpoint is ONE browser profile, so it belongs to
     * exactly one user, the one who registered it last; every other user's
     * row for it is dropped. That lets whoever registers an endpoint evict
     * its previous holder. Acceptable: a push endpoint is an unguessable
     * capability URL issued by the browser's push service, so only someone
     * holding that browser's subscription can present it.
     */
    public function upsert(
        int $userId,
        string $endpoint,
        string $p256dhKey,
        string $authKey,
        ?string $userAgent
    ): int {
        if ($userId <= 0 || $endpoint === '' || $p256dhKey === '' || $authKey === '') {
            return 0;
        }

        $hash = self::hashEndpoint($endpoint);

        // Serialise registrations of the SAME endpoint. Unlocked, two
        // accounts registering it at the same instant each deleted the
        // other's (not yet written) row, then each inserted its own: two
        // owners, the leak the handover exists to close. A lock that cannot
        // be had refuses the registration, the same fail-closed rule as a
        // failed handover delete; the client retries on its next load.
        $lock = self::lockKey($hash);
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lock, self::LOCK_WAIT_SECONDS)) {
            return 0;
        }
        try {
            return $this->upsertLocked($userId, $endpoint, $hash, $p256dhKey, $authKey, $userAgent);
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock);
        }
    }

    /** Seconds a registration waits for another registration of the same endpoint. */
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * The per-endpoint lock name. GET_LOCK names are capped at 64 characters,
     * so it uses a 40-hex prefix of the endpoint hash; a prefix collision
     * only serialises two registrations, it never merges them.
     */
    public static function lockKey(string $endpointHash): string
    {
        return 'bcc_push_ep_' . substr($endpointHash, 0, 40);
    }

    /** upsert() body; the caller holds the endpoint's lock. */
    private function upsertLocked(
        int $userId,
        string $endpoint,
        string $hash,
        string $p256dhKey,
        string $authKey,
        ?string $userAgent
    ): int {
        global $wpdb;
        $now = current_time('mysql', true);

        // F-76: hand the endpoint over (see upsert()). The UNIQUE key is per
        // (user_id, endpoint_hash), so without this a browser that changed
        // hands (the previous account's logout cleanup failed or never ran:
        // an expired session, cleared cookies) kept the old account's row,
        // and that account's pushes reached the new person's device. A failed
        // delete refuses the registration rather than leave the leak in place.
        $handedOver = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE endpoint_hash = %s AND user_id <> %d",
            $hash,
            $userId
        ));
        if ($handedOver === false) {
            return 0;
        }

        // Existing row check — UPDATE if present, INSERT if not.
        $existing = $this->findByUserAndHash($userId, $hash);
        if ($existing !== null) {
            $updated = $wpdb->update(
                $this->table,
                [
                    'p256dh_key'   => $p256dhKey,
                    'auth_key'     => $authKey,
                    'user_agent'   => $userAgent,
                    'last_used_at' => $now,
                ],
                [
                    'id' => (int) $existing->id,
                ],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
            if ($updated === false) {
                return 0;
            }
            return (int) $existing->id;
        }

        $inserted = $wpdb->insert(
            $this->table,
            [
                'user_id'       => $userId,
                'endpoint'      => $endpoint,
                'endpoint_hash' => $hash,
                'p256dh_key'    => $p256dhKey,
                'auth_key'      => $authKey,
                'user_agent'    => $userAgent,
                'created_at'    => $now,
                'last_used_at'  => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Find a single subscription by id. Used by the DELETE endpoint
     * for the auth check before deletion.
     *
     * @phpstan-return PushSubscriptionRow|null
     */
    public function find(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        global $wpdb;

        /** @var PushSubscriptionRow|null */
        return $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE id = %d LIMIT 1",
            $id
        ));
    }

    /**
     * Find an existing subscription for (user_id, endpoint_hash).
     * Used by upsert() to decide INSERT vs UPDATE.
     *
     * @phpstan-return PushSubscriptionRow|null
     */
    public function findByUserAndHash(int $userId, string $endpointHash): ?object
    {
        if ($userId <= 0 || $endpointHash === '') {
            return null;
        }

        global $wpdb;

        /** @var PushSubscriptionRow|null */
        return $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
             WHERE user_id = %d AND endpoint_hash = %s
             LIMIT 1",
            $userId,
            $endpointHash
        ));
    }

    /**
     * List all active subscriptions for a user. Used by the dispatcher
     * to fan a single push out to every device the user has registered.
     *
     * Bounded — no user should realistically exceed ~10 devices; cap
     * at 25 to stop a runaway-registration bug from taking the cron down.
     *
     * @phpstan-return list<PushSubscriptionRow>
     */
    public function findAllForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        global $wpdb;

        /** @var list<PushSubscriptionRow>|null */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
             WHERE user_id = %d
             ORDER BY id DESC
             LIMIT 25",
            $userId
        ));

        return is_array($rows) ? $rows : [];
    }

    /**
     * Delete one subscription. Returns true when a row was actually
     * removed (auth + existence check pre-passed).
     *
     * Caller is responsible for verifying the subscription belongs to
     * the current user before invoking — repository does no auth.
     */
    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        global $wpdb;
        $deleted = $wpdb->delete($this->table, ['id' => $id], ['%d']);
        return is_int($deleted) && $deleted > 0;
    }

    /**
     * Bulk-delete every subscription for a user. Called when the user
     * flips the master push toggle from ON to OFF — server-side cleanup
     * so we stop dispatching to dead endpoints — and on account deletion.
     *
     * F-76: also the call for every "kick everyone out" credential
     * revocation (logout-everywhere and password change), so a revoked
     * device stops receiving that account's pushes. NOT on a routine
     * email-change verify. The frontend re-subscribes on the next opted-in
     * login.
     *
     * Returns the number of rows deleted; 0 also on a DB error, which is
     * logged — callers cutting off revoked devices would otherwise read a
     * failure as "nothing to delete" and keep pushing to them.
     */
    public function deleteAllForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        global $wpdb;
        $deleted = $wpdb->delete($this->table, ['user_id' => $userId], ['%d']);
        if (!is_int($deleted)) {
            \BCC\Core\Log\Logger::error('[bcc-trust] push subscriptions not deleted', [
                'user_id' => $userId,
                'error'   => (string) ($wpdb->last_error ?? ''),
            ]);
            return 0;
        }
        return $deleted;
    }

    /**
     * Touch last_used_at after a successful dispatch. Used by the
     * PushDispatcher to keep the freshness signal accurate; rows with
     * stale last_used_at + repeated 410s are candidates for a future
     * GC sweep.
     */
    public function touchLastUsed(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        global $wpdb;
        $wpdb->update(
            $this->table,
            ['last_used_at' => current_time('mysql', true)],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Stable endpoint identity — SHA-256 over the raw endpoint URL.
     * Public so the dispatcher's 410-tombstone path can re-derive the
     * hash from a known endpoint without round-tripping through a row.
     */
    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
