<?php
/**
 * StewardshipSweepService — weekly community-upkeep credit for the
 * owners of genuinely-active User-kind communities (Rank redesign,
 * helping emitters).
 *
 * Runs off RankScheduler::EVENT_STEWARDSHIP_SWEEP (weekly). For each
 * User-kind community that clears the activity bar, it mints ONE
 * `stewardship` evidence event crediting the OWNER. Because rank-scoring
 * routes `stewardship` to BOTH `contribution` and `helping`, one active
 * community advances the owner's helping category AND the contribution
 * diversity axis — that dual credit is the intent.
 *
 * Idempotency + reversibility (§24.1): sourceId is a STABLE per-period
 * id — `groupId * 1_000_000 + (ISO-year*100 + ISO-week)`, all computed
 * in PHP UTC (never MySQL NOW()). The ledger's UNIQUE(event_uid,
 * category) then makes a re-run within the same ISO week a no-op, and a
 * later reversal can target the exact (group, period) source. source_id
 * is a BIGINT column, so the composite is packed as an integer rather
 * than the "{group}:{week}" string a varchar id would allow — same
 * (group, week) → same id.
 *
 * relationshipUserId is 0 (no member counterparty): the calculator's
 * helping share cap keys relationship_user_id as a USER id (with cluster
 * collapse), so passing a group id would risk colliding with a real
 * user's helping bucket. All stewardship helping credit therefore shares
 * the single identity-0 bucket (capped at the §9.3 helping-recipient
 * share), and the §21.2 ownership cap (max 2/5/10 communities per rank)
 * is the natural anti-farm bound on breadth. See the report for the
 * calibration note.
 *
 * Owner must be RESPONSIBLE — RankCredibilityGate::isResponsibleOwner
 * (not suspended, fraud-clear, not in Rank recovery). Bounded batch:
 * up to BATCH_LIMIT communities, TIME_BUDGET_SECONDS wall-clock budget
 * (mirrors the daily-evaluate sweep). Per-owner ingest failure records
 * the reused `rank_scoring.evidence_ingest_failed` DegradationMetric and
 * the sweep continues.
 *
 * Coverage (wrap-around cursor): each run reads a persisted cursor
 * (`bcc_rank_stewardship_cursor` wp_option, the highest gm_group_id the
 * previous run processed) and asks for the next BATCH_LIMIT communities
 * whose group id is greater than it; after processing it persists the
 * new high-water mark, or resets to 0 when it consumed the tail of the
 * set. Without this the same lowest-BATCH_LIMIT group ids were selected
 * every week and every community beyond them was permanently starved.
 * At fewer than BATCH_LIMIT communities the cursor wraps every run —
 * identical to the pre-cursor behavior.
 *
 * Collaborators are protected hooks (CapabilityResolver subclass pattern)
 * so StewardshipSweepServiceTest exercises the real sweep logic without
 * WordPress or a DB.
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign (helping emitters)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

if (!defined('ABSPATH')) {
    exit;
}

class StewardshipSweepService
{
    /** The evidence source type routed to contribution + helping (rank-scoring.php). */
    private const SOURCE_TYPE = 'stewardship';

    /** Bounded batch — one weekly run covers up to this many communities. */
    private const BATCH_LIMIT = 100;

    /** Wall-clock budget for one sweep, seconds (mirrors the daily-evaluate sweep). */
    private const TIME_BUDGET_SECONDS = 30;

    /**
     * Activity bar (⚖ calibratable): a community credits its owner only
     * with at least this many DISTINCT active posters in the window.
     */
    private const MIN_ACTIVE_POSTERS = 5;

    /** Activity window for the poster count (getActivityHeat `actives`). */
    private const ACTIVITY_WINDOW_SECONDS = 7 * 86400;

    /** Packing radix for the composite (group, period) source id. */
    private const PERIOD_RADIX = 1000000;

    /**
     * wp_option holding the wrap-around sweep cursor (the highest
     * gm_group_id processed by the previous run; 0 = start from the
     * beginning). Persisted non-autoloaded — read once per weekly run.
     */
    private const CURSOR_OPTION = 'bcc_rank_stewardship_cursor';

    public function run(): void
    {
        $deadline = $this->now() + self::TIME_BUDGET_SECONDS;

        // Wrap-around cursor: page the FULL community set across successive
        // weekly runs. Without it the same lowest-BATCH_LIMIT group ids were
        // re-selected forever (ORDER BY group_id ASC LIMIT with no offset),
        // permanently starving every community beyond the first BATCH_LIMIT
        // by group id of stewardship credit.
        $afterGroupId = $this->readCursor();
        $candidates   = $this->candidates(self::BATCH_LIMIT, $afterGroupId);
        if ($candidates === []) {
            // Reached the end of the set from a non-zero cursor — wrap to
            // the start so the next weekly run covers the earliest
            // communities. (A zero cursor with no rows = no communities.)
            if ($afterGroupId !== 0) {
                $this->writeCursor(0);
                $this->onCursorAdvance($afterGroupId, 0, true);
            }
            return;
        }

        $groupIds = [];
        foreach ($candidates as $c) {
            $groupIds[] = $c->group_id;
        }
        $activesByGroup = $this->activityHeat($groupIds, self::ACTIVITY_WINDOW_SECONDS);

        $period     = $this->isoPeriod();
        $maxGroupId = $afterGroupId;

        foreach ($candidates as $c) {
            if ($this->now() >= $deadline) {
                break; // Budget exhausted — cursor persists the last processed id.
            }

            // Candidates arrive ORDER BY group_id ASC, so this is monotonic —
            // the last processed row is the highest group id we advanced past
            // (including those skipped below the bar; they were still covered).
            $maxGroupId = $c->group_id;

            $actives = $activesByGroup[$c->group_id] ?? 0;
            if ($actives < self::MIN_ACTIVE_POSTERS) {
                continue; // Below the activity bar — no credit.
            }

            if (!$this->isResponsibleOwner($c->owner_id)) {
                continue; // Suspended / fraud-blocked / in recovery.
            }

            $sourceId = self::periodSourceId($c->group_id, $period);

            try {
                $this->ingest($c->owner_id, $sourceId);
            } catch (\Throwable $e) {
                $this->onIngestFailure($e, $c->owner_id, $c->group_id);
            }
        }

        // Advance the cursor. A short batch (fewer than BATCH_LIMIT) means we
        // consumed the tail of the set → wrap to 0 so the next run restarts
        // from the beginning; every community is thus covered within a bounded
        // number of weeks. Stewardship credit is idempotent per (group, ISO
        // week), so re-covering a community in a later week just mints that
        // week's credit — never a double-count.
        $reachedEnd = count($candidates) < self::BATCH_LIMIT;
        $newCursor  = $reachedEnd ? 0 : $maxGroupId;
        $this->writeCursor($newCursor);
        $this->onCursorAdvance($afterGroupId, $newCursor, $reachedEnd);
    }

    /**
     * Composite per-period source id: `groupId * RADIX + isoPeriod`,
     * where isoPeriod = ISO-year*100 + ISO-week (e.g. 202631). Injective
     * for isoPeriod < RADIX (max 209953 ≪ 1_000_000) and partitioned by
     * source_type='stewardship', so ids never collide with other
     * sources. Pure — unit-tested directly.
     */
    public static function periodSourceId(int $groupId, int $isoPeriod): int
    {
        return $groupId * self::PERIOD_RADIX + $isoPeriod;
    }

    // ── collaborator hooks (overridden by StewardshipSweepServiceTest) ──

    /** @return list<object{group_id: int, owner_id: int}> */
    protected function candidates(int $limit, int $afterGroupId): array
    {
        return \BCC\Trust\Core\Plugin::instance()
            ->stewardshipCandidateRepository()
            ->listUserKindCommunitiesWithOwners($limit, $afterGroupId);
    }

    /** Read the persisted wrap-around cursor (0 when unset). */
    protected function readCursor(): int
    {
        return (int) get_option(self::CURSOR_OPTION, 0);
    }

    /** Persist the wrap-around cursor (non-autoloaded). */
    protected function writeCursor(int $groupId): void
    {
        update_option(self::CURSOR_OPTION, $groupId, false);
    }

    /**
     * Observability hook for a cursor move. $wrapped is true when the run
     * reached the end of the set and reset to 0 (next run restarts from
     * the beginning).
     */
    protected function onCursorAdvance(int $from, int $to, bool $wrapped): void
    {
        \BCC\Core\Log\Logger::info('[bcc-trust] stewardship sweep cursor advanced', [
            'from'    => $from,
            'to'      => $to,
            'wrapped' => $wrapped,
        ]);
    }

    /**
     * groupId => distinct active posters in the window.
     *
     * @param list<int> $groupIds
     * @return array<int, int>
     */
    protected function activityHeat(array $groupIds, int $sinceSeconds): array
    {
        $heat = \BCC\Core\Repositories\PeepSoGroupRepository::getActivityHeat($groupIds, $sinceSeconds);
        $out  = [];
        foreach ($heat as $groupId => $row) {
            $out[(int) $groupId] = (int) $row->actives;
        }
        return $out;
    }

    protected function isResponsibleOwner(int $ownerUserId): bool
    {
        return \BCC\Trust\Core\Plugin::instance()
            ->rankCredibilityGate()
            ->isResponsibleOwner($ownerUserId);
    }

    /**
     * relationshipUserId 0 — stewardship has no member counterparty; the
     * §21.2 ownership cap plus the identity-0 helping bucket bound farming.
     */
    protected function ingest(int $ownerUserId, int $sourceId): void
    {
        \BCC\Trust\Core\Plugin::instance()
            ->rankEvidenceIngestor()
            ->ingest($ownerUserId, self::SOURCE_TYPE, $sourceId, 0);
    }

    protected function onIngestFailure(\Throwable $e, int $ownerUserId, int $groupId): void
    {
        \BCC\Core\Observability\DegradationMetrics::record('rank_scoring', 'evidence_ingest_failed');
        \BCC\Core\Log\Logger::error('[bcc-trust] stewardship ingest failed', [
            'owner_id' => $ownerUserId,
            'group_id' => $groupId,
            'error'    => $e->getMessage(),
        ]);
    }

    /** Current ISO period (ISO-year*100 + ISO-week), computed in UTC. */
    protected function isoPeriod(): int
    {
        $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return ((int) $dt->format('o')) * 100 + (int) $dt->format('W');
    }

    /** Epoch seconds — hook so tests can pin the budget clock. */
    protected function now(): int
    {
        return time();
    }
}
