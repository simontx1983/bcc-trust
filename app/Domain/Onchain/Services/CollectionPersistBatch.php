<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\CollectionRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persist a fetched batch of collections for one wallet and report how it went.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * Three call sites — wallet seeding, the 4-hourly refresh cron, and the
 * gallery-refresh task — each loop `CollectionRepository::upsert()` over a
 * fetched batch. Before #212 all three DISCARDED the return value, which is
 * precisely why thousands of rejected writes stayed invisible for months.
 * Handling that result identically in three places invites the same drift
 * back, so the policy lives here once.
 *
 * It also makes the outcome testable. The gallery-refresh caller must not
 * advance its "holdings refreshed" watermark when a write was lost — a
 * partial or total failure has to stay eligible for retry — and that
 * decision needs a value it can branch on.
 *
 * Deliberately NOT a repository: this orchestrates writes and counts
 * outcomes, it owns no SQL of its own.
 */
final class CollectionPersistBatch
{
    /**
     * Upsert every row, counting outcomes. Never throws — a failure is data,
     * not an exception, and the caller decides what it means.
     *
     * @param array<int, array<string, mixed>> $collections Normalized fetcher rows.
     * @param int $walletLinkId Wallet link that observed them.
     * @param int $ttlSeconds   TTL passed through to each upsert.
     * @return array{total: int, created: int, updated: int, failed: int}
     */
    public static function persist(array $collections, int $walletLinkId, int $ttlSeconds): array
    {
        $result = ['total' => count($collections), 'created' => 0, 'updated' => 0, 'failed' => 0];

        foreach ($collections as $collection) {
            if (!is_array($collection)) {
                $result['failed']++;
                continue;
            }

            $status = CollectionRepository::upsert($collection, $walletLinkId, $ttlSeconds)['status'];

            if ($status === 'created' || $status === 'updated') {
                $result[$status]++;
                continue;
            }

            $result['failed']++;
        }

        return $result;
    }

    /**
     * TRUE only when every row in the batch reached the database.
     *
     * An empty batch counts as fully persisted: there was nothing to lose, so
     * a wallet that genuinely holds no collections must not be pinned in a
     * permanent retry loop.
     *
     * @param array{total: int, created: int, updated: int, failed: int} $result
     */
    public static function allPersisted(array $result): bool
    {
        return $result['failed'] === 0;
    }
}
