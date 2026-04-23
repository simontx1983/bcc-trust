<?php

namespace BCC\Trust\Core\Application;

use BCC\Core\Contracts\RecalcQueueReadInterface;
use BCC\Trust\Core\Repositories\ScoreRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-side adapter for the score-recalculation queue.
 *
 * Implements the bcc-core contract so the health dashboard can surface
 * pending-recalc queue depth without reaching into Trust-domain tables.
 * Returns 0 on any failure — the health endpoint MUST NOT cascade into
 * a platform-wide outage.
 */
final class RecalcQueueReadService implements RecalcQueueReadInterface
{
    public function __construct(private readonly ScoreRepository $scoreRepository)
    {
    }

    public function pendingCount(): int
    {
        try {
            return $this->scoreRepository->countPendingRecalc();
        } catch (\Throwable $e) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] RecalcQueueReadService::pendingCount failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            return 0;
        }
    }
}