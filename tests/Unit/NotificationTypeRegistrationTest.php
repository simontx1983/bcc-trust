<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\NotificationPrefs;
use BCC\Trust\Core\Support\NotificationType;
use PHPUnit\Framework\TestCase;

/**
 * Regression pin for the RANK_DEMOTED registration bug (found Rank
 * Phase 8): the type was dispatched from its Phase 5 introduction but
 * missing from NotificationType::ALL, NotificationPrefs::BELL_TYPES,
 * and DEFAULTS — so NotificationViewService silently REJECTED every
 * demotion bell row and the pref was untoggleable. HOLDER_COMMUNITY_LIVE
 * had the same drift in BELL_TYPES.
 *
 * These tests are GENERIC — they reflect over the constants rather than
 * pinning a list — so a future type added to NotificationType without
 * its BELL_TYPES/DEFAULTS registration fails here, structurally, before
 * a single silent-rejected row ships.
 */
final class NotificationTypeRegistrationTest extends TestCase
{
    /** @return array<string, string> constant name => slug (ALL excluded) */
    private function typeConstants(): array
    {
        $constants = (new \ReflectionClass(NotificationType::class))->getConstants();
        unset($constants['ALL']);

        $out = [];
        foreach ($constants as $name => $value) {
            self::assertIsString($value, "NotificationType::{$name} must be a string slug");
            $out[$name] = $value;
        }
        return $out;
    }

    public function testEveryTypeConstantIsRegisteredInAll(): void
    {
        $constants = $this->typeConstants();

        foreach ($constants as $name => $slug) {
            self::assertContains(
                $slug,
                NotificationType::ALL,
                "NotificationType::{$name} is dispatched but missing from ALL — "
                . 'NotificationViewService::shapeRow rejects its rows silently (the RANK_DEMOTED bug)'
            );
        }

        // And nothing phantom: ALL contains exactly the constants, once.
        self::assertSame(count($constants), count(NotificationType::ALL));
        self::assertSame(count(array_unique(NotificationType::ALL)), count(NotificationType::ALL));
    }

    public function testBellTypesCoverEveryType(): void
    {
        $all  = NotificationType::ALL;
        $bell = NotificationPrefs::BELL_TYPES;
        sort($all);
        sort($bell);

        self::assertSame(
            $all,
            $bell,
            'BELL_TYPES must equal NotificationType::ALL — a dispatched type absent here is untoggleable in prefs'
        );
    }

    public function testDefaultsCarryABellFlagForEveryType(): void
    {
        $defaults = (new \ReflectionClassConstant(NotificationPrefs::class, 'DEFAULTS'))->getValue();
        self::assertIsArray($defaults);

        foreach (NotificationType::ALL as $slug) {
            self::assertArrayHasKey(
                'bell_' . $slug,
                $defaults,
                "DEFAULTS is missing bell_{$slug} — the pref would silently fall back to the generic default"
            );
            self::assertTrue(
                (bool) $defaults['bell_' . $slug],
                "bell_{$slug} must default ON (§I1 V1 baseline: all bell events on)"
            );
        }

        // No orphans: every bell_* default corresponds to a live type.
        foreach (array_keys($defaults) as $key) {
            if (!is_string($key) || !str_starts_with($key, 'bell_')) {
                continue;
            }
            self::assertContains(
                substr($key, 5),
                NotificationType::ALL,
                "DEFAULTS carries {$key} for a type that no longer exists"
            );
        }
    }

    public function testValidatorAcceptsEveryRegisteredType(): void
    {
        foreach (NotificationType::ALL as $slug) {
            self::assertTrue(NotificationType::isValid($slug));
        }
        self::assertFalse(NotificationType::isValid('bcc_never_a_type'));
    }
}
