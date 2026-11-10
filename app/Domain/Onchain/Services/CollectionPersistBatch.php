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
 * Every call site loops `CollectionRepository::upsert()` over a fetched
 * batch, and before #212 each one DISCARDED the return value — which is
 * precisely why thousands of rejected writes stayed invisible for months.
 * Handling that result identically in several places invites the same
 * drift back, so the policy lives here once. #221 put the DegradationMetrics
 * recording here for the same reason: a future caller cannot forget it.
 *
 * ⚠ TWO CALLERS, NOT THREE. Measured on `main` 2026-11-10:
 *   - `WalletSeedService::seedEntities()`     (wallet seeding)
 *   - `ChainRefreshService` refresh tick      (the 4-hourly cron)
 * An earlier version of this docblock named a third, "the gallery-refresh
 * task", and bcc-trust #221 inherited that claim from here. There is no
 * `CollectionPersistBatch` reference in `Plugin.php` or anywhere else.
 *
 * ⚠⚠ AND THE WATERMARK CLAIM WAS WRONG TOO. This docblock used to say the
 * gallery-refresh caller must not advance its "holdings refreshed"
 * watermark when a write was lost. No caller does that. The only
 * withholding of `WalletRepository::markHoldingsRefreshed()` is in
 * `HoldingsService` (the `!$complete` branch), and it turns on an
 * INCOMPLETE PROVIDER WALK — a holdings-read concern that never sees a
 * persist batch. What actually keeps a lost row retryable is narrower and
 * real: a failed `upsert()` never advances that row's `expires_at`, so the
 * refresh cron, which selects on `expires_at < NOW()`, picks it up again.
 *
 * The return value is still what makes the outcome testable, and both
 * callers branch on `allPersisted()` to decide whether to log.
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

        // ── #221: turn the loss into a HEALTH signal ────────────────────
        //
        // Both callers already log, and #212 made the counts honest, but a
        // log line is not a health signal: nothing aggregates `bcc-logs/`,
        // so the Golden Paths surface stayed green while writes were being
        // dropped. Subsystem and event names are the canonical ones from
        // bcc-core's DegradationMetric map (bcc-core #61);
        // CollectionPersistMetricsTest reconciles them against that file so
        // a rename on either side cannot pass silently.
        if ($result['failed'] > 0) {
            \BCC\Core\Observability\DegradationMetrics::record('collection_persist', 'write_failed');

            // The sharper signal: the wallet made NO progress at all, which
            // usually means the table or the connection rather than the
            // data, and wants a different response from partial loss.
            //
            // #221 specifies `failed === total && total > 0`. The second
            // half is already implied and is deliberately NOT re-tested:
            // `failed > 0` cannot hold on an empty batch, so an empty batch
            // never reaches this branch. Pinned by
            // testAnEmptyBatchRecordsNothing so the reasoning cannot rot.
            if ($result['failed'] === $result['total']) {
                \BCC\Core\Observability\DegradationMetrics::record('collection_persist', 'batch_total_failure');
            }
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
