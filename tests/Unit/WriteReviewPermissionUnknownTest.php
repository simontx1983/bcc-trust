<?php
/**
 * F-469: the card's `can_review` reads the write_review capability. A
 * standing that could not be read answers `unavailable` there, and the card
 * says the standing could not be checked. It never shows the requirement
 * hint, which would tell a member in good standing to reach a standing they
 * already have.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\Capability\CapabilityResolver;
use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\ValueObjects\CapabilityDecision;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WriteReviewPermissionUnknownTest extends TestCase
{
    private const REQUIREMENT = 'Become an Apprentice and keep your standing at Neutral or better to write reviews.';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/rank-unit-stubs.php';
    }

    public function testAnUnreadableStandingSaysItCouldNotBeChecked(): void
    {
        self::assertSame(
            [
                'allowed'     => false,
                'unlock_hint' => 'Your standing could not be checked right now. Please try again.',
                'reason_code' => 'unavailable',
            ],
            self::permissionFor(CapabilityDecision::denied('unavailable', 'feature_access'))
        );
    }

    public function testAMissingRequirementStillNamesIt(): void
    {
        self::assertSame(
            ['allowed' => false, 'unlock_hint' => self::REQUIREMENT, 'reason_code' => 'feature_locked'],
            self::permissionFor(CapabilityDecision::denied('feature_gate', 'feature_access'))
        );
    }

    public function testAnAllowedDecisionIsAllowed(): void
    {
        self::assertTrue(self::permissionFor(CapabilityDecision::allowed('feature_access'))['allowed']);
    }

    /** @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null} */
    private static function permissionFor(CapabilityDecision $decision): array
    {
        $capability = new class ($decision) extends CapabilityResolver {
            public function __construct(private CapabilityDecision $decision)
            {
            }

            public function can(int $userId, string $key, array $context = []): CapabilityDecision
            {
                return $key === 'write_review' ? $this->decision : CapabilityDecision::allowed('test');
            }
        };

        $card = (new \ReflectionClass(CardViewService::class))->newInstanceWithoutConstructor();
        (new ReflectionObject($card))->getProperty('capabilityResolver')->setValue($card, $capability);

        /** @var array{allowed: bool, unlock_hint: string|null, reason_code: string|null} $perm */
        $perm = (new ReflectionMethod(CardViewService::class, 'writeReviewPermission'))->invoke($card, 7);

        return $perm;
    }
}
