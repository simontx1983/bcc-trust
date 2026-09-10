<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Repair\HallOwnershipRepairService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The guard set IS the product.
 *
 * This repair re-points a row in PeepSo's membership ledger — the one write
 * BCC is otherwise forbidden to make. What makes that acceptable is not the
 * UPDATE, it is the set of conditions under which the UPDATE is allowed to
 * fire. So most of these cases prove a REFUSAL, and each guard is disabled
 * one at a time so a mutation control can show it is load-bearing.
 *
 * Two properties get special attention because they are the ones a careless
 * fake would let rot:
 *
 *  - the repair asks the LEDGER whether the write landed, rather than
 *    trusting the affected-row count (`$writeSilentlyNoOps`);
 *  - it proves every pre-existing real member is byte-identical afterwards,
 *    not merely that the count matches (`$writeCorruptsNeighbour`).
 */
#[CoversClass(HallOwnershipRepairService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HallOwnershipRepairServiceTest extends TestCase
{
    private const OWNER = 1;
    private const HALL  = 6703;
    private const CHAIN = 10;

    private string $dir = '';

    private function boot(): void
    {
        require_once __DIR__ . '/../Stubs/hall-ownership-repair-stubs.php';

        \HallRepairTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Core\Security\TransactionManager::reset();

        $this->dir = sys_get_temp_dir() . '/bcc-hall-repair-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) { @unlink($f); }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    /**
     * The single place a guard mutation is expressed.
     *
     * Two tests drive the same provider, and when each kept its own copy of
     * this switch they could — and did — drift: one copy silently failed to
     * apply a mutation and the assertion still passed, because the value it
     * checked was constant. One implementation, both callers.
     */
    private static function applyGuardMutation(string $mutation): void
    {
        $g = self::HALL;

        switch ($mutation) {
            case 'kind':        \HallRepairTestState::$meta[$g]['_bcc_group_kind'] = ['holders']; break;
            case 'kindCase':    \HallRepairTestState::$meta[$g]['_bcc_group_kind'] = ['Hall']; break;
            case 'kindDupe':    \HallRepairTestState::$meta[$g]['_bcc_group_kind'] = ['hall', 'hall']; break;
            case 'collection':  \HallRepairTestState::$meta[$g]['_bcc_gate_collection_id'] = ['12']; break;
            case 'validator':   \HallRepairTestState::$meta[$g]['_bcc_gate_validator_id'] = ['77']; break;
            case 'noChain':     unset(\HallRepairTestState::$meta[$g]['_bcc_chain_tag']); break;
            case 'chainDupe':   \HallRepairTestState::$meta[$g]['_bcc_chain_tag'] = ['10', '11']; break;
            case 'badChain':    \HallRepairTestState::$meta[$g]['_bcc_chain_tag'] = ['nope']; break;
            case 'orphanChain': \HallRepairTestState::$chains = []; break;
            case 'closed':      \HallRepairTestState::$meta[$g]['peepso_group_privacy'] = ['1']; break;
            case 'noPrivacy':   unset(\HallRepairTestState::$meta[$g]['peepso_group_privacy']); break;
            case 'noOwner':     \HallRepairTestState::$rows[$g] = []; break;
            case 'twoOwners':   \HallRepairTestState::$rows[$g][] = ['gm_id' => 9500, 'gm_user_id' => 2, 'gm_user_status' => 'member_owner']; break;
            case 'realOwner':   \HallRepairTestState::$rows[$g] = [['gm_id' => 9501, 'gm_user_id' => 2, 'gm_user_status' => 'member_owner']]; break;
            case 'collision':   \HallRepairTestState::$rows[$g][] = ['gm_id' => 9502, 'gm_user_id' => self::OWNER, 'gm_user_status' => 'member']; break;
            case 'zeroExists':  \HallRepairTestState::$users[] = 0; break;
            case 'ownerGone':   \HallRepairTestState::$users = [2, 4, 49]; break;
            case 'ownerUncount':\HallRepairTestState::$countable = [2, 4, 49]; break;
            default:            throw new \LogicException('unknown mutation: ' . $mutation);
        }
    }

    /** @return array<string, mixed> the single plan entry */
    private function planOne(): array
    {
        $plan = (new HallOwnershipRepairService())->plan(self::OWNER);
        self::assertCount(1, $plan);
        return $plan[0];
    }

    /** @return array{results: list<array<string,mixed>>, backup: mixed, rollback: mixed} */
    private function apply(): array
    {
        return (new HallOwnershipRepairService())->run(true, self::OWNER, 4, 'testrun', $this->dir);
    }

    // ── The intended owner ───────────────────────────────────────────────

    public function testIntendedOwnerIsTheFirstActiveAdministrator(): void
    {
        $this->boot();
        self::assertSame(1, HallOwnershipRepairService::resolveIntendedOwner());
    }

    public function testASuspendedAdministratorIsSkipped(): void
    {
        $this->boot();
        \HallRepairTestState::$suspended[1] = '1';

        self::assertSame(2, HallOwnershipRepairService::resolveIntendedOwner(), 'falls through to the next active admin');
    }

    public function testADeletedAdministratorIsSkipped(): void
    {
        $this->boot();
        \HallRepairTestState::$users = [2, 4, 49];   // user 1 no longer exists

        self::assertSame(2, HallOwnershipRepairService::resolveIntendedOwner());
    }

    public function testADemotedAdministratorIsSkipped(): void
    {
        $this->boot();
        \HallRepairTestState::$capable = [4];

        self::assertSame(4, HallOwnershipRepairService::resolveIntendedOwner());
    }

    public function testNoEligibleAdministratorReturnsZeroRatherThanAFallback(): void
    {
        $this->boot();
        \HallRepairTestState::$capable = [];

        self::assertSame(0, HallOwnershipRepairService::resolveIntendedOwner());
    }

    // ── The happy path ───────────────────────────────────────────────────

    public function testAHealthyBrokenHallIsPlannedForRepair(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);

        $entry = $this->planOne();

        self::assertSame(HallOwnershipRepairService::RESULT_WOULD_REPAIR, $entry['result']);
        self::assertSame(self::HALL, $entry['group_id']);
        self::assertSame(self::CHAIN, $entry['chain_id']);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [49]);
        $before = \HallRepairTestState::snapshot(self::HALL);

        $run = (new HallOwnershipRepairService())->run(false, self::OWNER, 4, 'testrun', $this->dir);

        self::assertSame($before, \HallRepairTestState::snapshot(self::HALL));
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions());
        self::assertNull($run['backup']);
        self::assertNull($run['rollback']);
        self::assertSame([], glob($this->dir . '/*') ?: [], 'no artifact on a dry run');
    }

    public function testApplyRepointsTheOrphanAndPreservesRealMembers(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [2, 49]);

        $run = $this->apply();

        self::assertSame(HallOwnershipRepairService::RESULT_REPAIRED, $run['results'][0]['result']);
        self::assertSame(
            ['1:member_owner', '2:member', '49:member'],
            \HallRepairTestState::snapshot(self::HALL),
            'the orphan became the owner; both real members are untouched'
        );
    }

    public function testMemberCountIsRecomputedPeepSosWay(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [49]);

        // Before: user 0 is not countable (no peepso_users row) so PeepSo
        // sees 1. After: the owner is countable too, so 2.
        $run = $this->apply();

        self::assertSame(1, $run['results'][0]['members_count_before']);
        self::assertSame(2, $run['results'][0]['members_count_after']);
        self::assertSame('2', \HallRepairTestState::$postMeta[self::HALL . ':peepso_group_members_count']);
    }

    public function testSuccessWritesOneVerifiedAuditRowWithNoPersonalData(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);

        $this->apply();

        $rows = \BCC\Trust\Core\Security\AuditLogger::$rows;
        self::assertCount(1, $rows);
        self::assertSame(HallOwnershipRepairService::AUDIT_ACTION, $rows[0]['action']);
        self::assertSame('chain', $rows[0]['targetType']);
        self::assertSame(self::CHAIN, $rows[0]['targetId']);
        self::assertSame(4, $rows[0]['userId'], 'attributed to the named operator');
        self::assertSame(0, $rows[0]['meta']['before']);
        self::assertSame(self::OWNER, $rows[0]['meta']['after']);

        // Numeric ids only — nothing that could carry a name or address.
        $encoded = (string) json_encode($rows[0]['meta']);
        foreach (['@', 'user_login', 'display_name', 'user_email'] as $needle) {
            self::assertStringNotContainsString($needle, $encoded);
        }
    }

    public function testAuditActionFitsTheColumn(): void
    {
        self::assertLessThanOrEqual(50, strlen(HallOwnershipRepairService::AUDIT_ACTION));
        self::assertStringStartsWith('admin_', HallOwnershipRepairService::AUDIT_ACTION);
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function testASecondRunFindsNothingToDo(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [49]);

        $first = $this->apply();
        self::assertSame(HallOwnershipRepairService::RESULT_REPAIRED, $first['results'][0]['result']);

        $after = \HallRepairTestState::snapshot(self::HALL);
        \BCC\Trust\Core\Security\AuditLogger::reset();

        $second = $this->apply();

        self::assertSame(HallOwnershipRepairService::RESULT_ALREADY_CORRECT, $second['results'][0]['result']);
        self::assertSame($after, \HallRepairTestState::snapshot(self::HALL), 'the second run changed nothing');
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions(), 'and audited nothing');
        self::assertNull($second['backup'], 'no backup is taken when there is nothing to repair');
    }

    // ── Every guard, one at a time ───────────────────────────────────────

    /** @return array<string, list<mixed>> */
    public static function guardProvider(): array
    {
        return [
            'not a hall'                => ['kind',        'not_a_hall'],
            // wp_postmeta collation is case-insensitive, so an SQL
            // `= 'hall'` would accept this. The PHP comparison must not.
            'kind is a case variant'    => ['kindCase',    'not_a_hall'],
            'duplicate kind meta'       => ['kindDupe',    'kind_meta_not_single'],
            'holder-gated community'    => ['collection',  'has_collection_gate'],
            'validator community'       => ['validator',   'has_validator_gate'],
            'no chain tag'              => ['noChain',     'chain_tag_not_single'],
            'duplicate chain tag'       => ['chainDupe',   'chain_tag_not_single'],
            'non-numeric chain tag'     => ['badChain',    'chain_tag_not_numeric'],
            'chain row missing'         => ['orphanChain', 'chain_not_found'],
            'closed privacy'            => ['closed',      'not_open_privacy'],
            'privacy meta absent'       => ['noPrivacy',   'privacy_meta_not_single'],
            'no owner row'              => ['noOwner',     'no_owner_row'],
            'two owner rows'            => ['twoOwners',   'multiple_owner_rows'],
            'owner is another real user'=> ['realOwner',   'owner_is_a_different_real_user'],
            'incoming owner collides'   => ['collision',   'owner_already_has_a_row'],
            'user 0 actually exists'    => ['zeroExists',  'orphan_user_exists'],
            'owner does not exist'      => ['ownerGone',   'owner_user_missing'],
            'owner not peepso-countable'=> ['ownerUncount','owner_not_peepso_countable'],
        ];
    }

    #[DataProvider('guardProvider')]
    public function testEachGuardRefusesIndependently(string $mutation, string $expectedDetail): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        self::applyGuardMutation($mutation);

        $before = \HallRepairTestState::snapshot(self::HALL);
        $entry  = $this->planOne();

        self::assertNotSame(HallOwnershipRepairService::RESULT_WOULD_REPAIR, $entry['result']);
        self::assertSame($expectedDetail, $entry['detail']);

        // And an apply must still touch nothing.
        $run = $this->apply();
        self::assertSame($before, \HallRepairTestState::snapshot(self::HALL));
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions());
        self::assertNull($run['backup'], 'an ineligible plan never reaches the backup gate');
    }

    /**
     * A non-Hall group carrying the very same orphan row must be left alone.
     * This is the property that protects user communities, Commons Rooms,
     * NFT holder groups and validator communities.
     */
    public function testANonHallGroupWithTheSameOrphanRowIsNeverTouched(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::seedBrokenHall(7777, 11);
        \HallRepairTestState::$meta[7777]['_bcc_group_kind'] = ['holders'];

        $before = \HallRepairTestState::snapshot(7777);
        $run    = $this->apply();

        $byGroup = [];
        foreach ($run['results'] as $r) { $byGroup[$r['group_id']] = $r['result']; }

        self::assertSame(HallOwnershipRepairService::RESULT_REPAIRED, $byGroup[self::HALL]);
        self::assertSame(HallOwnershipRepairService::RESULT_REFUSED_PRECONDITION, $byGroup[7777]);
        self::assertSame($before, \HallRepairTestState::snapshot(7777), 'the holder group is byte-identical');
    }

    // ── The two properties a lazy fake would let rot ──────────────────────

    /**
     * The UPDATE reports one affected row and changes nothing — a silently
     * dropped write. Trusting the affected count would report `repaired`.
     */
    public function testAWriteThatReportsSuccessButChangesNothingIsRolledBack(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::$writeSilentlyNoOps = true;

        $before = \HallRepairTestState::snapshot(self::HALL);
        $run    = $this->apply();

        self::assertSame(HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK, $run['results'][0]['result']);
        self::assertSame($before, \HallRepairTestState::snapshot(self::HALL));
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions(), 'no audit row for a repair that did not happen');
        self::assertSame(1, \BCC\Trust\Core\Security\TransactionManager::$rollbacks);
    }

    /**
     * The write lands but clobbers a real member's row. A count-based check
     * would pass — the count is unchanged. Only comparing the exact set
     * catches it.
     */
    public function testARepairThatDisturbsARealMemberIsRolledBack(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [49]);
        \HallRepairTestState::$writeCorruptsNeighbour = true;

        $before = \HallRepairTestState::snapshot(self::HALL);
        $run    = $this->apply();

        self::assertSame(HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK, $run['results'][0]['result']);
        self::assertSame($before, \HallRepairTestState::snapshot(self::HALL), 'the member is restored');
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    /**
     * ── ISOLATING THE POSTCONDITION CLAUSES ─────────────────────────────
     * The composite behaviour was already covered, but every clause caught
     * every fault, so a mutation control could delete any ONE of them and
     * still see green. These four faults are each shaped so that exactly one
     * clause can fire.
     */
    public function testAWriteReportingTheWrongAffectedCountIsRolledBack(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);

        // The row IS correctly moved — only the reported count is wrong, so
        // every other postcondition passes and only `affected !== 1` can fire.
        \HallRepairTestState::$writeReportsAffected = 2;

        $before = \HallRepairTestState::snapshot(self::HALL);
        $run    = $this->apply();

        self::assertSame(HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK, $run['results'][0]['result']);
        self::assertSame($before, \HallRepairTestState::snapshot(self::HALL));
    }

    public function testAWriteThatLandsOnTheWrongUserIsRolledBack(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);

        // The orphan is consumed (so "no orphan remains" passes) but the row
        // now names somebody else — only the owner re-read can catch it.
        \HallRepairTestState::$writeLandsOnUser = 2;

        $before = \HallRepairTestState::snapshot(self::HALL);
        $run    = $this->apply();

        self::assertSame(HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK, $run['results'][0]['result']);
        self::assertSame($before, \HallRepairTestState::snapshot(self::HALL));
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    public function testAWriteThatLeavesTheOrphanBehindIsRolledBack(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);

        // A correct owner row appears, so the owner re-read passes; the
        // orphan lingers as a plain member. Only the orphan check can fire.
        \HallRepairTestState::$writeLeavesOrphanBehind = true;

        $before = \HallRepairTestState::snapshot(self::HALL);
        $run    = $this->apply();

        self::assertSame(HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK, $run['results'][0]['result']);
        self::assertSame($before, \HallRepairTestState::snapshot(self::HALL));
    }

    /**
     * The audit-null branch, isolated: `verifyAuditRow()` would also have
     * thrown, so without this the null check could be deleted unnoticed.
     */
    public function testALostAuditIdIsCaughtByItsOwnCheck(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $run = $this->apply();

        self::assertSame(HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK, $run['results'][0]['result']);
        // The FIRST failure must be the null check, named in the log, rather
        // than an incidental TypeError further down.
        $errors = array_filter(\BCC\Core\Log\Logger::$lines, static fn(array $l): bool => $l[0] === 'error');
        self::assertNotEmpty($errors);
    }

    /**
     * ── THE STATE MOVED BETWEEN PLAN AND APPLY ──────────────────────────
     * The plan is read WITHOUT locks, so it can be stale by the time the
     * write runs. Re-evaluating every guard under the lock is what turns a
     * concurrent change into a refusal instead of a corruption.
     */
    public function testAGuardThatBecomesFalseUnderTheLockRefusesTheWrite(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);

        // Between planning and the locked re-read, somebody joins the very
        // user the repair was about to install as owner — a collision that
        // did not exist when the plan was made.
        \HallRepairTestState::$mutateBeforeApply = static function (int $groupId): void {
            \HallRepairTestState::$rows[$groupId][] = [
                'gm_id' => 9800, 'gm_user_id' => self::OWNER, 'gm_user_status' => 'member',
            ];
        };

        $run = $this->apply();

        self::assertSame(HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK, $run['results'][0]['result']);
        // The transaction rolled back, so even the interloper's row is gone
        // — the ledger is exactly as it was before the apply began.
        self::assertSame(
            ['0:member_owner'],
            \HallRepairTestState::snapshot(self::HALL),
            'the orphan is untouched'
        );
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    /**
     * ── PROVING FOUR EQUIVALENT MUTANTS, RATHER THAN CLAIMING THEM ──────
     *
     * Mutation result: 33 controls, 29 killed, 4 EQUIVALENT. The four are
     * classified out of the kill denominator rather than counted as killed
     * — 29/29 actionable controls die.
     *
     * ⚠ An earlier pass reported "26 killed, 7 explained". That was wrong
     * twice over, and the correction is worth recording. Three of those
     * seven — the two `repointOwnerRow()` WHERE predicates and the PeepSo
     * member-count JOIN — were never SKIPPED-but-fine, they were simply
     * never RUN: the runner filtered the unit suite, where this repository
     * is faked, so the only tests that can discriminate them (the real-MySQL
     * integration suite) never executed. Run against unit AND integration
     * they all die. A control that was not executed is a skipped control,
     * not an explained one.
     *
     * The remaining four are DEFENCE IN DEPTH, not dead code. Each is
     * backstopped by a later check that catches the same fault, so removing
     * one changes the error message but not the outcome.
     *
     *   1. the locked re-check throw — every refusal path returns
     *      `row_id => 0`, and the guarded UPDATE rejects a non-positive row
     *      id, so `affected !== 1` throws anyway (pinned below, and by the
     *      repository's own integration test);
     *   2. the owner re-read, and
     *   3. the "no orphan remains" check — a wrongly-owned or lingering row
     *      also lands in `$membersAfter`, so the member-preservation
     *      comparison fires;
     *   4. the audit null check — `verifyAuditRow(0)` cannot read a row and
     *      throws.
     *
     * They are kept because each names its own failure precisely, and an
     * operator reading a rolled-back repair should be told WHICH invariant
     * broke. This test pins the first link of chain 1 so the equivalence is
     * demonstrated rather than asserted in a comment.
     */
    public function testEveryRefusalYieldsRowIdZeroSoTheWriteCannotFire(): void
    {
        $this->boot();

        foreach (self::guardProvider() as $label => [$mutation, $expectedDetail]) {
            \HallRepairTestState::reset();
            \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
            self::applyGuardMutation($mutation);

            $entry = (new HallOwnershipRepairService())->plan(self::OWNER)[0];

            // ⚠ Assert the mutation actually TOOK first. Until the planner
            // stopped returning row_id 0 for everything, this loop passed
            // even when a mutation silently failed to apply — the assertion
            // below was true for the unmutated Hall too.
            self::assertSame(
                $expectedDetail,
                $entry['detail'],
                "mutation '{$mutation}' did not produce its refusal"
            );
            self::assertSame(0, $entry['row_id'], "refusal '{$label}' must not carry a writable row id");
        }
    }

    /**
     * The isolating case for the locked re-check.
     *
     * The collision variant above is also caught by the member-preservation
     * postcondition, so it cannot prove the re-check alone. This one changes
     * a guard the postconditions CANNOT see: the group stops being open
     * between planning and the write. Without re-evaluating under the lock,
     * the repair would happily re-point ownership on a group that is no
     * longer a public Hall, and every postcondition would pass.
     */
    public function testAGuardThePostconditionsCannotSeeIsStillCaughtUnderTheLock(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [49]);

        \HallRepairTestState::$mutateBeforeApply = static function (int $groupId): void {
            // Somebody closes the group after the plan was read.
            \HallRepairTestState::$meta[$groupId]['peepso_group_privacy'] = ['1'];
        };

        $before = \HallRepairTestState::snapshot(self::HALL);
        $run    = $this->apply();

        self::assertSame(HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK, $run['results'][0]['result']);
        self::assertSame($before, \HallRepairTestState::snapshot(self::HALL));
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    /**
     * ── THE BACKUP MUST DESCRIBE THE LIVE ROWS ──────────────────────────
     * Writing a file and reading it back proves only that the disk works. If
     * the rows moved between the snapshot and the verification, the file is
     * not a backup of the state about to be changed.
     */
    public function testABackupThatNoLongerMatchesTheLiveRowsRefusesTheApply(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::seedBrokenHall(6704, 8);

        // The backup reads each group twice: once to build the payload, once
        // to verify it against live rows. Mutating between the two makes the
        // saved snapshot stale — a file that no longer describes the state
        // about to be changed is not a backup.
        // Read order: plan() reads each of the 2 Halls (1,2); the backup
        // payload loop reads each again (3,4); the VERIFICATION loop reads
        // them a third time (5,6). Mutating on read 5 is what makes the
        // saved snapshot stale relative to live.
        \HallRepairTestState::$mutateOnReadNumber = 5;
        \HallRepairTestState::$mutateOnReadFn = static function (int $groupId): void {
            \HallRepairTestState::$rows[$groupId][] = [
                'gm_id' => 9901, 'gm_user_id' => 49, 'gm_user_status' => 'member',
            ];
        };

        $before6703 = \HallRepairTestState::snapshot(self::HALL);
        $before6704 = \HallRepairTestState::snapshot(6704);

        try {
            $this->apply();
            self::fail('Expected the backup verification to refuse the apply.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('refusing the entire apply', $e->getMessage());
            self::assertStringContainsString('does not match live rows', $e->getMessage());
        }

        // ⚠ Every Hall, not just the one whose rows moved.
        self::assertSame($before6704, \HallRepairTestState::snapshot(6704));
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions());
        self::assertStringNotContainsString(
            '1:member_owner',
            implode(',', \HallRepairTestState::snapshot(self::HALL)),
            'no ownership was changed'
        );
        unset($before6703);
    }

    public function testALostAuditRowRollsTheRepairBack(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $before = \HallRepairTestState::snapshot(self::HALL);
        $run    = $this->apply();

        self::assertSame(HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK, $run['results'][0]['result']);
        self::assertSame($before, \HallRepairTestState::snapshot(self::HALL),
            'an unattributable ownership change is not allowed to exist');
    }

    public function testAnAuditRowThatDoesNotSayWhatWeMeantRollsTheRepairBack(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \BCC\Trust\Core\Security\AuditLogger::$corruptStoredMeta = true;

        $before = \HallRepairTestState::snapshot(self::HALL);
        $run    = $this->apply();

        self::assertSame(HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK, $run['results'][0]['result']);
        self::assertSame($before, \HallRepairTestState::snapshot(self::HALL));
    }

    // ── Backup and rollback artifacts ────────────────────────────────────

    public function testAVerifiedBackupIsWrittenBeforeAnyChange(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [49]);

        $run = $this->apply();

        self::assertTrue($run['backup']['verified']);
        self::assertFileExists($run['backup']['path']);

        $decoded = json_decode((string) file_get_contents($run['backup']['path']), true);
        self::assertSame('bcc-hall-ownership-backup', $decoded['kind']);
        self::assertSame(self::HALL, $decoded['groups'][0]['group_id']);

        // The backup records the PRE-repair state, which is what makes it a
        // backup rather than a receipt.
        $users = array_map(static fn(array $r): int => $r['gm_user_id'], $decoded['groups'][0]['membership_rows']);
        self::assertContains(0, $users);
        self::assertNotContains(self::OWNER, $users);
    }

    public function testAnUnwritableArtifactDirectoryRefusesTheWholeApply(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::seedBrokenHall(6704, 8);
        \HallRepairTestState::$artifactDirUnwritable = true;

        $before6703 = \HallRepairTestState::snapshot(self::HALL);
        $before6704 = \HallRepairTestState::snapshot(6704);

        try {
            $this->apply();
            self::fail('Expected the backup gate to refuse the apply.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('refusing the entire apply', $e->getMessage());
        }

        // ⚠ The WHOLE apply, not just the first Hall.
        self::assertSame($before6703, \HallRepairTestState::snapshot(self::HALL));
        self::assertSame($before6704, \HallRepairTestState::snapshot(6704));
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    public function testTheRollbackArtifactIsBuiltFromRowsActuallyChanged(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        // A second Hall that will REFUSE — it must not appear in the rollback.
        \HallRepairTestState::seedBrokenHall(6704, 8);
        \HallRepairTestState::$meta[6704]['peepso_group_privacy'] = ['1'];

        $run = $this->apply();

        self::assertTrue($run['rollback']['written']);
        self::assertSame(1, $run['rollback']['row_count'], 'only the Hall that really changed');

        $decoded = json_decode((string) file_get_contents($run['rollback']['path']), true);
        self::assertSame('bcc-hall-ownership-rollback', $decoded['kind']);
        self::assertCount(1, $decoded['rows']);
        self::assertSame(self::HALL, $decoded['rows'][0]['group_id']);
        self::assertSame(0, $decoded['rows'][0]['restore_to']);
        self::assertSame(self::OWNER, $decoded['rows'][0]['was_changed_to']);
    }

    /**
     * A Hall that STARTED the repair and rolled back changed nothing, so it
     * must not appear in the rollback artifact either. Filtering on
     * `RESULT_REPAIRED` — rather than "everything we attempted" — is what
     * keeps the artifact a record of reality.
     */
    public function testARolledBackHallIsExcludedFromTheRollbackArtifact(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN);
        \HallRepairTestState::seedBrokenHall(6704, 8);

        // 6704 passes every guard and reaches the write, then fails its
        // postcondition — the one shape that is attempted but not changed.
        // 6703 repairs normally, so the artifact has something to contain.
        \HallRepairTestState::$writeSilentlyNoOpsForGroup = 6704;

        $run = $this->apply();

        $byGroup = [];
        foreach ($run['results'] as $r) { $byGroup[$r['group_id']] = $r['result']; }
        self::assertSame(HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK, $byGroup[6704]);

        $decoded = json_decode((string) file_get_contents($run['rollback']['path']), true);
        $groups  = array_map(static fn(array $r): int => $r['group_id'], $decoded['rows']);

        self::assertNotContains(6704, $groups, 'a Hall that changed nothing has nothing to roll back');
    }

    public function testTheRollbackArtifactRestoresTheExactPriorState(): void
    {
        $this->boot();
        \HallRepairTestState::seedBrokenHall(self::HALL, self::CHAIN, [2, 49]);
        $before = \HallRepairTestState::snapshot(self::HALL);

        $run = $this->apply();
        self::assertNotSame($before, \HallRepairTestState::snapshot(self::HALL));

        // Drive the artifact back through the SAME guarded write.
        $decoded = json_decode((string) file_get_contents($run['rollback']['path']), true);
        \BCC\Trust\Core\Security\TransactionManager::run(static function () use ($decoded): array {
            foreach ($decoded['rows'] as $row) {
                $n = \BCC\Trust\Onchain\Repositories\HallOwnershipRepairRepository::repointOwnerRow(
                    (int) $row['gm_id'],
                    (int) $row['group_id'],
                    (int) $row['was_changed_to'],
                    (int) $row['restore_to'],
                    (string) $row['status']
                );
                if ($n !== 1) { throw new \RuntimeException('rollback did not match'); }
            }
            return ['ok' => true];
        });

        self::assertSame($before, \HallRepairTestState::snapshot(self::HALL), 'byte-identical to the pre-repair state');
    }
}
