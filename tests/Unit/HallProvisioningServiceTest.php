<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Onchain\Services\HallProvisioningService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Hall creation is ADMINISTRATOR-INITIATED and OWNERSHIP IS PART OF IT.
 *
 * What these cases pin, in the order the service does them:
 *
 *   1. Fail-closed guards BEFORE anything is created — PeepSo absent, no
 *      chain, unnamed chain, no signed-in administrator, no resolvable owner.
 *   2. Idempotency — a chain that already has a Hall creates nothing.
 *   3. Ownership reconciliation — join → transfer → leave, and then the
 *      LEDGER is asked. A writer returning true is not evidence.
 *   4. A failed reconcile is never reported as a successful provision: it is
 *      marked, audited, and returned as `incomplete`.
 *   5. A marker that did not stick is COMPENSATED, because that failure — and
 *      only that one — would let the next attempt create a duplicate.
 *
 * Each case runs in its own subprocess so `\PeepSoGroup` can be absent in one
 * test and present in the next without cross-contamination.
 */
#[CoversClass(HallProvisioningService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HallProvisioningServiceTest extends TestCase
{
    private const CHAIN_ID = 42;

    /** Load the fakes and seed one named, resolvable chain. */
    private function boot(): void
    {
        require_once __DIR__ . '/../Stubs/hall-provision-stubs.php';

        \HallTestState::reset();
        \FakePeepSoLedger::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Core\PeepSo\PeepSoGroupWriter::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\HallRepository::reset();

        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN_ID, 'cosmos', 'Cosmos');
    }

    // ── Guards that must run BEFORE anything is created ──────────────────

    public function testPeepSoAbsentCreatesNothing(): void
    {
        // The WP-function fakes load, but `\PeepSoGroup` is deliberately NOT
        // defined — so the service's own availability guard is what stops
        // this, rather than an incidentally missing stub.
        define('HALL_STUBS_NO_PEEPSO', true);
        require_once __DIR__ . '/../Stubs/hall-provision-stubs.php';
        \HallTestState::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\HallRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN_ID, 'cosmos', 'Cosmos');

        self::assertFalse(class_exists('\\PeepSoGroup', false), 'precondition: PeepSo absent');

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('error', $result['status']);
        self::assertSame(HallProvisioningService::FAIL_PEEPSO_ABSENT, $result['failure_code']);
        self::assertSame(0, $result['group_id']);
    }

    public function testInvalidChainIdIsRefusedWithoutAnAuditRow(): void
    {
        $this->boot();

        $result = (new HallProvisioningService())->provisionOne(0);

        self::assertSame('skipped', $result['status']);
        self::assertSame(HallProvisioningService::FAIL_CHAIN_UNRESOLVED, $result['failure_code']);
        self::assertSame([], \HallTestState::$created, 'nothing may be created');
        // A rejected form post is not an authorized operation that failed.
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    public function testUnresolvableChainIsRefused(): void
    {
        $this->boot();

        $result = (new HallProvisioningService())->provisionOne(999);

        self::assertSame('skipped', $result['status']);
        self::assertSame(HallProvisioningService::FAIL_CHAIN_UNRESOLVED, $result['failure_code']);
        self::assertSame([], \HallTestState::$created);
    }

    public function testChainWithoutANameIsRefused(): void
    {
        $this->boot();
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN_ID, 'cosmos', '   ');

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('skipped', $result['status']);
        self::assertSame(HallProvisioningService::FAIL_CHAIN_UNNAMED, $result['failure_code']);
        self::assertSame([], \HallTestState::$created);
    }

    public function testNoSignedInAdministratorFailsClosed(): void
    {
        $this->boot();
        // The cron shape. With no current user the two ownership books cannot
        // be made to agree, so nothing may be created.
        \HallTestState::$currentUserId = 0;

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('error', $result['status']);
        self::assertSame(HallProvisioningService::FAIL_NO_ACTING_ADMIN, $result['failure_code']);
        self::assertSame([], \HallTestState::$created);
        self::assertSame(
            [HallProvisioningService::AUDIT_FAILED],
            \BCC\Trust\Core\Security\AuditLogger::actions()
        );
    }

    public function testNoAdministratorToOwnTheHallFailsClosed(): void
    {
        $this->boot();
        \HallTestState::$admins = [];

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('error', $result['status']);
        self::assertSame(HallProvisioningService::FAIL_OWNER_UNRESOLVED, $result['failure_code']);
        self::assertSame([], \HallTestState::$created);
    }

    public function testDeletedOwnerAccountFailsClosed(): void
    {
        $this->boot();
        // The first administrator no longer resolves.
        \HallTestState::$knownUserIds = [7];

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('error', $result['status']);
        self::assertSame(HallProvisioningService::FAIL_OWNER_UNRESOLVED, $result['failure_code']);
        self::assertSame([], \HallTestState::$created);
    }

    public function testDemotedOwnerAccountFailsClosed(): void
    {
        $this->boot();
        // The account exists but no longer holds manage_options.
        \HallTestState::$capableUserIds = [7];

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('error', $result['status']);
        self::assertSame(HallProvisioningService::FAIL_OWNER_UNRESOLVED, $result['failure_code']);
        self::assertSame([], \HallTestState::$created);
    }

    public function testGroupCreationFailureIsAudited(): void
    {
        $this->boot();
        \HallTestState::$createReturnsZero = true;

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('error', $result['status']);
        self::assertSame(HallProvisioningService::FAIL_CREATE_FAILED, $result['failure_code']);
        self::assertSame(
            [HallProvisioningService::AUDIT_FAILED],
            \BCC\Trust\Core\Security\AuditLogger::actions()
        );
    }

    // ── The happy path ───────────────────────────────────────────────────

    public function testCreatesExactlyOneHallAndMovesOwnershipToTheFirstAdministrator(): void
    {
        $this->boot();

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('created', $result['status']);
        self::assertSame(900, $result['group_id']);
        self::assertSame(3, $result['owner_id'], 'the first administrator, not the clicker');
        self::assertFalse($result['audit_degraded']);

        self::assertCount(1, \HallTestState::$created, 'exactly one group');
        self::assertSame('Cosmos Hall', \HallTestState::$created[0]['name']);
        self::assertSame(0, \HallTestState::$created[0]['meta']['privacy'], 'open');

        // ⚠ The creator is passed as owner_id ON PURPOSE — PeepSo owns the
        // current user regardless, and transferOwnership() refuses to act on
        // books that disagree. Ownership moves afterwards.
        self::assertSame(7, \HallTestState::$created[0]['owner_id']);

        // The ledger, not the return values.
        self::assertSame('member_owner', \FakePeepSoLedger::status(3, 900));
        self::assertNull(\FakePeepSoLedger::status(7, 900), 'the clicker holds no membership');
        self::assertSame(3, \FakePeepSoLedger::$owners[900], 'post_author pointer moved too');

        self::assertSame(
            ['join:3:900', 'transfer:900:7:3', 'leave:7:900'],
            \BCC\Core\PeepSo\PeepSoGroupWriter::$calls
        );
    }

    public function testSuccessWritesOneAuditRowAttributedToTheOperator(): void
    {
        $this->boot();

        (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        $rows = \BCC\Trust\Core\Security\AuditLogger::$rows;
        self::assertCount(1, $rows);
        self::assertSame(HallProvisioningService::AUDIT_CREATED, $rows[0]['action']);
        self::assertSame('chain', $rows[0]['targetType']);
        self::assertSame(self::CHAIN_ID, $rows[0]['targetId']);
        // WHO did it, and who ended up owning it — different people.
        self::assertSame(7, $rows[0]['userId']);
        self::assertSame(7, $rows[0]['meta']['operator_user_id']);
        self::assertSame(3, $rows[0]['meta']['owner_user_id']);
        self::assertSame(900, $rows[0]['meta']['group_id']);
        self::assertSame('cosmos', $rows[0]['meta']['chain_slug']);
    }

    public function testActingAdministratorWhoIsAlreadyTheOwnerSkipsTheTransfer(): void
    {
        $this->boot();
        \HallTestState::$currentUserId = 3;   // the first administrator clicks

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('created', $result['status']);
        self::assertSame('member_owner', \FakePeepSoLedger::status(3, 900));
        // No join, no transfer, no leave — PeepSo already produced the wanted
        // state, and moving ownership to yourself would be refused anyway.
        self::assertSame([], \BCC\Core\PeepSo\PeepSoGroupWriter::$calls);
    }

    public function testFanOutHookFiresWithTheStableSignature(): void
    {
        $this->boot();

        (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame(['bcc_hall_provisioned'], \HallTestState::actionNames());
        self::assertSame([900, self::CHAIN_ID, 'cosmos'], \HallTestState::$actions[0][1]);
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function testExistingHallIsNeverDuplicated(): void
    {
        $this->boot();
        \BCC\Trust\Onchain\Repositories\HallRepository::$halls[self::CHAIN_ID] = 555;

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('exists', $result['status']);
        self::assertSame(555, $result['group_id']);
        self::assertSame([], \HallTestState::$created);
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions(), 'a no-op is not a mutation');
    }

    public function testRepeatedSubmissionCreatesOnlyOneHall(): void
    {
        $this->boot();

        $service = new HallProvisioningService();
        $first   = $service->provisionOne(self::CHAIN_ID);
        $second  = $service->provisionOne(self::CHAIN_ID);
        $third   = $service->provisionOne(self::CHAIN_ID);

        self::assertSame('created', $first['status']);
        self::assertSame('exists', $second['status']);
        self::assertSame('exists', $third['status']);
        self::assertCount(1, \HallTestState::$created, 'a refresh must not mint a second Hall');
        self::assertSame($first['group_id'], $second['group_id']);
    }

    // ── Ownership reconciliation is part of creation ─────────────────────

    /** @return array<string, list<string>> */
    public static function reconcileFailureProvider(): array
    {
        return [
            'join refused'     => ['join'],
            'transfer refused' => ['transfer'],
            'leave refused'    => ['leave'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reconcileFailureProvider')]
    public function testAFailedReconcileIsNeverReportedAsCreated(string $failing): void
    {
        $this->boot();

        // Explicit, not a dynamic property write — PHPStan can see these.
        switch ($failing) {
            case 'join':
                \BCC\Core\PeepSo\PeepSoGroupWriter::$joinResult = false;
                break;
            case 'transfer':
                \BCC\Core\PeepSo\PeepSoGroupWriter::$transferResult = false;
                break;
            default:
                \BCC\Core\PeepSo\PeepSoGroupWriter::$leaveResult = false;
        }

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('incomplete', $result['status'], 'must NOT be reported as created');
        self::assertSame('ownership_unreconciled', $result['failure_code']);
        self::assertSame(900, $result['group_id'], 'the Hall exists and is named');

        // Visible for repair.
        self::assertTrue(\BCC\Trust\Onchain\Repositories\HallRepository::isOwnerIncomplete(900));

        // Durably recorded, with the step that failed.
        self::assertSame(
            [HallProvisioningService::AUDIT_INCOMPLETE],
            \BCC\Trust\Core\Security\AuditLogger::actions()
        );
        $meta = \BCC\Trust\Core\Security\AuditLogger::$rows[0]['meta'];
        self::assertSame($failing, $meta['reconcile_step']);
        self::assertSame('ownership_unreconciled', $meta['error_code']);

        // Still discoverable, so a retry repairs rather than duplicates.
        self::assertSame(900, \BCC\Trust\Onchain\Repositories\HallRepository::findHallForChain(self::CHAIN_ID));
    }

    /**
     * The guard that cannot be satisfied by three cooperative return values.
     *
     * Every writer returns TRUE and changes nothing — what a silently dropped
     * PeepSo write looks like. A service that trusted its return values would
     * report `created` here, leaving a public Hall owned by the clicker.
     */
    public function testWritersReportingSuccessCannotOverrideTheLedger(): void
    {
        $this->boot();
        \BCC\Core\PeepSo\PeepSoGroupWriter::$silentSuccess = true;

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('incomplete', $result['status']);
        self::assertSame(
            'postcondition_owner',
            \BCC\Trust\Core\Security\AuditLogger::$rows[0]['meta']['reconcile_step'],
            'the ledger, not the return values, decided this'
        );

        // All three writers were called and all three said yes.
        self::assertSame(
            ['join:3:900', 'transfer:900:7:3', 'leave:7:900'],
            \BCC\Core\PeepSo\PeepSoGroupWriter::$calls
        );
    }

    /**
     * The second half of the same guard: the owner row is right, but the
     * creator's membership was never removed. Reporting success would leave a
     * Hall with an extra manager nobody intended.
     */
    public function testALingeringCreatorMembershipFailsThePostcondition(): void
    {
        $this->boot();

        // leave() reports success and removes nothing.
        \BCC\Core\PeepSo\PeepSoGroupWriter::$leaveSilent = true;

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('incomplete', $result['status']);
        self::assertSame(
            'postcondition_creator',
            \BCC\Trust\Core\Security\AuditLogger::$rows[0]['meta']['reconcile_step']
        );

        // The intended owner IS correct — only the creator's row lingered,
        // which is exactly why the owner check alone would not have caught it.
        self::assertSame('member_owner', \FakePeepSoLedger::status(3, 900));
        self::assertSame('member_manager', \FakePeepSoLedger::status(7, 900));
        self::assertTrue(\BCC\Trust\Onchain\Repositories\HallRepository::isOwnerIncomplete(900));
    }

    public function testRetryOnAMarkedHallRepairsOwnershipWithoutCreatingAnother(): void
    {
        $this->boot();

        // First attempt: the transfer is refused, so the Hall is marked.
        \BCC\Core\PeepSo\PeepSoGroupWriter::$transferResult = false;
        $first = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);
        self::assertSame('incomplete', $first['status']);
        self::assertTrue(\BCC\Trust\Onchain\Repositories\HallRepository::isOwnerIncomplete(900));

        // Second attempt: the writer works now. Same button, repair path.
        \BCC\Core\PeepSo\PeepSoGroupWriter::$transferResult = true;
        \BCC\Trust\Core\Security\AuditLogger::reset();

        $second = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('created', $second['status']);
        self::assertSame(900, $second['group_id']);
        self::assertCount(1, \HallTestState::$created, 'no second Hall');
        self::assertFalse(\BCC\Trust\Onchain\Repositories\HallRepository::isOwnerIncomplete(900));
        self::assertSame('member_owner', \FakePeepSoLedger::status(3, 900));
        self::assertSame('repaired', \BCC\Trust\Core\Security\AuditLogger::$rows[0]['meta']['reconcile_step']);
    }

    // ── Compensation: only for the duplicate-creating failure ────────────

    public function testAMarkerThatDidNotStickIsCompensated(): void
    {
        $this->boot();
        // The marker write is accepted but the chain never resolves to the
        // group — the one failure that would let the next attempt duplicate.
        \BCC\Trust\Onchain\Repositories\HallRepository::$writeMetaWorks = false;

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('error', $result['status']);
        self::assertSame(HallProvisioningService::FAIL_MARKER_REFUSED, $result['failure_code']);

        // The group was removed, not left orphaned and unfindable.
        self::assertSame([900], \HallTestState::$deletedPosts);

        self::assertSame(
            [
                HallProvisioningService::AUDIT_COMPENSATED,
                HallProvisioningService::AUDIT_FAILED,
            ],
            \BCC\Trust\Core\Security\AuditLogger::actions()
        );
        self::assertSame('clean', \BCC\Trust\Core\Security\AuditLogger::$rows[0]['meta']['error_code']);
    }

    public function testACompensationWhoseOwnAuditIsLostIsSurfaced(): void
    {
        $this->boot();
        \BCC\Trust\Onchain\Repositories\HallRepository::$writeMetaWorks = false;
        \BCC\Trust\Core\Security\AuditLogger::$failCheckedActions =
            [HallProvisioningService::AUDIT_COMPENSATED];

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('error', $result['status']);
        self::assertTrue($result['audit_degraded'], 'a lost compensation record must be surfaced');
    }

    public function testALostCreationAuditIsSurfacedButTheHallIsKept(): void
    {
        $this->boot();
        \BCC\Trust\Core\Security\AuditLogger::$failCheckedActions =
            [HallProvisioningService::AUDIT_CREATED];

        $result = (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame('created', $result['status']);
        self::assertTrue($result['audit_degraded']);
        // Deleting a correct public space over a lost audit row would be the
        // worse trade — the Hall stands and the operator is told.
        self::assertSame([], \HallTestState::$deletedPosts);
    }

    // ── No unintended fan-out ────────────────────────────────────────────

    public function testCreationNeverJoinsAnyoneButTheDeterministicOwner(): void
    {
        $this->boot();

        (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        $joins = array_values(array_filter(
            \BCC\Core\PeepSo\PeepSoGroupWriter::$calls,
            static fn(string $c): bool => str_starts_with($c, 'join:')
        ));

        self::assertSame(['join:3:900'], $joins, 'the Hall owner, and nobody else');
    }

    public function testRefusedCreationFiresNoFanOutHook(): void
    {
        $this->boot();
        \HallTestState::$admins = [];

        (new HallProvisioningService())->provisionOne(self::CHAIN_ID);

        self::assertSame([], \HallTestState::actionNames());
    }

    // ── The retired automatic path ───────────────────────────────────────

    public function testThereIsNoBulkProvisionAllEntryPoint(): void
    {
        $this->boot();

        // Halls are administrator-created, one named chain at a time. A
        // rebuilt sweep would reintroduce exactly the coupling that was
        // retired: registering a chain would publish a public group.
        self::assertFalse(
            method_exists(HallProvisioningService::class, 'provisionAll'),
            'provisionAll() must stay retired'
        );
    }

    public function testEveryAuditActionFitsTheActionColumn(): void
    {
        foreach ([
            HallProvisioningService::AUDIT_CREATED,
            HallProvisioningService::AUDIT_FAILED,
            HallProvisioningService::AUDIT_INCOMPLETE,
            HallProvisioningService::AUDIT_COMPENSATED,
        ] as $action) {
            self::assertLessThanOrEqual(
                50,
                strlen($action),
                "audit action '{$action}' exceeds wp_bcc_trust_activity.action VARCHAR(50)"
            );
            self::assertStringStartsWith('admin_', $action);
        }
    }
}
