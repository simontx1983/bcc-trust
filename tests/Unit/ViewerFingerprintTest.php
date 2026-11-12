<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\ViewerFingerprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

if (!defined('BCC_ENCRYPTION_KEY')) {
    define('BCC_ENCRYPTION_KEY', 'test-key-for-viewer-fingerprint-only');
}

/**
 * F-324 — the per-day viewer code.
 *
 * The properties worth pinning are the privacy ones, because they are the
 * claims the user-facing copy makes:
 *   - the day is part of the message, so the same reader is uncorrelatable
 *     across days (this is what makes `unique_viewers` a daily figure);
 *   - signed-in and signed-out subjects cannot collide;
 *   - a signed-out viewer with no signal at all gets no code, rather than
 *     every such viewer collapsing into one "unique viewer";
 *   - the IPv4 coarsening to /24 is real — asserted, not assumed, because
 *     it is a deliberate accuracy-for-privacy trade and a future change
 *     should have to break a test to make it.
 */
#[CoversClass(ViewerFingerprint::class)]
final class ViewerFingerprintTest extends TestCase
{
    private const DAY = '2026-11-12';

    public function testTheCodeIsExactlySixteenRawBytes(): void
    {
        $h = ViewerFingerprint::forDay(self::DAY, 42);

        self::assertNotNull($h);
        self::assertSame(16, strlen($h), 'the column is BINARY(16)');
        self::assertSame(ViewerFingerprint::HASH_BYTES, strlen($h));
    }

    public function testTheSameViewerOnTheSameDayGetsTheSameCode(): void
    {
        self::assertSame(
            ViewerFingerprint::forDay(self::DAY, 42),
            ViewerFingerprint::forDay(self::DAY, 42),
            'dedupe depends on this being stable within a day'
        );
    }

    /**
     * ⭐ The central privacy property: a different day gives a different
     * code, so rows cannot be correlated across days.
     */
    public function testADifferentDayGivesADifferentCode(): void
    {
        self::assertNotSame(
            ViewerFingerprint::forDay(self::DAY, 42),
            ViewerFingerprint::forDay('2026-11-13', 42),
            'the day is mixed into the message — this is what bounds linkability'
        );
    }

    public function testSignedInAndSignedOutSubjectsCannotCollide(): void
    {
        // Without the 'u:' / 'a:' type prefix, a user id and an anonymous
        // subject that rendered the same way would be the same reader.
        $signedIn  = ViewerFingerprint::forDay(self::DAY, 12);
        $signedOut = ViewerFingerprint::forDay(self::DAY, 0, '12', '');

        self::assertNotNull($signedIn);
        self::assertNotNull($signedOut);
        self::assertNotSame($signedIn, $signedOut);
    }

    public function testASignedInViewerIgnoresIpAndUserAgent(): void
    {
        self::assertSame(
            ViewerFingerprint::forDay(self::DAY, 42, '203.0.113.9', 'Firefox'),
            ViewerFingerprint::forDay(self::DAY, 42, '198.51.100.7', 'Chrome'),
            'a signed-in reader is one reader regardless of device or network'
        );
    }

    /**
     * The documented accuracy-for-privacy trade: IPv4 coarsens to /24, so a
     * household or office reads as one viewer. Asserted so that changing it
     * has to break a test.
     */
    public function testTwoAddressesInTheSameIpv4SubnetShareACode(): void
    {
        self::assertSame(
            ViewerFingerprint::forDay(self::DAY, 0, '203.0.113.9', 'UA/1'),
            ViewerFingerprint::forDay(self::DAY, 0, '203.0.113.77', 'UA/1'),
            'same /24 + same UA => one viewer, by design'
        );

        self::assertNotSame(
            ViewerFingerprint::forDay(self::DAY, 0, '203.0.113.9', 'UA/1'),
            ViewerFingerprint::forDay(self::DAY, 0, '203.0.114.9', 'UA/1'),
            'a different /24 is a different viewer'
        );
    }

    public function testUserAgentIsPartOfTheSignedOutSubject(): void
    {
        self::assertNotSame(
            ViewerFingerprint::forDay(self::DAY, 0, '203.0.113.9', 'UA/1'),
            ViewerFingerprint::forDay(self::DAY, 0, '203.0.113.9', 'UA/2'),
            'two devices on one network are two readers'
        );
    }

    /**
     * ⚠ A signed-out viewer with neither an IP nor a UA carries no signal.
     * Minting a code anyway would collapse every such viewer into one, which
     * would report as a single unique viewer — a wrong number, not a safe
     * default.
     */
    public function testASignedOutViewerWithNoSignalGetsNoCode(): void
    {
        self::assertNull(ViewerFingerprint::forDay(self::DAY, 0, '', ''));
    }

    public function testAnEmptyDayIsRefused(): void
    {
        self::assertNull(ViewerFingerprint::forDay('', 42));
    }

    /**
     * ⚠ NOT COVERED HERE, deliberately, and said out loud rather than left
     * as a silent gap: the fail-closed path when `BCC_ENCRYPTION_KEY` is
     * absent. The constant is defined for the whole process by the time any
     * test runs (plugin activation is gated on it), and PHP cannot undefine a
     * constant, so this cannot be exercised in-process without a separate
     * process-isolated fixture that boots without the key. The behaviour
     * itself lives in `DeviceFingerprinter::keyedHash()` — it returns null
     * and logs — and `forDay()` propagates that null, which the type
     * signature pins.
     */
    public function testTheKeylessPathIsDocumentedAsUncoveredHere(): void
    {
        self::assertTrue(defined('BCC_ENCRYPTION_KEY'), 'see the docblock above');
    }
}
