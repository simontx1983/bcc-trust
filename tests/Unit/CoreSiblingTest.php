<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\CoreSibling;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;

/**
 * F-94: a missing bcc-core file FAILS in CI and only skips locally.
 * Separate processes because the cases set CI environment variables.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CoreSiblingTest extends TestCase
{
    private const MISSING = 'src/__definitely_not_a_core_file__.php';

    public function testAMissingFileFailsOnGitHubActions(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('CI');

        try {
            CoreSibling::require(self::MISSING);
        } catch (AssertionFailedError $e) {
            self::assertNotInstanceOf(SkippedTest::class, $e, 'a skip is not a failure');
            self::assertStringContainsString('while running in CI', $e->getMessage());
            return;
        }
        self::fail('expected a failure in CI');
    }

    public function testAMissingFileFailsWhenCiIsTrue(): void
    {
        putenv('GITHUB_ACTIONS');
        putenv('CI=true');

        try {
            CoreSibling::require(self::MISSING);
        } catch (AssertionFailedError $e) {
            self::assertNotInstanceOf(SkippedTest::class, $e, 'a skip is not a failure');
            return;
        }
        self::fail('expected a failure in CI');
    }

    public function testAMissingFileSkipsLocally(): void
    {
        putenv('GITHUB_ACTIONS');
        putenv('CI');

        try {
            CoreSibling::require(self::MISSING);
        } catch (SkippedTest $e) {
            self::assertStringContainsString('expected outside CI', $e->getMessage());
            return;
        }
        self::fail('expected a skip outside CI');
    }

    public function testThePathIsTheSiblingOfThisPlugin(): void
    {
        self::assertSame(dirname(__DIR__, 3) . '/bcc-core', CoreSibling::root());
    }
}
