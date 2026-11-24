<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\GroupMembersService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A roster item's `card` fails closed per item (§4.7.7): a card that cannot
 * be built is `null` for that member only, logged, and the tile stays. A
 * PHP Error is a bug and is not swallowed.
 *
 * Which members get a card, and that it is the directory's card, are proven
 * on real rows in GroupRosterCardsIntegrationTest.
 *
 * ⚠ SEPARATE PROCESSES: setUp() declares a recording Logger before anything
 * can autoload bcc-core's.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupRosterCardTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\BCC\Core\Log\Logger::class, false)) {
            eval('namespace BCC\Core\Log; final class Logger {
                /** @var list<array{string, array<string, mixed>}> */
                public static array $lines = [];
                public static function error(string $m, array $c = []): void { self::$lines[] = [$m, $c]; }
            }');
        }
        \BCC\Core\Log\Logger::$lines = [];
    }

    /** @param \Closure(): (array<string, mixed>|null) $build */
    private static function cardOrNull(\Closure $build): ?array
    {
        $m = new ReflectionMethod(GroupMembersService::class, 'cardOrNull');
        /** @var array<string, mixed>|null */
        return $m->invoke(null, $build, 41, 4300);
    }

    public function testABuiltCardIsTheItemsCard(): void
    {
        self::assertSame(['id' => 41, 'card_kind' => 'member'], self::cardOrNull(static fn (): array => ['id' => 41, 'card_kind' => 'member']));
        self::assertSame([], \BCC\Core\Log\Logger::$lines);
    }

    public function testAMemberWhoVanishedHasNoCard(): void
    {
        self::assertNull(self::cardOrNull(static fn (): ?array => null));
    }

    public function testACardThatCannotBeBuiltIsNullAndLogged(): void
    {
        $failing = static function (): array {
            throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure('Repo', 'read', 'no answer');
        };

        self::assertNull(self::cardOrNull($failing));
        self::assertCount(1, \BCC\Core\Log\Logger::$lines);
        self::assertSame(41, \BCC\Core\Log\Logger::$lines[0][1]['user_id'] ?? null);
        self::assertSame(4300, \BCC\Core\Log\Logger::$lines[0][1]['group_id'] ?? null);
    }

    public function testABugInTheCardBuilderIsNotSwallowed(): void
    {
        $this->expectException(\TypeError::class);

        self::cardOrNull(static function (): array {
            throw new \TypeError('a bug');
        });
    }
}
