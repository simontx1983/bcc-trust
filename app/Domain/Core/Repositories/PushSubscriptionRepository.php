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
     * Returns 0 on DB error.
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

        global $wpdb;
        $hash = self::hashEndpoint($endpoint);
        $now  = current_time('mysql', true);

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
     * so we stop dispatching to dead endpoints.
     *
     * Returns the number of rows deleted.
     */
    public function deleteAllForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        global $wpdb;
        $deleted = $wpdb->delete($this->table, ['user_id' => $userId], ['%d']);
        return is_int($deleted) ? $deleted : 0;
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
