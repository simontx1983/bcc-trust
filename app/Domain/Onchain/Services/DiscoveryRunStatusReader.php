<?php

declare(strict_types=1);

/**
 * The pollable status read model PR 7 will consume.
 *
 * ── IT WRITES NOTHING ───────────────────────────────────────────────────
 * No state change, no transient, no touch-write, no provider call. A poll
 * loop must be free: the moment reading costs a write, a status widget
 * becomes a load generator.
 *
 * `pickup_overdue` is the reason that rule needs stating. It is DERIVED at
 * read time from `requested_at` — never stored, never written when
 * observed. A queued run that nobody has collected is not broken and must
 * not be marked as anything; the flag simply lets an operator see it is
 * waiting rather than running.
 *
 * ── WHAT IT DELIBERATELY DOES NOT EXPOSE ────────────────────────────────
 * No lease token (a capability, not a fact), no host, no raw error text,
 * no SQL. Every explanatory field is a closed vocabulary.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunStatus;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type DiscoveryRunRow from DiscoveryRunRepository
 */
final class DiscoveryRunStatusReader
{
    /**
     * Status for one chain and job kind.
     *
     * @return array<string, mixed>
     */
    public static function forChain(int $chainId, string $jobKind = DiscoveryJobKind::COSMWASM_DISCOVERY): array
    {
        if ($chainId <= 0 || !DiscoveryJobKind::isValid($jobKind)) {
            return ['ok' => false, 'reason' => 'unknown_target'];
        }

        $chain = ChainRepository::getById($chainId);

        // The run an operator is looking at: the active one if there is one,
        // otherwise the most recent. Falling back matters — after a run
        // finishes there is no active row, and showing nothing would read as
        // "your scan vanished".
        $current = DiscoveryRunRepository::findActive($jobKind, $chainId)
            ?? DiscoveryRunRepository::findLatest($jobKind, $chainId);

        return [
            'ok'         => true,
            'chain_id'   => $chainId,
            'chain_slug' => $chain !== null ? (string) ($chain->slug ?? '') : '',
            'job_kind'   => $jobKind,
            'current'    => $current !== null ? self::present($current) : null,
            'last_succeeded' => self::presentOrNull(
                DiscoveryRunRepository::findLatestByStatus($jobKind, $chainId, DiscoveryRunStatus::SUCCEEDED)
            ),
            'last_failed' => self::presentOrNull(
                DiscoveryRunRepository::findLatestByStatus($jobKind, $chainId, DiscoveryRunStatus::FAILED)
            ),
        ];
    }

    /**
     * @param DiscoveryRunRow|null $row
     * @return array<string, mixed>|null
     */
    private static function presentOrNull(?object $row): ?array
    {
        return $row === null ? null : self::present($row);
    }

    /**
     * Shape one run for the wire.
     *
     * @param DiscoveryRunRow $row
     * @return array<string, mixed>
     */
    private static function present(object $row): array
    {
        $status   = (string) $row->status;
        $attempts = (int) $row->attempt_count;

        return [
            'run_uuid'       => (string) $row->run_uuid,
            'status'         => $status,
            'scan_mode'      => (string) $row->scan_mode,
            'requested_at'   => self::utc($row->requested_at ?? null),
            'started_at'     => self::utc($row->started_at ?? null),
            'finished_at'    => self::utc($row->finished_at ?? null),
            'attempt_count'  => $attempts,
            'max_attempts'   => DiscoveryRunRepository::MAX_ATTEMPTS,
            'stop_reason'    => self::nullableString($row->stop_reason ?? null),
            'error_code'     => self::nullableString($row->error_code ?? null),
            'partial'        => (int) $row->partial === 1,
            'audit_degraded' => (int) $row->audit_degraded === 1,
            'pickup_overdue' => self::pickupOverdue($status, $row->requested_at ?? null),
            'retry_allowed'  => DiscoveryRunStatus::isTerminal($status)
                && $status !== DiscoveryRunStatus::CANCELLED,
            'counts'         => [
                'requests_used'       => (int) $row->requests_used,
                'pages_fetched'       => (int) $row->pages_fetched,
                'families_seen'       => (int) $row->families_seen,
                'contracts_seen'      => (int) $row->contracts_seen,
                'collections_emitted' => (int) $row->collections_emitted,
                'collections_denied'  => (int) $row->collections_denied,
            ],
        ];
    }

    /**
     * Has a queued run been waiting longer than the grace period?
     *
     * PURE, and derived only. Nothing is written when this returns true —
     * that is the whole point. It is also scoped to `queued`: a RUNNING run
     * is being worked on, however long it has taken, and flagging it here
     * would send an operator chasing a healthy pass.
     *
     * @param mixed $requestedAt
     */
    private static function pickupOverdue(string $status, $requestedAt): bool
    {
        if ($status !== DiscoveryRunStatus::QUEUED) {
            return false;
        }

        if (!is_string($requestedAt) || trim($requestedAt) === '') {
            return false;
        }

        $ts = strtotime($requestedAt . ' UTC');
        if ($ts === false) {
            return false;
        }

        return (time() - $ts) > DiscoveryRunRepository::PICKUP_GRACE_SECONDS;
    }

    /** @param mixed $value */
    private static function utc($value): ?string
    {
        if (!is_string($value) || trim($value) === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return str_replace(' ', 'T', $value) . 'Z';
    }

    /** @param mixed $value */
    private static function nullableString($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
