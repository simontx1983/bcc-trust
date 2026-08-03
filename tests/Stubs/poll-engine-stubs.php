<?php
/**
 * Shared unit-test stubs for the Phase 6 meaningful-voting poll engine.
 *
 * In-memory repository fakes subclass the REAL repositories with empty
 * constructors (no TableRegistry/$wpdb) and mirror the DB-level
 * invariants the schema enforces — uniq_open_subject (one OPEN poll per
 * subject) and uniq_active_ballot (one ACTIVE ballot per voter) both
 * reject the losing insert with 0, exactly like the real constraint
 * path. Values round-trip as the numeric STRINGS wpdb returns
 * (DECIMAL(8,4) → '1.0000') so snapshot-verbatim assertions exercise
 * the same shapes production sees.
 *
 * TestPollService overrides the withinTransaction seam — the unit
 * bootstrap has no WP/DB for TransactionManager.
 *
 * Depends on rank-unit-stubs.php for the namespaced do_action fake
 * (PollService lives in BCC\Trust\Rank\Services) — require both.
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Stubs;

use BCC\Trust\Rank\Repositories\BallotRepository;
use BCC\Trust\Rank\Repositories\ClusterFindingsRepository;
use BCC\Trust\Rank\Repositories\PollRepository;
use BCC\Trust\Rank\Services\PollService;

class InMemoryPollRepository extends PollRepository
{
    /** @var array<int, object> id => PollRow-shaped stdClass */
    public array $rows = [];

    private int $nextId = 1;

    public function __construct()
    {
    }

    public function create(
        string $pollType,
        string $subjectType,
        int $subjectId,
        string $openedAt,
        string $bindingEarliestAt,
        string $expiresAt
    ): int {
        // Mimic uniq_open_subject: the losing INSERT returns 0.
        if ($this->getOpenBySubject($pollType, $subjectType, $subjectId) !== null) {
            return 0;
        }
        $id = $this->nextId++;
        $this->rows[$id] = (object) [
            'id'                  => (string) $id,
            'poll_type'           => $pollType,
            'subject_type'        => $subjectType,
            'subject_id'          => (string) $subjectId,
            'status'              => 'open',
            'outcome'             => null,
            'opened_at'           => $openedAt,
            'binding_earliest_at' => $bindingEarliestAt,
            'expires_at'          => $expiresAt,
            'closed_at'           => null,
        ];
        return $id;
    }

    public function getById(int $pollId): ?object
    {
        return $this->rows[$pollId] ?? null;
    }

    public function getOpenBySubject(string $pollType, string $subjectType, int $subjectId): ?object
    {
        foreach ($this->rows as $row) {
            if ($row->status === 'open'
                && $row->poll_type === $pollType
                && $row->subject_type === $subjectType
                && (int) $row->subject_id === $subjectId
            ) {
                return $row;
            }
        }
        return null;
    }

    public function listOpenPastBinding(string $nowSql, int $limit): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->status === 'open' && $row->binding_earliest_at <= $nowSql) {
                $out[] = $row;
            }
        }
        return array_slice($out, 0, $limit);
    }

    public function listOpenPastExpiry(string $nowSql, int $limit): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->status === 'open' && $row->expires_at <= $nowSql) {
                $out[] = $row;
            }
        }
        return array_slice($out, 0, $limit);
    }

    public function close(int $pollId, string $outcome, string $closedAt): bool
    {
        $row = $this->rows[$pollId] ?? null;
        if ($row === null || $row->status !== 'open') {
            return false; // Row-guard: only one closer wins.
        }
        $row->status    = 'closed';
        $row->outcome   = $outcome;
        $row->closed_at = $closedAt;
        return true;
    }
}

class InMemoryBallotRepository extends BallotRepository
{
    /** @var array<int, object> id => BallotRow-shaped stdClass */
    public array $rows = [];

    private int $nextId = 1;

    public function __construct()
    {
    }

