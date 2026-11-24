<?php
/**
 * F-469: AnnouncementViewerStanding's good-standing flag gates WRITING an
 * announcement comment, and its docblock says it fails CLOSED. Its catch
 * never fired for a failed tier read, because getTier() answered 'neutral'
 * ("in good standing") instead of throwing. It now reads the tri-state, so
 * an unreadable tier denies.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Announcements\AnnouncementViewerStanding;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\ReputationRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnnouncementViewerStanding::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AnnouncementStandingUnknownTest extends TestCase
{
    protected function setUp(): void
    {
        // A Logger double (the failure is logged).
        require_once __DIR__ . '/../Stubs/reputation-row-stubs.php';
    }

    public function testAnUnreadableTierDeniesTheWrite(): void
    {
        self::assertFalse(self::standingWith(null));
    }

    public function testTheTierStillDecidesWhenItCanBeRead(): void
    {
        self::assertTrue(self::standingWith('neutral'));
        self::assertFalse(self::standingWith('caution'));
    }

    /** isInGoodStanding() with the tier reader answering $tier (null = unreadable). */
    private static function standingWith(?string $tier): bool
    {
        $rep = new class ($tier) extends ReputationRepository {
            public function __construct(private ?string $tier)
            {
            }

            public function getTierOrUnknown(int $userId): ?string
            {
                return $this->tier;
            }

            public function getTier(int $userId): string
            {
                // The lenient reader would have said neutral: never consulted.
                return 'neutral';
            }
        };
        (new \ReflectionClass(Plugin::class))->getProperty('reputationRepository')->setValue(Plugin::instance(), $rep);

        /** @var bool $standing */
        $standing = (new \ReflectionMethod(AnnouncementViewerStanding::class, 'isInGoodStanding'))->invoke(null, 7);

        return $standing;
    }
}
