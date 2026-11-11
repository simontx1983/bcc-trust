<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\BlogCoverImageWriter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * THE BYTE-LEVEL STRIP, exercised against a real image library on a real
 * JPEG that really carries GPS.
 *
 * ── WHY THIS FILE EXISTS SEPARATELY ─────────────────────────────────────
 * `CoverImagePrivacyTest` pins the DECISIONS — which name is minted, which
 * mimes are stripped, that a failure is reported so upload() can refuse —
 * through a stubbed editor. It deliberately never enters the Imagick
 * branch, which is the only branch that actually removes bytes on an
 * Imagick host. That left the one claim the whole control rests on —
 * "the stored file no longer carries the uploader's EXIF" — asserted
 * nowhere.
 *
 * This file closes that. It drives `stripEmbeddedMetadata()` against a
 * genuine Imagick and asserts the metadata is gone FROM THE BYTES.
 *
 * ── HOW THE EVIDENCE IS GATHERED, AND WHY NOT exif_read_data() ──────────
 * ⚠ Absence is asserted by walking JPEG markers in pure PHP, not by asking
 * a parser. `exif_read_data()` returns false both for "no EXIF here" and
 * for "this parser gave up", and the `exif` extension may not be loaded at
 * all — so a parser-based assertion could read a strip as successful on
 * evidence that proves nothing about the file. Walking the segments needs
 * no extension and asserts the bytes themselves.
 *
 * ── THE FIXTURE IS BUILT HERE, NOT PASTED HERE ──────────────────────────
 * ⭐ Only the inert 8x8 pixel container is embedded (as base64, because
 * this repository has no `.gitattributes` and a committed binary would be
 * at the mercy of EOL normalisation). The EXIF — the thing under test — is
 * constructed in `exifApp1WithGps()` below, with every offset DERIVED and
 * then asserted, so the fixture is auditable line by line instead of being
 * an opaque blob nobody can check.
 *
 * ⚠ A hand-built fixture can be wrong in a way that makes every later
 * assertion vacuous, so `testTheFixtureCarriesTheMetadataThisStripMustRemove`
 * runs on EVERY host, with or without Imagick, and fails if the fixture
 * ever stops carrying the metadata the strip is supposed to remove.
 *
 * ── WHAT IS STILL NOT COVERED ───────────────────────────────────────────
 * ⚠ The `wp_get_image_editor()` fallback, taken on a host with GD but no
 * Imagick. It needs WordPress, which this harness does not load, and
 * stubbing it would only test the stub. Its contract — the strip counts
 * only when `$saved['path']` is the path requested — is pinned by value in
 * `CoverImagePrivacyTest`.
 */
#[CoversNothing]
final class CoverImageMetadataStripBehaviourTest extends TestCase
{
    /**
     * A minimal 8x8 JPEG carrying NO metadata beyond the JFIF APP0 header.
     * Produced by GD and then stripped, so it cannot smuggle in EXIF of its
     * own and hand this suite a false negative.
     */
    private const BASE_JPEG_B64 =
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAYEBAUEBAYFBQUGBgYHCQ4JCQgICRINDQoOFRIW'
        . 'FhUSFBQXGiEcFxgfGRQUHScdHyIjJSUlFhwpLCgkKyEkJST/2wBDAQYGBgkICREJCREkGBQY'
        . 'JCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCT/wAAR'
        . 'CAAIAAgDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAA'
        . 'AAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAB//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAM'
        . 'AwEAAhEDEQA/AJQAnix//9k=';

    /** The IFD0 -> GPS IFD pointer: tag 0x8825, type 4 (LONG). */
    private const GPS_POINTER_BYTES = "\x88\x25\x00\x04";

    private const MAKE  = "BCC Test Camera\0";
    private const MODEL = "Privacy Fixture\0";

    /** @var list<string> */
    private array $temps = [];