    public function insert(
        int $pollId,
        int $voterId,
        string $choice,
        array $snapshot,
        int $recastCount,
        string $castAt,
        string $lastActionAt
    ): int {
        // Mimic uniq_active_ballot: the losing INSERT returns 0.
        if ($this->getActiveForVoter($pollId, $voterId) !== null) {
            return 0;
        }
        $id = $this->nextId++;
        $this->rows[$id] = (object) [
            'id'                   => (string) $id,
            'poll_id'              => (string) $pollId,
            'voter_user_id'        => (string) $voterId,
            'choice'               => $choice,
            'status'               => 'active',
            'recast_count'         => (string) $recastCount,
            'rank_slug'            => $snapshot['rank_slug'],
            'maturity'             => sprintf('%.4F', $snapshot['maturity']),
            'rank_multiplier'      => sprintf('%.4F', $snapshot['rank_multiplier']),
            'trust_multiplier'     => sprintf('%.4F', $snapshot['trust_multiplier']),
            'trust_score'          => sprintf('%.2F', $snapshot['trust_score']),
            'fraud_discount'       => sprintf('%.4F', $snapshot['fraud_discount']),
            'effective_weight'     => sprintf('%.4F', $snapshot['effective_weight']),
            'suspected_cluster_id' => null,
            'confirmed_cluster_id' => null,
            'counted_weight'       => null,
            'counted'              => null,
            'cast_at'              => $castAt,
            'last_action_at'       => $lastActionAt,
            'withdrawn_at'         => null,
            'superseded_at'        => null,
        ];
        return $id;
    }

    public function getActiveForVoter(int $pollId, int $voterId): ?object
    {
        foreach ($this->rows as $row) {
            if ((int) $row->poll_id === $pollId
                && (int) $row->voter_user_id === $voterId
                && $row->status === 'active'
            ) {
                return $row;
            }
        }
        return null;
    }

    public function getLatestForVoter(int $pollId, int $voterId): ?object
    {
        $latest = null;
        foreach ($this->rows as $row) {
            if ((int) $row->poll_id === $pollId && (int) $row->voter_user_id === $voterId) {
                if ($latest === null || (int) $row->id > (int) $latest->id) {
                    $latest = $row;
                }
            }
        }
        return $latest;
    }

    public function supersede(int $pollId, int $ballotId, string $supersededAt): bool
    {
        $row = $this->rows[$ballotId] ?? null;
        if ($row === null || $row->status !== 'active') {
            return false;
        }
        $row->status         = 'superseded';
        $row->superseded_at  = $supersededAt;
        $row->last_action_at = $supersededAt;
        return true;
    }

    public function withdraw(int $pollId, int $ballotId, string $withdrawnAt): bool
    {
        $row = $this->rows[$ballotId] ?? null;
        if ($row === null || $row->status !== 'active') {
            return false;
        }
        $row->status         = 'withdrawn';
        $row->withdrawn_at   = $withdrawnAt;
        $row->last_action_at = $withdrawnAt;
        return true;
    }

    public function listActiveForPoll(int $pollId, int $limit = self::MAX_BALLOTS): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ((int) $row->poll_id === $pollId && $row->status === 'active') {
                $out[] = $row;
            }
        }
        return array_slice($out, 0, $limit);
    }

    public function recordClosureAudit(
        int $pollId,
        int $ballotId,
        ?int $suspectedClusterId,
        ?int $confirmedClusterId,
        float $countedWeight,
        bool $counted
    ): bool {
        $row = $this->rows[$ballotId] ?? null;
        if ($row === null) {
            return false;
        }
        $row->suspected_cluster_id = $suspectedClusterId !== null ? (string) $suspectedClusterId : null;
        $row->confirmed_cluster_id = $confirmedClusterId !== null ? (string) $confirmedClusterId : null;
        $row->counted_weight       = sprintf('%.4F', $countedWeight);
        $row->counted              = $counted ? '1' : '0';
        return true;
    }

    public function countActiveForPoll(int $pollId): int
    {
        return count($this->listActiveForPoll($pollId));
    }
}

class StubClusterFindingsRepository extends ClusterFindingsRepository
{
    /** @var list<object> ClusterFindingRow-shaped stdClass rows */
    public array $confirmed = [];

    /** @var list<object> ClusterFindingRow-shaped stdClass rows */
    public array $suspected = [];

    public function __construct()
    {
    }

    public function listActiveConfirmed(): array
    {
        return $this->confirmed;
    }

    public function listActiveSuspected(): array
    {
        return $this->suspected;
    }
}

class TestPollService extends PollService
{
    /** No WP/DB in the unit bootstrap — run the callback directly. */
    protected function withinTransaction(callable $callback): mixed
    {
        return $callback();
    }
}
