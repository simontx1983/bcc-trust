<?php
/**
 * Watch-batch body hydration for the §F3 feed brain.
 *
 * Extracted verbatim from FeedRankingService (Phase 3.2 split): the
 * per-kind `watch_batch` body loader. FeedRankingService remains the
 * orchestrator — it buckets feed items by post_kind and delegates the
 * sidecar reads here.
 *
 * @package BCC\Trust\Core\Services\Feed
 */

namespace BCC\Trust\Core\Services\Feed;

use BCC\Trust\Core\Repositories\WatchBatchRepository;
use BCC\Trust\Core\Repositories\WatchMetaRepository;
use BCC\Trust\Core\Repositories\WatchingRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class WatchBatchBodyHydrator
{
    /** §C3 cap for watch_batch top_cards display. */
    private const TOP_CARDS_DISPLAY = 3;

    public function __construct(
        private readonly WatchBatchRepository $watchBatchRepo,
        private readonly WatchMetaRepository $watchMetaRepo,
        private readonly WatchingRepository $watchingRepo
    ) {
    }

    /**
     * Bulk-load watch_batch bodies: snapshot + top 3 card handles per
     * batch. Returns map keyed by bcc_watch_batches.id.
     *
     * Frozen-history rule (§C3): card_count and more_count come from
     * the bcc_watch_batches snapshot taken at emit time, NOT a live
     * COUNT(*) on bcc_watch_meta — subsequent unwatches don't shift the
     * displayed numbers.
     *
     * @param list<int> $batchRowIds
     * @return array<int, array<string, mixed>>
     */
    public function loadWatchBatchBodies(array $batchRowIds): array
    {
        if ($batchRowIds === []) {
            return [];
        }

        $batches = $this->watchBatchRepo->findManyByIds($batchRowIds);
        if ($batches === []) {
            return [];
        }

        // Load all member rows for these batches in one query.
        $batchIds = [];
        foreach ($batches as $batch) {
            $batchIds[] = (string) $batch->batch_id;
        }
        $membersByBatchId = $this->watchMetaRepo->findManyByBatchIds($batchIds);

        // Collect top-3 follow_ids per batch + dedupe across batches
        // for one bulk handle lookup.
        $topFollowIdsPerBatch = [];
        $allFollowIds = [];
        foreach ($membersByBatchId as $batchId => $members) {
            $top = array_slice($members, 0, self::TOP_CARDS_DISPLAY);
            $topIds = [];
            foreach ($top as $row) {
                $followId = (int) $row->follow_id;
                $topIds[] = $followId;
                $allFollowIds[$followId] = true;
            }
            $topFollowIdsPerBatch[$batchId] = $topIds;
        }
        $handleMap = $this->watchingRepo->findHandlesForFollowIds(array_keys($allFollowIds));

        // Compose bodies indexed by bcc_watch_batches.id (matches
        // act_external_id at the call site).
        $bodies = [];
        foreach ($batches as $internalId => $batch) {
            $batchIdStr = (string) $batch->batch_id;
            $topIds     = $topFollowIdsPerBatch[$batchIdStr] ?? [];
            $topCards   = [];
            foreach ($topIds as $fid) {
                $topCards[] = [
                    'follow_id'   => $fid,
                    'card_handle' => $handleMap[$fid] ?? '',
                ];
            }

            $bodies[$internalId] = [
                'batch_id'   => $batchIdStr,
                'card_count' => (int) $batch->card_count,
                'more_count' => (int) $batch->more_count,
                'top_cards'  => $topCards,
            ];
        }
        return $bodies;
    }
}
