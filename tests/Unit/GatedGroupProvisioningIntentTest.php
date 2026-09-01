<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\GatedGroupProvisioningService;
use BCC\Trust\Onchain\ValueObjects\ProvisioningFailureCode;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Provisioning, once intent exists.
 *
 * ── THE GATE THIS FILE EXISTS FOR ───────────────────────────────────────
 * `provisionOne()` refuses anything not in `requested`. That single guard is
 * what makes "verification alone never provisions" true for EVERY caller at
 * once — the cron, the bulk button and the per-row AJAX all go through it —
 * rather than a property each entry point has to remember to preserve.
 *
 * The rest is about not leaving wreckage: a group created but never gated is
 * invisible to `findGroupForCollection()`, so the NEXT run cannot see it and
 * creates a duplicate. That is the defect PR 6 inherited and closes.
 */
#[CoversClass(GatedGroupProvisioningService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GatedGroupProvisioningIntentTest extends TestCase
{
    private const CID      = 42;
    private const OPERATOR = 7;
    private const ADDRESS  = '0x1234567890abcdef1234567890abcdef12345678';

    private GatedGroupProvisioningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/provisioning-intent-stubs.php';

        // ⚠ A class cannot be un-declared, so "PeepSo Groups is not
        // installed" is only reachable by NOT declaring it. That works here
        // because every test runs in its own process — see the attribute on
        // this class. Skipping the require IS the fixture for that one case.
        if (!str_contains($this->name(), 'PeepSoBeingAbsent')) {
            require_once __DIR__ . '/../Stubs/peepso-group-fakes.php';
        }

        \BccAdminTestState::reset();
        \BccObjectCacheSpy::reset();
        \BccPeepSoSpy::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Core\Observability\DegradationMetrics::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Core\Security\TransactionManager::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();

        $this->service = new GatedGroupProvisioningService();
    }

    private function seed(string $state = ProvisioningState::REQUESTED, int $verified = 1): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(4, 'ethereum', 'evm');
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID] = (object) [
            'id'                   => self::CID,
            'chain_id'             => 4,
            'contract_address'     => self::ADDRESS,
            'canonical_identifier' => self::ADDRESS,
            'collection_name'      => 'Seeded Collection',
            'is_verified'          => $verified,
            'chain_type'           => 'evm',
            'chain_slug'           => 'ethereum',
        ];

        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID] =
            $state === ProvisioningState::NONE
                ? ['state' => 'none', 'at' => null, 'by' => null, 'code' => null]
                : [
                    'state' => $state,
                    'at'    => '2026-09-01 00:00:00',
                    'by'    => self::OPERATOR,
                    'code'  => $state === ProvisioningState::FAILED ? 'group_create_failed' : null,
                ];
    }

    private function state(): string
    {
        return \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID]['state'] ?? 'none';
    }

    // ── The intent gate ─────────────────────────────────────────────────

    /**
     * The core claim of PR 6, stated as a data-driven sweep so a new state
     * cannot quietly become provisionable.
     *
     * @return list<array{0: string}>
     */
    public static function statesThatMustNotProvision(): array
    {
        return [
            'none — verified but nobody asked' => [ProvisioningState::NONE],
            'failed — needs a fresh request'   => [ProvisioningState::FAILED],
            'provisioned — already done'       => [ProvisioningState::PROVISIONED],
        ];
    }

    #[DataProvider('statesThatMustNotProvision')]
    public function testOnlyARecordedRequestCanProduceACommunity(string $state): void
    {
        $this->seed($state);

        $result = $this->service->provisionOne(self::CID);

        self::assertNotSame('created', $result['status'], $state . ' must not create a community');
        self::assertSame(
            0,
            \BccPeepSoSpy::$created,
            'no PeepSo group may be created from state ' . $state
        );
    }

    /**
     * A VERIFIED collection with no request is the exact shape that used to
     * be auto-provisioned. It is the regression this PR exists to prevent.
     */
    public function testAVerifiedCollectionWithNoRequestIsSkipped(): void
    {
        $this->seed(ProvisioningState::NONE, verified: 1);

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('skipped', $result['status']);
        self::assertStringContainsString('no community has been requested', strtolower($result['message']));
        self::assertSame(0, \BccPeepSoSpy::$created);
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
    }

    /**
     * The sweep drains RECORDED REQUESTS, not verified rows. Seeding two
     * verified collections where only one is requested proves the selection,
     * not merely that provisioning works.
     */
    public function testTheSweepTouchesOnlyRequestedCollections(): void
    {
        $this->seed(ProvisioningState::REQUESTED);

        // A second verified collection nobody asked about.
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[99] = (object) [
            'id'                   => 99,
            'chain_id'             => 4,
            'contract_address'     => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'canonical_identifier' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'collection_name'      => 'Unrequested',
            'is_verified'          => 1,
            'chain_type'           => 'evm',
            'chain_slug'           => 'ethereum',
        ];
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[99] =
            ['state' => 'none', 'at' => null, 'by' => null, 'code' => null];

        $result = $this->service->processRequested();

        self::assertSame(1, $result['created']);
        self::assertSame(1, \BccPeepSoSpy::$created, 'exactly one community, for the requested row');
        self::assertSame(
            'none',
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[99]['state']
        );
    }

    // ── Preconditions checked before anything is created ────────────────

    public function testVerificationIsRecheckedAtProvisionTime(): void
    {
        $this->seed(ProvisioningState::REQUESTED, verified: 0);

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('failed', $result['status']);
        self::assertSame(ProvisioningFailureCode::NOT_VERIFIED, $result['failure_code']);
        self::assertSame(0, \BccPeepSoSpy::$created);
        self::assertSame(ProvisioningState::FAILED, $this->state());
    }

    /**
     * ⚠ THE ORDERING FIX.
     *
     * `writeGateConfig()` refuses on exactly one condition — the identity not
     * canonicalising — so checking it FIRST makes that refusal unreachable.
     * Before PR 6 the group was created and only then refused, leaving an
     * ungated community the next run could not see.
     */
    public function testAnUnresolvedIdentityIsRefusedBeforeAnyGroupIsCreated(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->canonical_identifier = null;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('failed', $result['status']);
        self::assertSame(ProvisioningFailureCode::IDENTITY_UNRESOLVED, $result['failure_code']);
        self::assertSame(0, \BccPeepSoSpy::$created, 'nothing may be created for an unsatisfiable gate');
    }

    public function testAnIdentityThatDoesNotValidateForItsChainIsAlsoRefusedFirst(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        // Stored, non-empty, and not a valid EVM address.
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->canonical_identifier = 'not-an-address';

        $result = $this->service->provisionOne(self::CID);

        self::assertSame(ProvisioningFailureCode::IDENTITY_UNRESOLVED, $result['failure_code']);
        self::assertSame(0, \BccPeepSoSpy::$created);
    }

    public function testACollectionWithNoNameIsRefusedBeforeCreation(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->collection_name = '';

        $result = $this->service->provisionOne(self::CID);

        self::assertSame(ProvisioningFailureCode::AWAITING_METADATA, $result['failure_code']);
        self::assertSame(0, \BccPeepSoSpy::$created);
    }

    // ── The owner is the requester, never a guess ───────────────────────

    public function testTheCommunityIsOwnedByTheAdministratorWhoRequestedIt(): void
    {
        $this->seed(ProvisioningState::REQUESTED);

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('created', $result['status']);
        self::assertSame(
            self::OPERATOR,
            \BccPeepSoSpy::$lastOwnerId,
            'ownership follows the recorded authorization, not the lowest admin id'
        );
    }

    public function testARequesterWhoNoLongerExistsFailsClosedRatherThanSubstituting(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BccAdminTestState::$knownUserIds = [1, 2];

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('failed', $result['status']);
        self::assertSame(ProvisioningFailureCode::OWNER_UNRESOLVED, $result['failure_code']);
        self::assertSame(0, \BccPeepSoSpy::$created, 'no community is handed to somebody who never asked');
    }

    public function testARequesterWhoLostTheCapabilityFailsClosed(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BccAdminTestState::$capableUserIds = [1];

        $result = $this->service->provisionOne(self::CID);

        self::assertSame(ProvisioningFailureCode::OWNER_UNRESOLVED, $result['failure_code']);
        self::assertSame(0, \BccPeepSoSpy::$created);
    }

    // ── The happy path, and its postconditions ─────────────────────────

    public function testASuccessfulProvisioningWritesTheGateStateAndCheckedAuditTogether(): void
    {
        $this->seed(ProvisioningState::REQUESTED);

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('created', $result['status']);
        self::assertSame(ProvisioningState::PROVISIONED, $this->state());

        // The requester survives onto the provisioned row.
        self::assertSame(
            self::OPERATOR,
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID]['by']
        );

        $audit = \BCC\Trust\Core\Security\AuditLogger::$rows[0];
        self::assertSame(GatedGroupProvisioningService::AUDIT_PROVISIONED, $audit['action']);
        self::assertSame(self::OPERATOR, $audit['meta']['operator_user_id']);
        self::assertSame(ProvisioningState::REQUESTED, $audit['meta']['previous_state']);
        self::assertSame(ProvisioningState::PROVISIONED, $audit['meta']['new_state']);
    }

    public function testTheMetaCacheIsDroppedAfterAGateWrite(): void
    {
        $this->seed(ProvisioningState::REQUESTED);

        $this->service->provisionOne(self::CID);

        $groups = array_filter(
            \BccObjectCacheSpy::$deleted,
            static fn (array $d): bool => $d['group'] === 'post_meta'
        );
        self::assertNotSame([], $groups, 'raw gate-meta writes do not maintain WP meta cache for us');
    }

    /** An existing community is adopted, not duplicated. */
    public function testAnExistingCommunityIsRecordedRatherThanRecreated(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groups['4|' . self::ADDRESS] = 555;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('exists', $result['status']);
        self::assertSame(555, $result['group_id']);
        self::assertSame(0, \BccPeepSoSpy::$created);
        self::assertSame(
            ProvisioningState::PROVISIONED,
            $this->state(),
            'the row catches up with reality instead of asking again tomorrow'
        );
    }

    // ── Failure and compensation ────────────────────────────────────────

    public function testAFailedGroupCreationRecordsABoundedCodeAndNoProse(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BccPeepSoSpy::$createReturnsZero = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('failed', $result['status']);
        self::assertSame(ProvisioningFailureCode::GROUP_CREATE_FAILED, $result['failure_code']);
        self::assertSame(ProvisioningState::FAILED, $this->state());

        $code = \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID]['code'];
        self::assertTrue(
            ProvisioningFailureCode::isValid((string) $code),
            'only a bounded code may become durable, never a message'
        );
    }

    /**
     * The failure the whole compensation design exists for.
     *
     * When finalization fails AFTER the group was created, the group must be
     * removed — provably, not hopefully — or the next run cannot see it and
     * creates a second one.
     */
    public function testAFailedCheckedAuditCompensatesTheGroupAway(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('failed', $result['status']);
        self::assertSame(ProvisioningFailureCode::GATE_WRITE_REFUSED, $result['failure_code']);

        self::assertSame(1, \BccPeepSoSpy::$created, 'the group WAS created…');
        self::assertSame(1, \BccPeepSoSpy::$deleted, '…and was then removed');
        self::assertSame(1, \BccPeepSoSpy::$memberLeaves, 'its owner membership is removed too');

        self::assertSame(ProvisioningState::FAILED, $this->state());
    }

    /**
     * The postcondition re-read is not decoration.
     *
     * `writeGateConfig()` reporting success is not the same as the gate
     * being readable afterwards — a partially applied meta write returns
     * true and leaves a gate that resolves to nothing. Re-reading it inside
     * the transaction is what turns that into a rollback instead of a
     * community nobody can join.
     */
    public function testAGateThatDoesNotSurviveItsReReadRollsBackAndCompensates(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$postconditionFails = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('failed', $result['status']);
        self::assertSame(ProvisioningFailureCode::GATE_WRITE_REFUSED, $result['failure_code']);
        self::assertSame(ProvisioningState::FAILED, $this->state());
        self::assertSame(1, \BccPeepSoSpy::$deleted, 'the unusable community is removed');
    }

    /**
     * And a gate write that refuses outright — the branch the ordering fix
     * makes unreachable — still rolls back rather than committing a
     * half-provisioned row, because "unreachable" is a claim about today's
     * callers, not a guarantee about tomorrow's.
     */
    public function testARefusedGateWriteStillRollsBack(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$writeRefuses = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('failed', $result['status']);
        self::assertSame(ProvisioningState::FAILED, $this->state());
        self::assertSame(1, \BccPeepSoSpy::$deleted);
    }

    public function testCompensationRemovesTheGateMetaAndTheImageDirectory(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $this->service->provisionOne(self::CID);

        self::assertSame(
            [],
            \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groups,
            'no gate may survive a compensated provisioning'
        );
        self::assertGreaterThan(0, \BccPeepSoSpy::$metaDeletes, 'gate meta is deleted explicitly');
        self::assertTrue(\BccPeepSoSpy::$imageDirRemoved, 'the album directory the hook mkdir-ed is removed');
    }

    /**
     * Compensation writes a bounded audit trail — including whether an
     * irreversible admin e-mail may already have gone out. Reporting that
     * honestly is the point: the community is gone, the e-mail is not.
     */
    public function testCompensationIsAuditedWithBoundedEvidence(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;

        $this->service->provisionOne(self::CID);

        $rows = array_values(array_filter(
            \BCC\Trust\Core\Security\AuditLogger::$rows,
            static fn (array $r): bool => $r['action'] === GatedGroupProvisioningService::AUDIT_COMPENSATED
        ));

        self::assertCount(1, $rows);
        $meta = $rows[0]['meta'];
        self::assertSame('clean', $meta['error_code'], 'nothing was left behind');
        self::assertArrayHasKey('admin_email_sent', $meta);
        self::assertSame(ProvisioningFailureCode::GATE_WRITE_REFUSED, $meta['failure_code']);
    }

    /**
     * And after compensation a RETRY creates exactly one community — the
     * duplicate the old ordering produced.
     */
    public function testARetryAfterCompensationCreatesExactlyOneCommunity(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = true;
        $this->service->provisionOne(self::CID);

        // The operator retries: failed -> requested, then the sweep runs.
        \BCC\Trust\Core\Security\AuditLogger::$failChecked = false;
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID] = [
            'state' => ProvisioningState::REQUESTED,
            'at'    => '2026-09-01 00:00:00',
            'by'    => self::OPERATOR,
            'code'  => null,
        ];

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('created', $result['status']);
        self::assertSame(
            2,
            \BccPeepSoSpy::$created,
            'two attempts were made in total…'
        );
        self::assertSame(
            1,
            \BccPeepSoSpy::$created - \BccPeepSoSpy::$deleted,
            '…but exactly one community survives'
        );
    }

    // ── The degradation taxonomy is NOT extended by PR 6 ────────────────

    /**
     * bcc-core declares exactly three `gated_group_provision` events. A
     * fourth would need bcc-core plus pattern-registry.md plus
     * GOLDEN_PATHS.md to land together, and `subsystem-count-guard.php`
     * would hold umbrella CI red until they did.
     *
     * PR 6 therefore adds none — the new failure modes live in
     * `provisioning_failure_code` instead. This asserts that every event any
     * PR 6 path emits is one of the declared three.
     *
     * @return list<array{0: callable(self): void}>
     */
    public function testNoFailurePathEmitsAnUndeclaredDegradationEvent(): void
    {
        $declared = ['peepso_absent', 'no_admin_owner', 'group_create_failed'];

        // Walk the failure modes that reach a metric at all.
        $this->seed(ProvisioningState::REQUESTED);
        \BccPeepSoSpy::$createReturnsZero = true;
        $this->service->provisionOne(self::CID);

        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID] = [
            'state' => ProvisioningState::REQUESTED,
            'at'    => '2026-09-01 00:00:00',
            'by'    => self::OPERATOR,
            'code'  => null,
        ];
        \BccAdminTestState::$knownUserIds = [1];
        $this->service->provisionOne(self::CID);

        self::assertNotSame(
            [],
            \BCC\Core\Observability\DegradationMetrics::$recorded,
            'the sweep must still emit the events it always did'
        );

        foreach (\BCC\Core\Observability\DegradationMetrics::$recorded as $entry) {
            self::assertSame('gated_group_provision', $entry['subsystem']);
            self::assertContains(
                $entry['event'],
                $declared,
                'PR 6 must not introduce a fourth gated_group_provision event'
            );
        }
    }

    /**
     * And the two purely PR 6 refusals — an unresolved identity and an
     * unresolvable requester — record a bounded CODE without inventing a
     * metric for it.
     */
    public function testTheNewRefusalsRecordACodeAndNoNewMetric(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->canonical_identifier = null;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame(ProvisioningFailureCode::IDENTITY_UNRESOLVED, $result['failure_code']);
        self::assertSame(
            [],
            \BCC\Core\Observability\DegradationMetrics::$recorded,
            'an unresolved identity has no declared event, so it emits none'
        );
    }

    // ── PeepSo absent ───────────────────────────────────────────────────

    public function testPeepSoBeingAbsentIsItsOwnBoundedFailure(): void
    {
        $this->seed(ProvisioningState::REQUESTED);
        \BccPeepSoSpy::$classAvailable = false;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame('failed', $result['status']);
        self::assertSame(ProvisioningFailureCode::PEEPSO_ABSENT, $result['failure_code']);
    }
}
