<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Core\DB\AdvisoryLock;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Brings a validator page's PeepSo ownership (the page owner role,
 * post_author, the owner-keyed score rows) in line with what the CLAIMS say,
 * after a relationship change committed.
 *
 * It runs ONLY from cron (a single event a minute after the change, and a
 * bounded 15-minute sweep that retries), never inline in a staff request: a
 * page write from a logged-in editor would fire the page save handlers as
 * that editor. Every BCC authority check reads the claims, so until this
 * runs the only lag is in PeepSo's own surfaces.
 *
 * Rules (security's design check):
 *  - No parameters from the change: the page's desired owner is read FRESH
 *    from the claims, fail-closed. A failed read or an AMBIGUOUS page does
 *    nothing (a metric), never a demotion.
 *  - It reuses the existing writers. A promotion goes through
 *    PeepSoIntegration::onPageClaimed() (it only ever moves post_author FROM
 *    0, then ensures the PeepSo owner row and moves the score rows); a
 *    release goes through releaseClaimedPage() (it skips while any verified
 *    claim binds the page, skips a banned owner with an audit row, demotes
 *    the owner role, frees post_author, moves the score rows).
 *  - Just before releasing, the departing author is re-checked: if they
 *    still hold the page through any verified claim (or the read fails), the
 *    page is left alone.
 *  - A page whose author is someone the claims do not name (a manual
 *    reassignment, or a swap this step does not make) is left alone and
 *    reported (page_owner_misaligned), never overwritten.
 *  - One run per page at a time (a per-page advisory lock); a second run
 *    is a no-op. An aligned page appends an `owner_aligned` row.
 *
 * Pages still to align are kept in a bounded option, which the sweep
 * retries and the admin health view lists.
 *
 * @package BCC\Trust\Onchain\Services
 */
final class PageOwnershipAligner
{
    public const HOOK         = 'bcc_validator_page_owner_align';
    public const SWEEP_HOOK   = 'bcc_validator_page_owner_align_sweep';
    public const PENDING      = 'bcc_validator_pages_to_align';
    public const MAX_PENDING  = 200;
    public const SWEEP_BATCH  = 20;

    public const OUTCOME_ALIGNED    = 'aligned';
    public const OUTCOME_NOOP       = 'noop';
    public const OUTCOME_LOCKED     = 'locked';
    public const OUTCOME_UNKNOWN    = 'unknown';
    public const OUTCOME_MISALIGNED = 'misaligned';

    /** Queue $pageId: a single align event a minute from now, and the retry list. */
    public static function schedule(int $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }
        self::remember($pageId);
        $args = [$pageId];
        if (wp_next_scheduled(self::HOOK, $args) === false
            && wp_schedule_single_event(time() + 60, self::HOOK, $args) === false
        ) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] page ownership align could not be scheduled; the sweep will retry', ['page_id' => $pageId]);
        }
    }

    /** Cron: align one page. */
    public static function align(int $pageId): string
    {
        if ($pageId <= 0) {
            return self::OUTCOME_NOOP;
        }
        $lock = 'bcc_page_owner_align:' . $pageId;
        if (!AdvisoryLock::acquire($lock, 0)) {
            return self::OUTCOME_LOCKED; // another run has it; the sweep retries
        }
        try {
            $outcome = self::alignLocked($pageId);
        } finally {
            AdvisoryLock::release($lock);
        }

        if ($outcome === self::OUTCOME_ALIGNED || $outcome === self::OUTCOME_NOOP) {
            self::forget($pageId);
        } else {
            \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'page_owner_' . $outcome);
        }
        if ($outcome === self::OUTCOME_ALIGNED) {
            RelationshipLogWriter::appendStandalone([
                'action'          => 'owner_aligned',
                'page_id'         => $pageId,
                'subject_user_id' => null,
                'actor_kind'      => 'system',
                'actor_user_id'   => null,
                'payload'         => ['author' => self::author($pageId)],
            ]);
        }

        return $outcome;
    }

    /** Cron (every 15 minutes): retry a bounded batch of pages still to align. */
    public static function sweep(): void
    {
        foreach (array_slice(self::pending(), 0, self::SWEEP_BATCH) as $pageId) {
            self::align($pageId);
        }
    }

    /**
     * Pages still waiting to be aligned (oldest first), for the health view.
     *
     * @return list<int>
     */
    public static function pending(): array
    {
        $stored = get_option(self::PENDING, []);

        return is_array($stored) ? array_values(array_filter(array_map('intval', $stored), static fn (int $id): bool => $id > 0)) : [];
    }

    private static function alignLocked(int $pageId): string
    {
        try {
            $desired = ClaimRepository::resolveVerifiedOperatorForPageStrict($pageId);
        } catch (RepositoryReadFailure) {
            return self::OUTCOME_UNKNOWN;
        }
        if ($desired['state'] === ClaimRepository::OPERATOR_AMBIGUOUS) {
            return self::OUTCOME_UNKNOWN;
        }
        $desiredId = $desired['state'] === ClaimRepository::OPERATOR_RESOLVED ? (int) $desired['user_id'] : 0;
        $author    = self::author($pageId);
        $peepso    = \BCC\Trust\Core\Plugin::instance()->peepSoIntegration();

        if ($desiredId > 0) {
            if ($author !== 0 && $author !== $desiredId) {
                return self::OUTCOME_MISALIGNED; // never overwrite an author the claims do not name
            }
            $peepso->onPageClaimed($desiredId, $pageId); // moves post_author only from 0; ensures the owner row

            return self::author($pageId) === $desiredId ? ($author === 0 ? self::OUTCOME_ALIGNED : self::OUTCOME_NOOP) : self::OUTCOME_MISALIGNED;
        }

        if ($author === 0) {
            return self::OUTCOME_NOOP;
        }
        try {
            if (ClaimRepository::userHoldsPageThroughAnyClaim($author, $pageId)) {
                return self::OUTCOME_NOOP; // the author still holds the page through another claim
            }
        } catch (RepositoryReadFailure) {
            return self::OUTCOME_UNKNOWN;
        }
        $peepso->releaseClaimedPage($pageId, $author);
        if (self::author($pageId) !== 0) {
            return self::OUTCOME_MISALIGNED; // skipped (banned, still bound) or failed: reported
        }
        BonusService::clearForUnownedPage($pageId);

        return self::OUTCOME_ALIGNED;
    }

    /** The page's post_author, read past the object cache. */
    private static function author(int $pageId): int
    {
        clean_post_cache($pageId);

        return (int) get_post_field('post_author', $pageId);
    }

    private static function remember(int $pageId): void
    {
        $pending = self::pending();
        if (in_array($pageId, $pending, true)) {
            return;
        }
        $pending[] = $pageId;
        if (count($pending) > self::MAX_PENDING) {
            \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'align_backlog_full');
            $pending = array_slice($pending, -self::MAX_PENDING);
        }
        update_option(self::PENDING, $pending, false);
    }

    private static function forget(int $pageId): void
    {
        $pending = self::pending();
        $kept    = array_values(array_filter($pending, static fn (int $id): bool => $id !== $pageId));
        if ($kept !== $pending) {
            update_option(self::PENDING, $kept, false);
        }
    }
}
