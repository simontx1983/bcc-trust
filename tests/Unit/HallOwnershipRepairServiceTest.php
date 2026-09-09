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

        $M = &\HallRepairTestState::$meta[self::HALL];
        $R = &\HallRepairTestState::$rows[self::HALL];

        switch ($mutation) {
            case 'kind':        $M['_bcc_group_kind'] = ['holders']; break;
            case 'kindCase':    $M['_bcc_group_kind'] = ['Hall']; break;
            case 'kindDupe':    $M['_bcc_group_kind'] = ['hall', 'hall']; break;
            case 'collection':  $M['_bcc_gate_collection_id'] = ['12']; break;
            case 'validator':   $M['_bcc_gate_validator_id'] = ['77']; break;
            case 'noChain':     unset($M['_bcc_chain_tag']); break;
            case 'chainDupe':   $M['_bcc_chain_tag'] = ['10', '11']; break;
            case 'badChain':    $M['_bcc_chain_tag'] = ['nope']; break;
            case 'orphanChain': \HallRepairTestState::$chains = []; break;
            case 'closed':      $M['peepso_group_privacy'] = ['1']; break;
            case 'noPrivacy':   unset($M['peepso_group_privacy']); break;
            case 'noOwner':     $R = []; break;
            case 'twoOwners':   $R[] = ['gm_id' => 9500, 'gm_user_id' => 2, 'gm_user_status' => 'member_owner']; break;
            case 'realOwner':   $R = [['gm_id' => 9501, 'gm_user_id' => 2, 'gm_user_status' => 'member_owner']]; break;
            case 'collision':   $R[] = ['gm_id' => 9502, 'gm_user_id' => self::OWNER, 'gm_user_status' => 'member']; break;
            case 'zeroExists':  \HallRepairTestState::$users[] = 0; break;
            case 'ownerGone':   \HallRepairTestState::$users = [2, 4, 49]; break;
            case 'ownerUncount':\HallRepairTestState::$countable = [2, 4, 49]; break;
        }

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
