<?php
/**
 * Community Ownership Log Repository — owns the append-only custody
 * ledger `wp_bcc_trust_community_ownership_log` (Rank Phase 7, §21.2).
 *
 * Writers: MyGroupsEndpoint::postCreate ('create') and
 * CommunityCustodyService::transfer ('transfer_out' + 'receive') only.
 * Rows are pure INSERTs — never updated, never deleted — because the
 * 30-day global custody cooldown is live-computed from MAX(created_at)
 * per user (same live-computed-cooldown posture as
 * HandleService::cooldownExpiresAt).
 *
 * INTENTIONALLY UNCACHED (no §5 generation counter): the cooldown is a
 * custody security gate and the read is a single index-backed row on
 * idx_user_created — a stale cached answer would let a user re-arm
 * around their cooldown, and the query cost doesn't justify a cache.
 *
 * @package BCC\Trust\Rank\Repositories
 * @since Rank redesign Phase 7 (2026-08-04)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

class CommunityOwnershipLogRepository
{
    /** The only custody events the ledger accepts. */
    public const EVENT_CREATE       = 'create';
    public const EVENT_RECEIVE      = 'receive';
    public const EVENT_TRANSFER_OUT = 'transfer_out';

    /** @var list<string> */
    private const EVENTS = [
        self::EVENT_CREATE,
        self::EVENT_RECEIVE,
        self::EVENT_TRANSFER_OUT,
    ];

    // No full-row COLUMNS projection yet — the only reads are the
    // single-column cooldown lookup below. Add an explicit COLUMNS
    // const (§2) with the first row-shape reader.

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::communityOwnershipLog();
    }

    /**
     * Append one custody event. `created_at` is UTC computed in PHP
     * (gmdate) — never MySQL NOW(), whose session time zone is not UTC
     * on this host. `$counterpartyUserId` is the other party of a
     * transfer (null for 'create').
     *
     * @return bool False on invalid input or DB error — callers treat
     *              a failure as non-fatal for the group write itself
     *              but MUST Logger::error it (the cooldown did not arm).
     */
    public function record(int $userId, int $groupId, string $event, ?int $counterpartyUserId): bool
    {
        if ($userId <= 0 || $groupId <= 0 || !in_array($event, self::EVENTS, true)) {
            return false;
        }

        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');

        if ($counterpartyUserId !== null && $counterpartyUserId > 0) {
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$this->table}
                    (user_id, group_id, event, counterparty_user_id, created_at)
                 VALUES (%d, %d, %s, %d, %s)",
                $userId,
                $groupId,
                $event,
                $counterpartyUserId,
                $now
            ));
        } else {
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$this->table}
                    (user_id, group_id, event, counterparty_user_id, created_at)
                 VALUES (%d, %d, %s, NULL, %s)",
                $userId,
                $groupId,
                $event,
                $now
            ));
        }

        return $result !== false && (int) $result > 0;
    }

    /**
     * The user's most recent custody event timestamp (UTC MySQL
     * datetime), or null when they have never created / received /
     * transferred a community. Single row off idx_user_created —
     * bounded by construction. Drives the §21.2 30-day global cooldown
     * in CapabilityResolver::custodyState.
     */
    public function lastCustodyEventAt(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT created_at FROM {$this->table}
              WHERE user_id = %d
              ORDER BY created_at DESC, id DESC
              LIMIT 1",
            $userId
        ));

        return is_string($value) && $value !== '' ? $value : null;
    }
}
