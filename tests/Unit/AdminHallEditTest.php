<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\AdminHallsService;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\HallRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

// The edit's PeepSo write runs inside bcc-core's real PeepSoAddPostGuard::pinOnly().
require_once __DIR__ . '/../Stubs/peepso-add-post-guard-real.php';

/**
 * The admin Halls edit (F-597, topic Halls step 3a): AdminHallsService::update().
 *
 *   - a topic Hall: name, description, topic label;
 *   - a chain Hall: description only (its name comes from its chain);
 *   - a Hall with neither: name and description;
 *   - values trimmed, checked by the create's rules, nothing written unless
 *     every change passes; a group that is not a Hall, or a read that cannot
 *     be trusted, writes nothing; a checked audit row, and a lost one is
 *     reported, never hidden.
 */
#[CoversClass(AdminHallsService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminHallEditTest extends TestCase
{
    private const TOPIC   = 501;
    private const CHAIN   = 502;
    private const NEITHER = 503;
    private const ADMIN   = 7;

    private function boot(): void
    {
        require_once __DIR__ . '/../Stubs/hall-provision-stubs.php';
        \HallTestState::reset();
        \BCC\Core\Log\Logger::reset();
        AuditLogger::reset();
        ChainRepository::reset();
        HallRepository::reset();

        foreach ([self::TOPIC => 'defi', self::CHAIN => 'cosmos-hall', self::NEITHER => 'old-hall'] as $id => $slug) {
            HallRepository::$isHall[$id] = true;
            PeepSoGroupRepository::$groups[$id] = (object) [
                'id' => (string) $id, 'post_name' => $slug, 'post_title' => 'Hall ' . $id, 'post_content' => 'About.', 'member_count' => '4',
            ];
        }
        HallRepository::$kindFacts[self::TOPIC] = ['hall_kind' => 'topic', 'topic' => ['slug' => 'defi', 'label' => 'DeFi']];
        HallRepository::$kindFacts[self::CHAIN] = ['hall_kind' => 'chain', 'topic' => null];
        ChainRepository::$slugsByGroup[self::CHAIN] = 'cosmos';
    }

    /** @param array<string, string> $changes */
    private static function edit(int $groupId, array $changes): array
    {
        return (new AdminHallsService())->update($groupId, $changes, self::ADMIN);
    }

    private static function assertNothingWritten(): void
    {
        self::assertSame([], \HallTestState::$updates, 'no PeepSo write');
        self::assertSame([], HallRepository::$labelWrites, 'no label write');
        self::assertSame([], AuditLogger::$rows, 'no audit row');
    }

    // ── what each kind may change ───────────────────────────────────────

    public function testATopicHallTakesANameADescriptionAndATopicLabel(): void
    {
        $this->boot();

        $result = self::edit(self::TOPIC, ['name' => 'DeFi Floor', 'description' => 'Swaps.', 'topic_label' => 'Decentralised finance']);

        self::assertArrayNotHasKey('error', $result);
        self::assertSame([[self::TOPIC, ['name' => 'DeFi Floor', 'description' => 'Swaps.']]], \HallTestState::$updates);
        self::assertSame([[self::TOPIC, 'Decentralised finance']], HallRepository::$labelWrites);
        self::assertFalse($result['audit_degraded']);

        $row = AuditLogger::$rows[0];
        self::assertSame([AdminHallsService::AUDIT_UPDATED, self::TOPIC, 'hall', self::ADMIN], [$row['action'], $row['targetId'], $row['targetType'], $row['userId']]);
        self::assertSame(['group_id' => self::TOPIC, 'hall_kind' => 'topic', 'fields' => 'description|name|topic_label'], $row['meta']);
    }

    public function testAChainHallTakesADescription(): void
    {
        $this->boot();

        $result = self::edit(self::CHAIN, ['description' => 'The Cosmos floor.']);

        self::assertArrayNotHasKey('error', $result);
        self::assertSame([[self::CHAIN, ['description' => 'The Cosmos floor.']]], \HallTestState::$updates);
        self::assertSame(['name' => false, 'description' => true, 'topic_label' => false], $result['hall']['editable']);
        self::assertSame('cosmos', $result['hall']['chain']);
    }

    /** @return array<string, array{int, array<string, string>}> */
    public static function refusedForTheKind(): array
    {
        return [
            'a chain Hall\'s name'            => [self::CHAIN, ['name' => 'New']],
            'a chain Hall\'s topic'           => [self::CHAIN, ['topic_label' => 'New']],
            'name and description on a chain' => [self::CHAIN, ['name' => 'New', 'description' => 'Fine.']],
            'a topic on a Hall without one'   => [self::NEITHER, ['topic_label' => 'New']],
        ];
    }

    /** ⭐ Nothing is written unless every change is allowed, not even the allowed ones. */
    #[DataProvider('refusedForTheKind')]
    public function testAChangeTheKindDoesNotAllowWritesNothing(int $groupId, array $changes): void
    {
        $this->boot();

        self::assertSame('bcc_invalid_request', self::edit($groupId, $changes)['error'] ?? null);
        self::assertNothingWritten();
    }

    public function testAHallWithNeitherKindTakesANameAndADescription(): void
    {
        $this->boot();

        $result = self::edit(self::NEITHER, ['name' => 'Old Hall', 'description' => 'Still here.']);

        self::assertArrayNotHasKey('error', $result);
        self::assertSame(['name' => true, 'description' => true, 'topic_label' => false], $result['hall']['editable']);
    }

    // ── the values ──────────────────────────────────────────────────────

    /** @return array<string, array{array<string, string>}> */
    public static function badValues(): array
    {
        return [
            'an empty name'        => [['name' => '   ']],
            'a long name'          => [['name' => str_repeat('n', 81)]],
            'an empty topic label' => [['topic_label' => '']],
            'a long topic label'   => [['topic_label' => str_repeat('l', 61)]],
            'a long description'   => [['description' => str_repeat('d', 1001)]],
            'an unknown field'     => [['slug' => 'defi-2']],
            'nothing at all'       => [[]],
        ];
    }

    /** @param array<string, string> $changes */
    #[DataProvider('badValues')]
    public function testABadValueWritesNothing(array $changes): void
    {
        $this->boot();

        self::assertSame('bcc_invalid_request', self::edit(self::TOPIC, $changes)['error'] ?? null);
        self::assertNothingWritten();
    }

    public function testValuesAreTrimmed(): void
    {
        $this->boot();

        self::edit(self::TOPIC, ['name' => '  DeFi Floor  ', 'topic_label' => ' DeFi ']);

        self::assertSame([[self::TOPIC, ['name' => 'DeFi Floor']]], \HallTestState::$updates);
        self::assertSame([[self::TOPIC, 'DeFi']], HallRepository::$labelWrites);
    }

    // ── refused before any write ────────────────────────────────────────

    public function testAGroupThatIsNotAHallIsNotFound(): void
    {
        $this->boot();

        self::assertSame('bcc_not_found', self::edit(999, ['description' => 'x'])['error'] ?? null);
        self::assertNothingWritten();
    }

    /** @return array<string, array{string}> */
    public static function untrustedReads(): array
    {
        return ['is it a Hall' => ['isHall'], 'its kind' => ['kindFacts']];
    }

    #[DataProvider('untrustedReads')]
    public function testAnUntrustedReadWritesNothing(string $read): void
    {
        $this->boot();
        HallRepository::${$read} = null;

        self::assertSame('bcc_unavailable', self::edit(self::TOPIC, ['description' => 'x'])['error'] ?? null);
        self::assertNothingWritten();
    }

    public function testAFailedPeepSoWriteIsUnavailableAndNotAudited(): void
    {
        $this->boot();
        \HallTestState::$updateThrows = true;

        self::assertSame('bcc_unavailable', self::edit(self::TOPIC, ['name' => 'New'])['error'] ?? null);
        self::assertSame([], AuditLogger::$rows);
    }

    // ── the audit ───────────────────────────────────────────────────────

    /** A correct edit whose audit row is lost is kept and reported, never silently undone. */
    public function testALostAuditRowIsReported(): void
    {
        $this->boot();
        AuditLogger::$failChecked = true;

        $result = self::edit(self::TOPIC, ['description' => 'Kept.']);

        self::assertTrue($result['audit_degraded']);
        self::assertSame([[self::TOPIC, ['description' => 'Kept.']]], \HallTestState::$updates);
    }
}
