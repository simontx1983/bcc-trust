<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\RelationshipLogVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Without BCC_ENCRYPTION_KEY the chain cannot be checked. The verifier says
 * so (`key_unavailable`, not ok) instead of throwing, and reads nothing.
 *
 * Separate process: BCC_ENCRYPTION_KEY must be undefined here.
 */
#[CoversClass(RelationshipLogVerifier::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RelationshipLogVerifierKeyTest extends TestCase
{
    public function testAMissingKeyIsReportedNotThrown(): void
    {
        self::assertFalse(defined('BCC_ENCRYPTION_KEY'), 'precondition: no key in this process');

        self::assertSame(
            ['ok' => false, 'rows_checked' => 0, 'head_seq' => 0, 'first_bad_seq' => null, 'reason' => 'key_unavailable'],
            RelationshipLogVerifier::verify()
        );
    }
}