    protected function tearDown(): void
    {
        foreach ($this->temps as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }

        $this->temps = [];
    }

    // ── the fixture ─────────────────────────────────────────────────────

    private static function be16(int $n): string
    {
        return pack('n', $n);
    }

    private static function be32(int $n): string
    {
        return pack('N', $n);
    }

    /** One 12-byte IFD entry: tag, type, count, then 4 bytes of value or offset. */
    private static function entry(int $tag, int $type, int $count, string $value4): string
    {
        self::assertSame(4, strlen($value4), 'an IFD entry value field is exactly 4 bytes');

        return self::be16($tag) . self::be16($type) . self::be32($count) . $value4;
    }

    /**
     * A real Exif APP1 segment carrying Make, Model and a GPS IFD.
     *
     * Offsets in Exif are counted from the start of the TIFF header, so they
     * are derived from the sizes above them and then asserted against the
     * values this layout must produce. A silent offset drift would build a
     * segment parsers reject, which would make the strip look effective when
     * it had nothing valid to remove.
     */
    private static function exifApp1WithGps(): string
    {
        $typeAscii    = 2;
        $typeLong     = 4;
        $typeRational = 5;

        // 51/1, 30/1, 0/1 and 0/1, 7/1, 3900/100 — synthetic, not a real place.
        $lat = self::be32(51) . self::be32(1) . self::be32(30) . self::be32(1)
            . self::be32(0) . self::be32(1);
        $lon = self::be32(0) . self::be32(1) . self::be32(7) . self::be32(1)
            . self::be32(3900) . self::be32(100);

        $ifd0At   = 8;
        $ifd0Size = 2 + (3 * 12) + 4;
        $makeAt   = $ifd0At + $ifd0Size;
        $modelAt  = $makeAt + strlen(self::MAKE);
        $gpsIfdAt = $modelAt + strlen(self::MODEL);
        $gpsSize  = 2 + (4 * 12) + 4;
        $latAt    = $gpsIfdAt + $gpsSize;
        $lonAt    = $latAt + strlen($lat);

        self::assertSame(
            [8, 50, 66, 82, 136, 160],
            [$ifd0At, $makeAt, $modelAt, $gpsIfdAt, $latAt, $lonAt],
            'the Exif layout must place every block where this fixture claims'
        );

        // IFD0. ⚠ Tags must ASCEND or a conforming parser stops reading.
        $ifd0 = self::be16(3)
            . self::entry(0x010F, $typeAscii, strlen(self::MAKE), self::be32($makeAt))
            . self::entry(0x0110, $typeAscii, strlen(self::MODEL), self::be32($modelAt))
            . self::entry(0x8825, $typeLong, 1, self::be32($gpsIfdAt))
            . self::be32(0);

        // The GPS IFD: the tags this control exists for. The two Ref values
        // fit in the 4-byte field, so they are inline rather than offset.
        $gps = self::be16(4)
            . self::entry(0x0001, $typeAscii, 2, "N\0\0\0")
            . self::entry(0x0002, $typeRational, 3, self::be32($latAt))
            . self::entry(0x0003, $typeAscii, 2, "W\0\0\0")
            . self::entry(0x0004, $typeRational, 3, self::be32($lonAt))
            . self::be32(0);

        $tiff = 'MM' . self::be16(0x002A) . self::be32($ifd0At)
            . $ifd0 . self::MAKE . self::MODEL . $gps . $lat . $lon;

        self::assertSame(184, strlen($tiff), 'the TIFF block is a fixed, known size');

        $payload = "Exif\0\0" . $tiff;

        // An APP1 length field counts itself but not the 0xFFE1 marker.
        return "\xFF\xE1" . self::be16(strlen($payload) + 2) . $payload;
    }

