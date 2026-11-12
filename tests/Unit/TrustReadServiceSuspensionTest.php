<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Application\TrustReadService;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-109 pins what the suspension gates read.
 *
 * `Permissions::is_not_suspended()` delegates to this
 * `TrustReadService::isSuspended()`. The F-109 gates are on claims, avatars,
 * profile edits, the onboarding handle and watch, so a user without a
 * user_info row MUST read as NOT suspended. Production has users with no row
 * (95 users, 54 rows on 2026-11-10). Treating "no row" as suspended would
 * lock every one of them out of all of those paths.
 *
 * Plugin's repository is replaced by reflection with a double that answers
 * getByUserId() from a fixed map. Each test runs in its own process, so the
 * Plugin singleton never leaks.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TrustReadServiceSuspensionTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/trust-read-service-stubs.php';
    }

    /** @return iterable<string, array{0: ?object, 1: bool}> */
    public static function rows(): iterable
    {
        yield 'no user_info row is NOT a suspension' => [null, false];
        yield 'a row with is_suspended = 0'           => [(object) ['is_suspended' => '0'], false];
        yield 'a row with is_suspended = 1'           => [(object) ['is_suspended' => '1'], true];
    }

    #[DataProvider('rows')]
    public function testSuspensionIsReadFromTheRowAndAMissingRowIsNotSuspended(?object $row, bool $expected): void
    {
        self::assertSame($expected, self::service($row, false)->isSuspended(140));
    }

    /**
     * A READ FAILURE is neither suspended nor not: isSuspended() throws
     * bcc-core's SuspensionUnknownException, which Permissions turns into an
     * UNKNOWN state it never caches. It used to read as "no row", so every
     * gate failed open.
     */
    public function testAReadFailureIsUnknownNotNotSuspended(): void
    {
        $this->expectException(\BCC\Core\Contracts\SuspensionUnknownException::class);

        self::service(null, true)->isSuspended(140);
    }

    private static function service(?object $row, bool $readFails): TrustReadService
    {
        $repo = new class ($row, $readFails) extends UserInfoRepository {
            public function __construct(private ?object $row, private bool $readFails)
            {
            }

            public function getByUserIdOrThrow(int $userId): ?object
            {
                if ($this->readFails) {
                    throw new \BCC\Trust\Core\Exceptions\RepositoryException('simulated user_info read failure');
                }
                return $this->row;
            }
        };

        $property = (new ReflectionClass(Plugin::class))->getProperty('userInfoRepository');
        $property->setValue(Plugin::instance(), $repo);

        return new TrustReadService(
            (new ReflectionClass(VoteRepository::class))->newInstanceWithoutConstructor()
        );
    }
}
