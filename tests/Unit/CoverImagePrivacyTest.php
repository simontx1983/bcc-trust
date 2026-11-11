<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\BlogCoverImageWriter;
use BCC\Trust\Tests\Stubs\ImageEditorSpy;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * An uploaded cover/avatar image must not publish facts about its uploader.
 *
 * ── THE TWO LEAKS THIS PINS ─────────────────────────────────────────────
 * Both were reachable through the same upload path
 * (`POST /bcc/v1/pages/:id/avatar` → BlogCoverImageWriter::upload), and
 * both were PUBLIC, because nothing in the bcc-* plugins restricts
 * `/wp/v2/media`, so WordPress core's defaults apply.
 *
 *   1. THE ORIGINAL FILENAME, twice over. `wp_handle_upload()` keeps the
 *      caller's base name, so it landed in the uploads path and therefore
 *      in the attachment's `source_url`; and `wp_insert_attachment()`
 *      derived `post_title` from that same base name. An operator
 *      uploading `jane-doe-headshot-final.jpg` published
 *      "jane-doe-headshot-final" as a title and as a URL.
 *
 *   2. EMBEDDED METADATA IN THE STORED BYTES. The original was saved
 *      as-uploaded — derivatives were generated, but nothing re-encoded or
 *      stripped the file — so a phone photo kept its full EXIF, GPS
 *      included, in a publicly downloadable file.
 *
 * ⚠ WP's own `image_meta` never carried GPS, so the exposure is the FILE,
 * not the postmeta. That is why the strip runs before the attachment is
 * inserted rather than being left to attachment metadata.
 *
 * ── WHAT THIS SUITE CAN AND CANNOT SEE ──────────────────────────────────
 * ⭐ Asserted BY VALUE: the stored-name mint and the strip decision table.
 * Those are the privacy logic, and a mutation to either is caught here.
 *
 * ⭐ Asserted BEHAVIOURALLY through a stubbed editor: that a strip is
 * actually attempted for a strippable mime, that GIF is left alone without
 * the editor being touched at all, and that a failure is REPORTED rather
 * than swallowed — which is what lets upload() fail closed.
 *
 * ⚠ NOT ASSERTED HERE, and deliberately so: the actual byte-level
 * strip. This file reaches the editor through a stub, so it can say a
 * strip was ATTEMPTED but never that bytes were removed.
 *
 * ⭐ That claim is covered next door, in
 * `CoverImageMetadataStripBehaviourTest`, which drives the same private
 * method against a genuine Imagick on a JPEG carrying real GPS and
 * asserts the Exif segment is gone from the stored bytes. Those cases
 * carry `#[RequiresPhpExtension('imagick')]`, so they SKIP rather than
 * fail on a host without it — read that suite's skip count before
 * treating the strip as proven on any given run.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class CoverImagePrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        // ⚠ Required from setUp(), NOT file scope: this stub declares
        // functions in the production namespace, and a file-scope require
        // would declare them in the discovery parent and follow every
        // other suite in the process.
        require_once __DIR__ . '/../Stubs/upload-privacy-stubs.php';
        ImageEditorSpy::reset();
    }

    // ── 1. the stored name carries no caller-supplied byte ──────────────

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function mimeExtensions(): array
    {
        return [
            ['image/jpeg', 'jpg'],
            ['image/png', 'png'],
            ['image/webp', 'webp'],
            ['image/gif', 'gif'],
        ];
    }

    #[DataProvider('mimeExtensions')]
    public function testTheStoredNameIsMintedFromTheMimeAndNothingElse(string $mime, string $ext): void
    {
        $name = BlogCoverImageWriter::neutralStoredName($mime);

        self::assertMatchesRegularExpression(
            '/^cover-[0-9a-f]{16}\.' . preg_quote($ext, '/') . '$/',
            $name,
            'the stored name must be prefix + hex + the mime-derived extension, with no other input'
        );
    }

    /**
     * ⭐ THE REGRESSION, STATED AS THE PROPERTY THAT FAILED.
     *
     * The old code passed the caller's `$file['name']` straight through to
     * wp_handle_upload. This asserts the mint cannot reproduce any of the
     * hostile names a real uploader might send — not by sanitising them,
     * but by never consulting them: neutralStoredName() takes only a mime.
     */
    public function testNoHostileFilenameCanSurviveIntoTheStoredName(): void
    {
        $hostile = [
            'jane-doe-headshot-final.jpg',
            'passport-scan.jpg',
            '../../../etc/passwd.jpg',
            'C:\\Users\\jane\\Desktop\\private.jpg',
            'My Company Q4 Revenue.png',
            str_repeat('a', 300) . '.webp',
        ];

        foreach ($hostile as $original) {
            // The only input the mint accepts is the DETECTED mime.
            $name = BlogCoverImageWriter::neutralStoredName('image/jpeg');

            self::assertStringNotContainsString('jane', $name);
            self::assertStringNotContainsString('passport', $name);
            self::assertStringNotContainsString('Company', $name);
            self::assertStringNotContainsString('..', $name);
            self::assertMatchesRegularExpression('/^cover-[0-9a-f]{16}\.jpg$/', $name);
            // And the original is genuinely unrelated to the result.
            self::assertNotSame($original, $name);
        }
    }

    public function testAnUnknownMimeFallsBackToBinNotToACallerExtension(): void
    {
        self::assertSame(
            'bin',
            pathinfo(BlogCoverImageWriter::neutralStoredName('image/heic'), PATHINFO_EXTENSION),
            'an unmapped mime must not borrow an extension from anywhere else'
        );
    }

    public function testTwoMintsDoNotCollide(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $seen[BlogCoverImageWriter::neutralStoredName('image/png')] = true;
        }

        self::assertCount(50, $seen, 'the random component must actually vary');
    }

    // ── 2. the strip decision table ─────────────────────────────────────

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    public static function stripDecisions(): array
    {
        return [
            ['image/jpeg', true, 'JPEG is the EXIF/GPS carrier this control exists for'],
            ['image/png', true, 'PNG can carry an eXIf chunk'],
            ['image/webp', true, 'WebP can carry an EXIF chunk'],
            ['image/gif', false, 'GIF would be flattened to one frame by a re-encode'],
            // ⭐ The fail-closed direction. ALLOWED_MIMES gates upload() to
            // the four above, so this is unreachable TODAY — but it is the
            // case that decides what happens when someone widens the
            // allowlist (HEIC being the obvious candidate, and HEIC is full
            // of EXIF) and forgets this map.
            ['image/heic', true, 'an unseen mime must be stripped, not skipped'],
            ['image/avif', true, 'ditto'],
            ['', true, 'an empty mime must not read as "nothing to do"'],
        ];
    }

    #[DataProvider('stripDecisions')]
    public function testTheStripDecisionFailsClosedForAnythingButGif(
        string $mime,
        bool $expected,
        string $why
    ): void {
        self::assertSame($expected, BlogCoverImageWriter::metadataStripApplies($mime), $why);
    }

    public function testGifIsTheOnlyMimeEverSkipped(): void
    {
        $skipped = [];
        foreach (['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'x/y'] as $m) {
            if (!BlogCoverImageWriter::metadataStripApplies($m)) {
                $skipped[] = $m;
            }
        }

        self::assertSame(['image/gif'], $skipped);
    }

    // ── 3. the strip is actually attempted / reported ───────────────────

    private static function strip(string $path, string $mime): bool
    {
        $m = new \ReflectionMethod(BlogCoverImageWriter::class, 'stripEmbeddedMetadata');
        $m->setAccessible(true);

        /** @var bool $out */
        $out = $m->invoke(null, $path, $mime);

        return $out;
    }

    /**
     * ⚠⚠ WHY THE EDITOR IS REACHED AT ALL ON AN IMAGICK HOST, stated
     * because it looks like luck and is not.
     *
     * The writer tries Imagick FIRST. These cases pass a path that does
     * not exist, so `new \Imagick($path)` cannot read it and throws on
     * every host that has the extension; the catch logs and execution
     * falls through to the editor. So the editor-reached assertions below
     * hold with OR without imagick loaded — by the file's absence, which
     * this case pins, not by the extension's.
     *
     * ⭐ That fall-through is also the real fallback contract: an
     * Imagick failure must be LOGGED and then handed to the editor, never
     * swallowed into a silent success.
     */
    public function testAnImagickFailureIsLoggedAndThenFallsThroughToTheEditor(): void
    {
        $missing = '/tmp/cover-does-not-exist-' . bin2hex(random_bytes(6)) . '.jpg';
        self::assertFileDoesNotExist($missing, 'the precondition this case rests on');

        self::assertTrue(self::strip($missing, 'image/jpeg'));

        self::assertSame(
            [$missing],
            ImageEditorSpy::$requested,
            'the editor was asked for, whichever branch ran first'
        );

        if (class_exists('\Imagick')) {
            self::assertNotSame(
                [],
                ImageEditorSpy::$warnings,
                'an Imagick failure must be logged, not swallowed'
            );
        } else {
            self::assertSame(
                [],
                ImageEditorSpy::$warnings,
                'with no Imagick there is nothing to log on the way to the editor'
            );
        }
    }

    public function testAStrippableMimeActuallyReachesTheEditor(): void
    {
        self::assertTrue(self::strip('/tmp/cover-abc.jpg', 'image/jpeg'));

        self::assertSame(
            ['/tmp/cover-abc.jpg'],
            ImageEditorSpy::$requested,
            'the editor must be asked for exactly the stored path'
        );
        self::assertSame(
            [['path' => '/tmp/cover-abc.jpg', 'mime' => 'image/jpeg']],
            ImageEditorSpy::$saves,
            'and the file must be re-written, which is what drops the metadata on GD'
        );
    }

    /**
     * ⚠ GIF must not merely "survive" — the editor must never be touched,
     * because touching it is what flattens the animation.
     */
    public function testGifIsLeftAloneWithoutTheEditorBeingTouched(): void
    {
        self::assertTrue(self::strip('/tmp/cover-abc.gif', 'image/gif'));

        self::assertSame([], ImageEditorSpy::$requested, 'no editor for a GIF');
        self::assertSame([], ImageEditorSpy::$saves, 'and certainly no re-save');
    }

    /**
     * ⭐ THE FAIL-CLOSED CONTRACT. upload() refuses when this returns
     * false, so "no editor available" must not read as "nothing to strip".
     */
    public function testAnUnavailableEditorIsReportedAsFailureNotAsSuccess(): void
    {
        ImageEditorSpy::$mode = 'wp_error';

        self::assertFalse(
            self::strip('/tmp/cover-abc.jpg', 'image/jpeg'),
            'a missing editor must fail closed — the caller refuses the upload on false'
        );
    }

    /**
     * ⭐ THE FAIL-OPEN THIS CLOSES, and it is not hypothetical: WordPress
     * routes a save target through the filterable
     * wp_get_image_editor_output_format() map, so an editor can re-encode
     * to a NEW filename and report success while the unstripped original
     * stays exactly where we put it. A strip that cannot prove it
     * overwrote the file must not claim to have happened.
     */
    public function testASaveThatLandedOnAnotherPathIsNotAStrip(): void
    {
        ImageEditorSpy::$redirectTo = '/tmp/cover-abc.webp';

        self::assertFalse(
            self::strip('/tmp/cover-abc.jpg', 'image/jpeg'),
            'the original is still unstripped at the path we asked for'
        );
        self::assertCount(1, ImageEditorSpy::$saves, 'it did attempt the write');
    }

    public function testASaveThatLandedOnTheRequestedPathIsAStrip(): void
    {
        self::assertTrue(self::strip('/tmp/cover-abc.jpg', 'image/jpeg'));
        self::assertSame(
            [['path' => '/tmp/cover-abc.jpg', 'mime' => 'image/jpeg']],
            ImageEditorSpy::$saves
        );
    }

    public function testAFailedSaveIsReportedAsFailure(): void
    {
        ImageEditorSpy::$saveSucceeds = false;

        self::assertFalse(self::strip('/tmp/cover-abc.png', 'image/png'));
        self::assertCount(1, ImageEditorSpy::$saves, 'it must have tried before giving up');
    }

    // ── 4. the wiring, including the rule I was told not to weaken ──────

    private static function source(): string
    {
        $path = dirname(__DIR__, 2) . '/app/Domain/Core/Services/BlogCoverImageWriter.php';
        $src  = file_get_contents($path);
        self::assertIsString($src, 'the writer source must be readable');

        return $src;
    }

    /**
     * Comment-stripped source. Same implementation as
     * ValidatorPlaceholderScoreTest::code() — reused rather than
     * reinvented.
     *
     * ⚠⚠ REQUIRED, NOT TIDINESS. The first version of the ordering test
     * below measured raw source and matched
     * `wp_generate_attachment_metadata(` inside the COMMENT that explains
     * the strip's position — a comment that necessarily sits BEFORE the
     * call site it describes. So the assertion compared prose against code
     * and failed on correct code. Anything that reads positions out of
     * this file has to be comment-blind.
     */
    private static function code(string $src): string
    {
        $out = preg_replace('!/\*.*?\*/!s', '', $src) ?? '';

        return (string) preg_replace('!//[^\r\n]*!', '', $out);
    }

    /**
     * Anti-vacuity: the stripper must really strip, or every assertion
     * built on it is measuring the wrong string.
     */
    public function testTheCommentStripperStrips(): void
    {
        $raw     = self::source();
        $stripped = self::code($raw);

        self::assertStringContainsString(
            'THE ORDER IS LOAD-BEARING',
            $raw,
            'the raw source must contain the comment this test strips'
        );
        self::assertStringNotContainsString(
            'THE ORDER IS LOAD-BEARING',
            $stripped,
            'the stripper must remove block comments'
        );
        self::assertStringContainsString(
            'function stripEmbeddedMetadata',
            $stripped,
            'and it must leave the code intact'
        );
    }

    /**
     * ⛔ THE OWNERSHIP GATE IS NOT WEAKENED. `post_author = $authorId` is
     * what makes PostsService::createBlog accept the attachment later, and
     * it is deliberately NOT zeroed to hide the uploader: that would break
     * the ownership check. The filename and metadata leaks are closed
     * without touching it.
     */
    public function testTheUploaderOwnershipGateIsStillInPlace(): void
    {
        $src = self::code(self::source());

        self::assertStringContainsString(
            "'post_author'    => \$authorId,",
            $src,
            'the ownership gate must survive this privacy fix unchanged'
        );
        self::assertStringNotContainsString(
            "'post_author'    => 0,",
            $src,
            'zeroing the author would break PostsService::createBlog ownership'
        );
    }

    public function testTheCallerFilenameNeverReachesTheUploadCall(): void
    {
        $src = self::code(self::source());

        self::assertStringContainsString(
            "'name'     => \$storedName,",
            $src,
            'wp_handle_upload must receive the minted name'
        );
        self::assertStringNotContainsString(
            "'name'     => \$fileName,",
            $src,
            "the caller's own filename must not be what gets stored"
        );
    }

    /**
     * ⭐ ORDER IS LOAD-BEARING. wp_generate_attachment_metadata() copies
     * whatever metadata it finds into `_wp_attachment_metadata.image_meta`.
     * Stripping first means there is nothing to copy, so the file and the
     * postmeta are both clean from one call; stripping afterwards would
     * leave the postmeta copy behind.
     */
    public function testTheStripRunsBeforeTheAttachmentIsInserted(): void
    {
        $src = self::code(self::source());

        $strip  = strpos($src, 'if (!self::stripEmbeddedMetadata($savedPath, $savedMime))');
        $insert = strpos($src, '$attachmentId = wp_insert_attachment(');
        $meta   = strpos($src, 'wp_generate_attachment_metadata(');

        self::assertIsInt($strip, 'the strip call site must exist');
        self::assertIsInt($insert, 'the insert call site must exist');
        self::assertIsInt($meta, 'the metadata call site must exist');

        self::assertLessThan($insert, $strip, 'strip must precede wp_insert_attachment');
        self::assertLessThan($meta, $strip, 'strip must precede wp_generate_attachment_metadata');
    }

    /**
     * A refusal must not leave the moved file behind in uploads/.
     */
    public function testARefusedUploadRemovesTheMovedFile(): void
    {
        $src = self::code(self::source());
        $at  = strpos($src, 'if (!self::stripEmbeddedMetadata($savedPath, $savedMime))');
        self::assertIsInt($at);

        $branch = substr($src, $at, 900);

        self::assertStringContainsString('@unlink($savedPath);', $branch, 'clean up on refusal');
        self::assertStringContainsString("'error'   => 'bcc_unavailable'", $branch);
    }
}
