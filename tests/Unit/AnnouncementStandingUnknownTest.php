<?php
/**
 * F-469: AnnouncementViewerStanding's good-standing flag gates WRITING an
 * announcement comment, and its docblock says it fails CLOSED. Its catch
 * never fired for a failed tier read, because getTier() answered 'neutral'
 * ("in good standing") instead of throwing. It now reads the tri-state, so
 * an unreadable tier is UNKNOWN (null): never good standing, and forViewer()
 * reports it as `standing_known: false`, which the comment gate answers with
 * its own retryable `unavailable`.
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

    public function testAnUnreadableTierIsUnknownNotGoodStanding(): void
    {
        self::assertNull(self::standingWith(null));
    }

    public function testTheTierStillDecidesWhenItCanBeRead(): void
    {
        self::assertTrue(self::standingWith('neutral'));
        self::assertFalse(self::standingWith('caution'));
    }

    /**
     * The producer, executed: an unreadable tier is neither good standing nor
     * KNOWN, so the comment gate answers `unavailable` rather than the
     * `suspended` a member below Neutral gets.
     */
    public function testForViewerReportsAnUnreadableTierAsNotKnown(): void
    {
        $standing = self::forViewerWith(null);

        self::assertFalse($standing['good_standing']);
        self::assertFalse($standing['standing_known']);
    }

    public function testForViewerReportsAReadTierAsKnownEitherWay(): void
    {
        $good = self::forViewerWith('neutral');
        self::assertTrue($good['good_standing']);
        self::assertTrue($good['standing_known']);

        $below = self::forViewerWith('caution');
        self::assertFalse($below['good_standing']);
        self::assertTrue($below['standing_known'], 'below Neutral is an answer, not unknown');
    }

    /**
     * forViewer() for member 7. Without PeepSo the fraud lookup answers false;
     * the suspension seam is not loaded here and fails closed, which this
     * test does not read.
     *
     * @return array<string, mixed>
     */
    private static function forViewerWith(?string $tier): array
    {
        self::installTier($tier);

        return AnnouncementViewerStanding::forViewer(7);
    }

    /** isInGoodStanding() with the tier reader answering $tier (null = unreadable). */
    private static function standingWith(?string $tier): ?bool
    {
        self::installTier($tier);

        /** @var bool|null $standing */
        $standing = (new \ReflectionMethod(AnnouncementViewerStanding::class, 'isInGoodStanding'))->invoke(null, 7);

        return $standing;
    }

    /** The Plugin's reputation repository answers $tier (null = unreadable). */
    private static function installTier(?string $tier): void
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
    }
}
