<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\PrivacySettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-257 (option A): "hidden from member search" is ONE flag.
 *
 * `discovery_optout` on /me/privacy and `hide_from_search` on
 * /me/profile-prefs used to be two settings with one meaning, stored apart
 * (`bcc_privacy_discovery_optout` vs PeepSo's
 * `peepso_is_hide_profile_from_user_listing`), so they could disagree.
 * PeepSo's key is the single source of truth; `discovery_optout` stays on
 * /me/privacy as an ALIAS that reads and writes it.
 *
 * User meta is an in-memory double: $GLOBALS['__bcc_um'][$userId][$key].
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoveryOptOutAliasTest extends TestCase
{
    private const USER = 42;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__bcc_um'] = [];
        if (!\function_exists('get_user_meta')) {
            eval(<<<'PHP'
                function get_user_meta(int $userId, string $key = '', bool $single = false)
                {
                    $v = $GLOBALS['__bcc_um'][$userId][$key] ?? null;
                    return $single ? ($v ?? '') : ($v === null ? [] : [$v]);
                }
                function update_user_meta(int $userId, string $key, $value): bool
                {
                    $GLOBALS['__bcc_um'][$userId][$key] = (string) $value;
                    return true;
                }
                PHP);
        }
    }

    /** @return array<string, string> */
    private static function meta(): array
    {
        return $GLOBALS['__bcc_um'][self::USER] ?? [];
    }

    public function testWritingTheAliasWritesTheOneFlag(): void
    {
        PrivacySettings::writePartial(self::USER, ['discovery_optout' => true]);

        self::assertSame('1', self::meta()[PrivacySettings::HIDE_FROM_LISTING_META] ?? null);
        self::assertArrayNotHasKey(
            PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META,
            self::meta(),
            'the legacy key is no longer written'
        );

        PrivacySettings::writePartial(self::USER, ['discovery_optout' => false]);
        self::assertSame('0', self::meta()[PrivacySettings::HIDE_FROM_LISTING_META]);
    }

    public function testReadingTheAliasReadsTheOneFlag(): void
    {
        // What PATCH /me/profile-prefs hide_from_search=true writes.
        $GLOBALS['__bcc_um'][self::USER][PrivacySettings::HIDE_FROM_LISTING_META] = '1';

        self::assertTrue(PrivacySettings::flag(self::USER, 'discovery_optout'));
        self::assertTrue(PrivacySettings::readAll(self::USER)['discovery_optout']);
    }

    public function testTheLegacyKeyNoLongerDecidesTheAlias(): void
    {
        // A pre-F-257 row with the flag since turned off through
        // profile-prefs: the one flag wins.
        $GLOBALS['__bcc_um'][self::USER][PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META] = '1';
        $GLOBALS['__bcc_um'][self::USER][PrivacySettings::HIDE_FROM_LISTING_META]       = '0';

        self::assertFalse(PrivacySettings::flag(self::USER, 'discovery_optout'));
    }

    public function testTheOtherPrivacyKeysKeepTheirOwnMeta(): void
    {
        PrivacySettings::writePartial(self::USER, ['reviews_hidden' => true]);

        self::assertSame('1', self::meta()['bcc_privacy_reviews_hidden'] ?? null);
        self::assertArrayNotHasKey(PrivacySettings::HIDE_FROM_LISTING_META, self::meta());
    }

    public function testTheFieldStaysOnPrivacyForCompatibility(): void
    {
        self::assertContains('discovery_optout', PrivacySettings::ALL_KEYS);
        self::assertArrayHasKey('discovery_optout', PrivacySettings::readAll(self::USER));
    }
}
