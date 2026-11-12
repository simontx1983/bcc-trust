<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\FraudSignalWorld as W;
use BCC\Trust\Core\Security\FraudDetector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-107 (review M1): getEnhancedFraudScore() fails CLOSED on its user_info
 * read. getByUserId() answers null on a failed read, which blended as
 * "existing 0, peak 0" and under-counted the new score; the read is now
 * getByUserIdOrThrow(), so the post-vote retry (and the cron's per-user
 * catch) handle it instead.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EnhancedFraudScoreReadFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/fraud-signal-stubs.php';
        W::reset();
    }

    public function testAFailedUserInfoReadThrowsInsteadOfBlendingAsZero(): void
    {
        W::$userInfoReadFails = true;

        $this->expectException(RepositoryException::class);
        FraudDetector::getEnhancedFraudScore(7);
    }
}