    /** A real JPEG with the Exif segment immediately after SOI, per the spec. */
    private static function fixtureBytes(): string
    {
        $base = base64_decode(self::BASE_JPEG_B64, true);

        self::assertIsString($base, 'the embedded container must decode');
        self::assertSame("\xFF\xD8", substr($base, 0, 2), 'the container must start with SOI');

        return "\xFF\xD8" . self::exifApp1WithGps() . substr($base, 2);
    }

    private function fixtureFile(string $suffix = '.jpg'): string
    {
        // tempnam() leaves no extension, and a stored cover always has one,
        // so give the fixture the shape the real file would have.
        //
        // ⚠ tempnam() CREATES the base file, and appending a suffix means we
        // write to a DIFFERENT path, so both are registered for cleanup.
        // Tracking only the suffixed one leaks an empty file per case.
        $base = (string) tempnam(sys_get_temp_dir(), 'bcccover');
        $path = $base . $suffix;

        file_put_contents($path, self::fixtureBytes());
        $this->temps[] = $base;
        $this->temps[] = $path;

        return $path;
    }

    // ── the marker walker ───────────────────────────────────────────────

    /**
     * The JPEG segment markers present, e.g. ['APP1','APP0','SOS'].
     *
     * Pure PHP on purpose — see the class docblock. Stops at SOS, because
     * everything past it is entropy-coded pixel data rather than segments.
     *
     * @return list<string>
     */
    private static function markers(string $jpeg): array
    {
        if (substr($jpeg, 0, 2) !== "\xFF\xD8") {
            return ['NOT_JPEG'];
        }

        $out = [];
        $i   = 2;
        $n   = strlen($jpeg);

        while ($i + 3 < $n) {
            if ($jpeg[$i] !== "\xFF") {
                break;
            }

            $m = ord($jpeg[$i + 1]);

            // Standalone markers: no length field follows these.
            if ($m === 0xD8 || $m === 0x01 || ($m >= 0xD0 && $m <= 0xD7)) {
                $i += 2;
                continue;
            }
            if ($m === 0xDA) {
                $out[] = 'SOS';
                break;
            }
            if ($m === 0xD9) {
                $out[] = 'EOI';
                break;
            }

            $len = (int) unpack('n', substr($jpeg, $i + 2, 2))[1];

            if ($m >= 0xE0 && $m <= 0xEF) {
                $out[] = 'APP' . ($m - 0xE0);
            } elseif ($m === 0xFE) {
                $out[] = 'COM';
            } else {
                $out[] = sprintf('M%02X', $m);
            }

            $i += 2 + $len;
        }

        return $out;
    }

    private static function strip(string $path, string $mime): bool
    {
        $m = new \ReflectionMethod(BlogCoverImageWriter::class, 'stripEmbeddedMetadata');
        $m->setAccessible(true);

        /** @var bool $out */
        $out = $m->invoke(null, $path, $mime);

        return $out;
    }

    // ── 1. anti-vacuity: runs on every host, Imagick or not ─────────────

    /**
     * ⚠ THE GUARD AGAINST A VACUOUS SUITE. If the fixture ever stops
     * carrying real EXIF, every assertion below it would pass against a
     * file that had nothing to strip. This case fails first, and it needs
     * no image library to do it.
     */
    public function testTheFixtureCarriesTheMetadataThisStripMustRemove(): void
    {
        $bytes = self::fixtureBytes();

        self::assertContains('APP1', self::markers($bytes), 'the Exif segment is present');
        self::assertStringContainsString(
            "Exif\0\0",
            $bytes,
            'it is an Exif APP1 rather than some other APP1'
        );
        self::assertStringContainsString(
            self::GPS_POINTER_BYTES,
            $bytes,
            'IFD0 points at a GPS IFD — the exposure this control exists for'
        );
        self::assertStringContainsString('BCC Test Camera', $bytes, 'a non-GPS tag is present too');
        self::assertStringContainsString('Privacy Fixture', $bytes);
    }

