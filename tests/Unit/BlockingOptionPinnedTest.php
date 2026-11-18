<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\PrivacySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-295 — one block system.
 *
 * BCC enforces blocks always (PeepSoBlockRepository reads peepso_blocks
 * directly), but PeepSo's own SQL honours them only while its admin option
 * `user_blocking_enable` is on: the user search behind member search and the
 * mention gate, plus activity, photos and messages. PrivacySettings pins the
 * option ON through PeepSo's per-option read filter, so both systems agree
 * whatever the toggle is saved as.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BlockingOptionPinnedTest extends TestCase
{
    private const HOOK = 'peepso_filter_get_option_user_blocking_enable';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/blocking-option-pin-stubs.php';
        $GLOBALS['__f295_hooks'] = [];
    }

    public function testBootRegistersThePinOnPeepSosOptionReadFilter(): void
    {
        PrivacySettings::registerSearchFilter();

        $registered = $GLOBALS['__f295_hooks'][self::HOOK] ?? [];
        self::assertCount(1, $registered);
        self::assertSame([PrivacySettings::class, 'pinBlockingEnabled'], $registered[0][0]);
    }

    /** @return array<string, array{mixed}> what the admin toggle may be saved as */
    public static function storedValues(): array
    {
        return [
            'saved off (0)'     => [0],
            "saved off ('0')"   => ['0'],
            'never saved (null)' => [null],
            'empty string'      => [''],
            'false'             => [false],
            'saved on (1)'      => [1],
        ];
    }

    /** As PeepSoConfigSettings::get_option() reads it: the stored value through the filter. */
    #[DataProvider('storedValues')]
    public function testTheOptionAlwaysReadsOn(mixed $stored): void
    {
        PrivacySettings::registerSearchFilter();

        self::assertSame(1, apply_filters(self::HOOK, $stored));
    }

    public function testTheBootFileStillCallsTheRegistration(): void
    {
        $boot = (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');
        self::assertStringContainsString('\BCC\Trust\Core\Support\PrivacySettings::registerSearchFilter();', $boot);
    }
}
