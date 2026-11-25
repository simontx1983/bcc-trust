<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Core\DB\AdvisoryLock;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Repositories\HallRepository;
use BCC\Trust\Onchain\Services\HallProvisioningService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

// The create runs inside bcc-core's real PeepSoAddPostGuard::pinOnly(), as
// in HallProvisioningServiceTest (see the note there).
require_once __DIR__ . '/../Stubs/peepso-add-post-guard-real.php';

/**
 * Topic Halls (F-597): HallProvisioningService::provisionTopic() creates an
 * official, open Hall about a subject, through the same pipeline as a chain
 * Hall: per-topic lock, existence check inside it, PeepSo's create, the
 * marker and its proof (compensated if it does not stick), the deterministic
 * owner, and a checked audit. The topic's slug is the Hall's address.
 */
#[CoversClass(HallProvisioningService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TopicHallProvisioningTest extends TestCase
{
    private function boot(): void
    {
        require_once __DIR__ . '/../Stubs/hall-provision-stubs.php';
        \HallTestState::reset();
        \FakePeepSoLedger::reset();
        AdvisoryLock::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Core\PeepSo\PeepSoGroupWriter::reset();
        AuditLogger::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        HallRepository::reset();
    }

    /** @return array{status: string, group_id: int, message: string, failure_code: string|null, owner_id: int, audit_degraded: bool} */
    private function create(string $name = 'DeFi Hall', string $slug = 'defi', string $label = 'DeFi', string $description = 'Lending, swaps and yield.'): array
    {
        return (new HallProvisioningService())->provisionTopic($name, $slug, $label, $description);
    }

    private static function assertNothingCreated(): void
    {
        self::assertSame([], \HallTestState::$created, 'no group created');
        self::assertSame([], HallRepository::$metaWrites, 'no marker written');
    }

    // ── created ─────────────────────────────────────────────────────────

    public function testATopicHallIsCreatedOpenAtItsTopicsAddress(): void
    {
        $this->boot();

        $result = $this->create();

        self::assertSame('created', $result['status']);
        self::assertSame(900, $result['group_id']);
        self::assertSame(3, $result['owner_id'], 'the deterministic owner, not the clicker');

        $data = \HallTestState::$created[0];
        self::assertSame('DeFi Hall', $data['name']);
        self::assertSame('Lending, swaps and yield.', $data['description']);
        self::assertSame('defi', $data['slug'], 'the topic slug is the address');
        self::assertSame(0, $data['meta']['privacy'], 'open');
        self::assertTrue($data['meta']['is_joinable']);
        self::assertTrue($data['meta']['is_auto_accept_join_request']);

        self::assertSame([[900, 'defi', 'DeFi']], HallRepository::$metaWrites, 'kind, topic and label');
        self::assertSame(900, HallRepository::findHallForTopic('defi'));
    }

    public function testTheCreateIsAuditedAgainstTheHall(): void
    {
        $this->boot();

        $this->create();

        $rows = AuditLogger::$rows;
        self::assertCount(1, $rows);
        self::assertSame(HallProvisioningService::AUDIT_CREATED, $rows[0]['action']);
        self::assertSame('hall', $rows[0]['targetType']);
        self::assertSame(900, $rows[0]['targetId']);
        self::assertSame(7, $rows[0]['userId']);
        self::assertSame('defi', $rows[0]['meta']['topic_slug']);
        self::assertSame(900, $rows[0]['meta']['group_id']);
        self::assertSame(7, $rows[0]['meta']['operator_user_id']);
        self::assertSame(3, $rows[0]['meta']['owner_user_id']);
        self::assertArrayNotHasKey('chain_id', $rows[0]['meta']);
    }

    /** `bcc_hall_provisioned` carries a chain (groupId, chainId, chainSlug), so a topic Hall does not fire it. */
    public function testATopicHallDoesNotFireTheChainHallHook(): void
    {
        $this->boot();

        $this->create();

        self::assertNotContains('bcc_hall_provisioned', \HallTestState::actionNames());
    }

    /** Inputs are trimmed before they are checked and stored. */
    public function testInputsAreTrimmed(): void
    {
        $this->boot();

        $result = $this->create('  DeFi Hall  ', ' defi ', '  DeFi ', '  Lending.  ');

        self::assertSame('created', $result['status']);
        self::assertSame(['DeFi Hall', 'defi', 'Lending.'], [\HallTestState::$created[0]['name'], \HallTestState::$created[0]['slug'], \HallTestState::$created[0]['description']]);
        self::assertSame([[900, 'defi', 'DeFi']], HallRepository::$metaWrites);
    }

    // ── refused before anything is created ──────────────────────────────

    public function testATopicThatHasAHallCreatesNothing(): void
    {
        $this->boot();
        HallRepository::$topicHalls['defi'] = 55;

        $result = $this->create();

        self::assertSame(['exists', 55], [$result['status'], $result['group_id']]);
        self::assertSame('This topic already has a Hall.', $result['message']);
        self::assertNothingCreated();
        self::assertSame([], AuditLogger::$rows);
    }

    /** ⭐ A group already at the address is a refusal, never a silently suffixed address. */
    public function testATakenAddressCreatesNothing(): void
    {
        $this->boot();
        HallRepository::$slugsInUse = ['defi'];

        $result = $this->create();

        self::assertSame(['skipped', HallProvisioningService::FAIL_SLUG_TAKEN], [$result['status'], $result['failure_code']]);
        self::assertNothingCreated();
        self::assertSame([], AuditLogger::$rows, 'a refusal is not audited');
    }

    /** @return array<string, array{string, string, string, string}> name, slug, label, description */
    public static function invalidInputs(): array
    {
        return [
            'no name'                  => ['', 'defi', 'DeFi', ''],
            'a long name'              => [str_repeat('n', 81), 'defi', 'DeFi', ''],
            'markup in the name'       => ['<b>DeFi</b>', 'defi', 'DeFi', ''],
            'no label'                 => ['DeFi Hall', 'defi', '', ''],
            'a long label'             => ['DeFi Hall', 'defi', str_repeat('l', 61), ''],
            'markup in the label'      => ['DeFi Hall', 'defi', '<i>DeFi</i>', ''],
            'a long description'       => ['DeFi Hall', 'defi', 'DeFi', str_repeat('d', 1001)],
            'an uppercase slug'        => ['DeFi Hall', 'DeFi', 'DeFi', ''],
            'a slug with a space'      => ['DeFi Hall', 'de fi', 'DeFi', ''],
            'a double hyphen'          => ['DeFi Hall', 'de--fi', 'DeFi', ''],
            'a leading hyphen'         => ['DeFi Hall', '-defi', 'DeFi', ''],
            'a long slug'              => ['DeFi Hall', str_repeat('a', 51), 'DeFi', ''],
            'digits only'              => ['DeFi Hall', '2024', 'DeFi', ''],
            'digits and hyphens only'  => ['DeFi Hall', '20-24', 'DeFi', ''],
            'a chain Hall address'     => ['DeFi Hall', 'defi-hall', 'DeFi', ''],
            'just hall'                => ['DeFi Hall', 'hall', 'DeFi', ''],
            'a reserved path word'     => ['DeFi Hall', 'peepso', 'DeFi', ''],
            'PeepSo\'s blocklist'      => ['DeFi Hall', 'category', 'DeFi', ''],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testAnInvalidTopicHallCreatesNothing(string $name, string $slug, string $label, string $description): void
    {
        $this->boot();

        $result = $this->create($name, $slug, $label, $description);

        self::assertSame(['skipped', HallProvisioningService::FAIL_TOPIC_INVALID], [$result['status'], $result['failure_code']]);
        self::assertNotSame('', $result['message']);
        self::assertNothingCreated();
        self::assertSame([], AdvisoryLock::$acquired, 'refused before the lock');
        self::assertSame([], AuditLogger::$rows);
    }

    public function testTheLongestValidInputsPass(): void
    {
        $this->boot();

        $result = $this->create(str_repeat('é', 80), str_repeat('a', 50), str_repeat('é', 60), str_repeat('é', 1000));

        self::assertSame('created', $result['status'], 'lengths are characters, not bytes');
    }

    /** An existence or address check that cannot be read creates nothing, and says so. */
    public function testAnUnreadableCheckCreatesNothing(): void
    {
        $this->boot();
        HallRepository::$topicReadsThrow = true;

        $result = $this->create();

        self::assertSame(['error', HallProvisioningService::FAIL_READ_UNVERIFIED], [$result['status'], $result['failure_code']]);
        self::assertNothingCreated();
        self::assertSame([HallProvisioningService::AUDIT_FAILED], AuditLogger::actions());
        self::assertSame([], AdvisoryLock::$held, 'the lock is released');
    }

    // ── the lock ────────────────────────────────────────────────────────

    public function testATopicHeldByAnotherRunIsBusyAndCreatesNothing(): void
    {
        $this->boot();
        $this->create('Other', 'other', 'Other');            // learn this topic's lock name
        $lock = AdvisoryLock::$acquired[0];
        $this->boot();
        AdvisoryLock::$held[$lock] = true;                   // another run holds 'other'

        $busy = $this->create('Other', 'other', 'Other');
        self::assertSame(['busy', HallProvisioningService::FAIL_LOCK_CONTENDED], [$busy['status'], $busy['failure_code']]);
        self::assertNothingCreated();

        self::assertSame('created', $this->create()['status'], 'another topic is not blocked');
    }

    public function testTheLockIsPerTopicAndFitsMySql(): void
    {
        $this->boot();

        $this->create();
        $this->create('NFT Hall', 'nfts', 'NFTs');

        [$defi, $nfts] = AdvisoryLock::$acquired;
        self::assertNotSame($defi, $nfts);
        self::assertLessThanOrEqual(64, strlen($defi), 'MySQL lock names are at most 64 characters');
        self::assertStringStartsWith('bcc_hall_provision_topic:', $defi);
        self::assertNotSame('bcc_hall_provision:0', $defi, 'never a chain lock');
        self::assertSame(AdvisoryLock::$acquired, AdvisoryLock::$released, 'one release per acquire');
    }

    // ── created, then rolled back ───────────────────────────────────────

    public function testAMarkerThatDoesNotStickIsRolledBack(): void
    {
        $this->boot();
        HallRepository::$writeMetaWorks = false;

        $result = $this->create();

        self::assertSame(['error', HallProvisioningService::FAIL_MARKER_REFUSED], [$result['status'], $result['failure_code']]);
        self::assertSame('The Hall could not be linked to its topic and was rolled back.', $result['message']);
        self::assertContains(900, \HallTestState::$deletedPosts, 'the group is deleted');
        self::assertContains([900, '_bcc_hall_topic'], \HallTestState::$deletedMeta, 'its topic marker is removed');
        self::assertContains([900, '_bcc_hall_topic_label'], \HallTestState::$deletedMeta);
        self::assertContains([900, '_bcc_group_kind'], \HallTestState::$deletedMeta);
        self::assertSame([HallProvisioningService::AUDIT_COMPENSATED, HallProvisioningService::AUDIT_FAILED], AuditLogger::actions());
        self::assertSame(HallProvisioningService::FAIL_MARKER_REFUSED, AuditLogger::$rows[0]['meta']['failure_code']);
    }

    /** ⭐ The group must keep the topic's address; one that got another is rolled back, never left at a stray address. */
    public function testAnAddressThatDoesNotStickIsRolledBack(): void
    {
        $this->boot();
        HallRepository::$slugOverride = 'defi-2';

        $result = $this->create();

        self::assertSame(['error', HallProvisioningService::FAIL_SLUG_REFUSED], [$result['status'], $result['failure_code']]);
        self::assertContains(900, \HallTestState::$deletedPosts);
        self::assertSame(HallProvisioningService::FAIL_SLUG_REFUSED, AuditLogger::$rows[0]['meta']['failure_code']);
        self::assertSame('defi', AuditLogger::$rows[0]['meta']['topic_slug']);
    }

    // ── created, ownership not reconciled ───────────────────────────────

    public function testOwnershipThatCannotBeSetIsMarkedForRepair(): void
    {
        $this->boot();
        \BCC\Core\PeepSo\PeepSoGroupWriter::$joinResult = false;

        $result = $this->create();

        self::assertSame('incomplete', $result['status']);
        self::assertStringContainsString('create the same topic Hall again', $result['message']);
        self::assertTrue(HallRepository::isOwnerIncomplete(900));
        self::assertSame([HallProvisioningService::AUDIT_INCOMPLETE], AuditLogger::actions());
        self::assertNotContains('bcc_hall_provisioned', \HallTestState::actionNames());
    }
}
