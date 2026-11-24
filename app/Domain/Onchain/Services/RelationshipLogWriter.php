<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Core\DB\AdvisoryLock;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Onchain\Repositories\ValidatorRelationshipLogRepository as Log;
use BCC\Trust\Onchain\Support\RelationshipLogAnchor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Appends a STANDALONE event to the validator relationship log: one that
 * records something that happened without a relationship change of its own
 * (a failed or expired staff step-up). Changes that write claim rows append
 * inside their own transaction instead (ValidatorRelationshipService).
 *
 * Lock order, as on every path: GET_LOCK bcc_vrl_append → START TRANSACTION
 * → append → COMMIT → advance the anchor → RELEASE, the release in finally.
 * The anchor moves only after a successful COMMIT and while the vrl lock is
 * still held, so appends cannot move it out of order. A lock timeout or any
 * failure writes nothing, records a metric and returns false: a log row
 * about a failure must never turn into an error for the caller.
 *
 * @package BCC\Trust\Onchain\Services
 */
final class RelationshipLogWriter
{
    private const LOCK_TIMEOUT = 5;

    /**
     * @param array{action: string, page_id: int, subject_user_id: int|null, actor_kind: string, actor_user_id: int|null, payload: array<mixed>} $event
     */
    public static function appendStandalone(array $event): bool
    {
        if (!AdvisoryLock::acquire(Log::LOCK_NAME, self::LOCK_TIMEOUT)) {
            \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'log_lock_unavailable');
            return false;
        }
        try {
            try {
                /** @var array{id: int, seq: int, row_hash: string} $appended */
                $appended = TransactionManager::run(static fn (): array => Log::append($event));
            } catch (\Throwable $e) {
                \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'log_append_failed');
                \BCC\Core\Log\Logger::error('[bcc-trust] relationship log: standalone append failed', [
                    'action' => $event['action'],
                    'error'  => $e->getMessage(),
                ]);
                return false;
            }

            // Committed; the vrl lock is still held.
            RelationshipLogAnchor::advance($appended['seq'], $appended['row_hash']);

            return true;
        } finally {
            AdvisoryLock::release(Log::LOCK_NAME);
        }
    }
}
