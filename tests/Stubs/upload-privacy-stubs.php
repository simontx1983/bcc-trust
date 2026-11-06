<?php
/**
 * Stubs for CoverImagePrivacyTest.
 *
 * ⚠⚠ EVERY FUNCTION HERE IS NAMESPACED, NOT GLOBAL, and that is required
 * rather than stylistic. PHP resolves an unqualified call namespace-first,
 * so `wp_get_image_editor(...)` inside
 * BCC\Trust\Core\Services\BlogCoverImageWriter binds to
 * BCC\Trust\Core\Services\wp_get_image_editor when one exists. Declaring a
 * GLOBAL one instead would be picked up by every other suite loaded in the
 * same process — the exact mechanism that broke 21 unrelated
 * PageReadModelSync cases earlier in this project, because a
 * function_exists-guarded declaration still wins for whoever loads first.
 *
 * The consuming test therefore ALSO runs in separate processes and requires
 * this file from setUp(), not at file scope: file scope pollutes the
 * discovery parent before any isolation takes effect.
 *
 * @package BCC\Trust\Tests\Stubs
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Stubs {

    /**
     * Records what the writer asked the image editor to do, so a test can
     * assert the DECISION (was a strip attempted at all?) rather than the
     * pixels, which no unit harness can observe.
     */
    final class ImageEditorSpy
    {
        /** @var list<array{path: string, mime: string}> */
        public static array $saves = [];

        /** @var list<string> */
        public static array $requested = [];

        /** Next wp_get_image_editor() outcome: 'editor' | 'wp_error'. */
        public static string $mode = 'editor';

        /** Whether the spy editor's save() reports success. */
        public static bool $saveSucceeds = true;

        /**
         * When non-empty, save() reports SUCCESS but claims it wrote here
         * instead — the wp_get_image_editor_output_format() case.
         */
        public static string $redirectTo = '';

        public static function reset(): void
        {
            self::$saves        = [];
            self::$requested    = [];
            self::$mode         = 'editor';
            self::$saveSucceeds = true;
            self::$redirectTo   = '';
        }
    }

    final class FakeWpError
    {
        public function __construct(public string $code = 'stub_error')
        {
        }
    }

    final class FakeImageEditor
    {
        /** @return array{path: string}|FakeWpError */
        public function save(string $path, ?string $mime = null): array|FakeWpError
        {
            ImageEditorSpy::$saves[] = ['path' => $path, 'mime' => (string) $mime];

            if (!ImageEditorSpy::$saveSucceeds) {
                return new FakeWpError('image_save_failed');
            }

            if (ImageEditorSpy::$redirectTo !== '') {
                return ['path' => ImageEditorSpy::$redirectTo];
            }

            return ['path' => $path];
        }
    }
}

namespace BCC\Trust\Core\Services {

    use BCC\Trust\Tests\Stubs\FakeImageEditor;
    use BCC\Trust\Tests\Stubs\FakeWpError;
    use BCC\Trust\Tests\Stubs\ImageEditorSpy;

    if (!function_exists('BCC\\Trust\\Core\\Services\\wp_get_image_editor')) {
        /** @return FakeImageEditor|FakeWpError */
        function wp_get_image_editor(string $path): FakeImageEditor|FakeWpError
        {
            ImageEditorSpy::$requested[] = $path;

            if (ImageEditorSpy::$mode === 'wp_error') {
                return new FakeWpError('no_editor');
            }

            return new FakeImageEditor();
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\Services\\is_wp_error')) {
        function is_wp_error(mixed $thing): bool
        {
            return $thing instanceof FakeWpError;
        }
    }
}
