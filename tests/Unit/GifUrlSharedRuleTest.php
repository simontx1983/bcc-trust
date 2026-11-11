<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Every bcc-trust GIF path applies bcc-core's GiphyUrl::isAllowed():
 *   - CommentService::resolveGifMedia (WRITE, comment GIFs);
 *   - CommentService::shapeMedia (READ, stored comment GIF blobs);
 *   - GifRepository::findByPostIds (READ, post GIFs: feed promotion and
 *     the moderation queue).
 *
 * ── ⚠ THE THINGS THAT MUST NOT HAPPEN ───────────────────────────────────
 *  1. A substring URL (https://evil.example/t.gif?x=giphy.com) renders.
 *     PeepSo's native GIF path still stores those, so the READ side must
 *     filter, not just the BCC write side.
 *  2. The comment path keeps its own looser rule (it used to allow plain
 *     http), so the two GIF paths drift apart.
 *  3. Without bcc-core's class, GIFs fail OPEN.
 *
 * Uses the REAL GiphyUrl from the sibling bcc-core checkout (CI checks it
 * out at ../bcc-core, the same path phpstan.neon scans), not a double: a
 * double could only agree with itself. A missing checkout FAILS the test
 * rather than skipping it. Override the location with BCC_CORE_SRC.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GifUrlSharedRuleTest extends TestCase
{
    private const GOOD      = 'https://media0.giphy.com/media/abc/giphy.gif';
    private const SUBSTRING = 'https://evil.example/t.gif?x=giphy.com';
    private const HTTP      = 'http://media0.giphy.com/media/abc/giphy.gif';

    private static function loadRealGiphyUrl(): void
    {
        $src = getenv('BCC_CORE_SRC') ?: dirname(__DIR__, 3) . '/bcc-core/src';
        $file = rtrim((string) $src, '/\\') . '/Security/GiphyUrl.php';
        self::assertFileExists(
            $file,
            'bcc-core checkout not found; set BCC_CORE_SRC to bcc-core/src. Refusing to skip.'
        );
        require_once $file;
        self::assertTrue(class_exists(\BCC\Core\Security\GiphyUrl::class));
    }

    private static function stubPostMeta(array $metaByPostId): void
    {
        $GLOBALS['__bcc_gif_meta'] = $metaByPostId;
        require_once __DIR__ . '/../Stubs/gif-url-rule-stubs.php';
    }

    /** @return mixed */
    private static function callPrivateStatic(string $class, string $method, array $args)
    {
        $m = new ReflectionMethod($class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    // ── GifRepository (post GIFs, read side) ─────────────────────────────

    public function testRepositoryKeepsOnlyConformingStoredUrls(): void
    {
        self::loadRealGiphyUrl();
        self::stubPostMeta([
            10 => self::GOOD,
            11 => self::SUBSTRING,
            12 => self::HTTP,
            13 => '',
        ]);

        $out = (new \BCC\Trust\Core\Repositories\GifRepository())->findByPostIds([10, 11, 12, 13, 14]);

        self::assertSame([10 => self::GOOD], $out);
    }

    public function testRepositoryFailsClosedWithoutBccCore(): void
    {
        // GiphyUrl deliberately NOT loaded in this process.
        self::stubPostMeta([10 => self::GOOD]);
        self::assertFalse(class_exists(\BCC\Core\Security\GiphyUrl::class, false));

        $out = (new \BCC\Trust\Core\Repositories\GifRepository())->findByPostIds([10]);

        self::assertSame([], $out);
    }

    // ── CommentService (comment GIFs) ────────────────────────────────────

    public function testCommentWriteUsesTheSharedRule(): void
    {
        self::loadRealGiphyUrl();
        $svc = \BCC\Trust\Core\Services\CommentService::class;

        $ok = self::callPrivateStatic($svc, 'resolveGifMedia', [self::GOOD]);
        self::assertSame(['ok' => true, 'media' => ['kind' => 'gif', 'url' => self::GOOD]], $ok);

        foreach ([self::SUBSTRING, self::HTTP] as $bad) {
            $r = self::callPrivateStatic($svc, 'resolveGifMedia', [$bad]);
            self::assertSame('bcc_invalid_request', $r['error'] ?? null, $bad);
        }
    }

    public function testCommentReadDropsNonConformingStoredGifs(): void
    {
        self::loadRealGiphyUrl();
        $svc = \BCC\Trust\Core\Services\CommentService::class;

        self::assertSame(
            ['kind' => 'gif', 'url' => self::GOOD],
            self::callPrivateStatic($svc, 'shapeMedia', [['kind' => 'gif', 'url' => self::GOOD]])
        );
        // Stored before the shared rule (the old check allowed http).
        self::assertNull(self::callPrivateStatic($svc, 'shapeMedia', [['kind' => 'gif', 'url' => self::HTTP]]));
        self::assertNull(self::callPrivateStatic($svc, 'shapeMedia', [['kind' => 'gif', 'url' => self::SUBSTRING]]));
        // Photos are untouched by the GIF rule.
        self::assertSame(
            ['kind' => 'photo', 'url' => 'https://cdn.example/p.jpg', 'width' => 4, 'height' => 3],
            self::callPrivateStatic($svc, 'shapeMedia', [['kind' => 'photo', 'url' => 'https://cdn.example/p.jpg', 'width' => 4, 'height' => 3]])
        );
    }

    public function testCommentPathHasNoLocalCopyOfTheRule(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/CommentService.php');
        self::assertStringNotContainsString("str_ends_with(\$host, '.giphy.com')", $src);
        self::assertStringContainsString('GiphyUrl::isAllowed', $src);
    }
}