    public function testTheFixtureIsAValidDecodableJpegAndNotJustBytes(): void
    {
        $path = $this->fixtureFile();

        $info = @getimagesize($path);

        self::assertIsArray($info, 'a fixture no decoder accepts would prove nothing');
        self::assertSame(8, $info[0]);
        self::assertSame(8, $info[1]);
        self::assertSame('image/jpeg', $info['mime']);
    }

    // ── 2. the real strip ───────────────────────────────────────────────

    /**
     * ⭐ THE ASSERTION THE WHOLE CONTROL RESTS ON: after the strip, the
     * stored file does not carry the uploader's EXIF.
     */
    #[RequiresPhpExtension('imagick')]
    public function testARealStripRemovesTheExifSegmentFromTheStoredBytes(): void
    {
        $path = $this->fixtureFile();

        self::assertTrue(self::strip($path, 'image/jpeg'), 'the strip must report success');

        $after = (string) file_get_contents($path);

        self::assertNotContains('APP1', self::markers($after), 'the Exif segment is gone');
        self::assertStringNotContainsString("Exif\0\0", $after);
        self::assertStringNotContainsString(
            self::GPS_POINTER_BYTES,
            $after,
            'no GPS IFD pointer survives'
        );
        self::assertStringNotContainsString('BCC Test Camera', $after, 'the camera make is gone');
        self::assertStringNotContainsString('Privacy Fixture', $after, 'the camera model is gone');
    }

    /** A strip that destroyed the image would be a different bug, not a fix. */
    #[RequiresPhpExtension('imagick')]
    public function testARealStripLeavesTheImageUsable(): void
    {
        $path = $this->fixtureFile();

        self::assertTrue(self::strip($path, 'image/jpeg'));

        $info = @getimagesize($path);

        self::assertIsArray($info, 'the stripped file must still decode');
        self::assertSame(8, $info[0], 'dimensions are unchanged');
        self::assertSame(8, $info[1]);
        self::assertSame('image/jpeg', $info['mime'], 'the mime is unchanged');
        self::assertGreaterThan(0, (int) filesize($path), 'and it is not an empty file');
    }

    /**
     * ⭐ The fail-open this pins BEHAVIOURALLY, with a real library: the
     * stripped bytes must land ON THE PATH GIVEN. WordPress routes a save
     * target through the filterable `wp_get_image_editor_output_format()`
     * map, so a re-encode can land on a sibling file and report success
     * while the original sits untouched where we put it. Here the proof is
     * direct — the file at `$path` itself changed and shrank.
     */
    #[RequiresPhpExtension('imagick')]
    public function testTheStripOverwritesTheFileItWasGivenRatherThanASibling(): void
    {
        $path   = $this->fixtureFile();
        $before = (string) file_get_contents($path);

        self::assertTrue(self::strip($path, 'image/jpeg'));

        $after = (string) file_get_contents($path);

        self::assertNotSame($before, $after, 'the file at this exact path changed');
        self::assertLessThan(
            strlen($before),
            strlen($after),
            'and it shrank, because a segment was removed from it'
        );
        self::assertSame(
            "\xFF\xD8",
            substr($after, 0, 2),
            'what is left at the path is still a JPEG, not a truncated stub'
        );
    }

    /**
     * GIF is on the skip list by design: re-encoding flattens animation, and
     * GIF has no EXIF chunk for the GPS this exists for. Asserted here with
     * a real library present, so the skip is not an artefact of the stubbed
     * harness — the file must come back BYTE-IDENTICAL.
     */
    #[RequiresPhpExtension('imagick')]
    public function testAGifIsReportedDoneWithoutItsBytesBeingTouched(): void
    {
        $path   = $this->fixtureFile('.gif');
        $before = (string) file_get_contents($path);

        self::assertTrue(self::strip($path, 'image/gif'), 'a skipped mime is not a failure');

        self::assertSame(
            $before,
            (string) file_get_contents($path),
            'the skip list must mean untouched, not re-encoded'
        );
    }
}
