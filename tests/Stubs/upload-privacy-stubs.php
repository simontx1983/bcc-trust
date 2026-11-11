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
         * Messages the writer logged, newest last.
         *
         * ⚠ Needed because on a host that HAS Imagick the writer
         * enters the Imagick branch FIRST, and these cases probe a path
         * that does not exist, so the construction throws and is logged
         * before the editor fallback is reached. Without a Logger that
         * throw is a fatal missing-class error instead.
         *
         * @var list<string>
         */
        public static array $warnings = [];

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
            self::$warnings     = [];
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

/**
 * ⚠⚠ THE WRITER LOGS, SO THE LOGGER MUST EXIST.
 *
 * BlogCoverImageWriter imports BCC\Core\Log\Logger, which lives
 * in the bcc-core sibling and is NOT loaded by the unit bootstrap. On a
 * host WITHOUT Imagick that never mattered: the strip went straight to
 * the editor and logged nothing on the way. On a host WITH Imagick it
 * matters immediately — the Imagick construction throws for these
 * cases' probe paths, the catch logs, and the missing class turned five
 * passing cases into errors the moment imagick was added to CI.
 *
 * ⭐ Recording rather than discarding: a swallowed failure and a
 * reported-and-logged failure look identical from the return value
 * alone, and that difference is the whole point of failing closed.
 */
namespace BCC\Core\Log {

    use BCC\Trust\Tests\Stubs\ImageEditorSpy;

    if (!class_exists('BCC\\Core\\Log\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                ImageEditorSpy::$warnings[] = 'warning: ' . $message;
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                ImageEditorSpy::$warnings[] = 'error: ' . $message;
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
                ImageEditorSpy::$warnings[] = 'info: ' . $message;
            }

            /** @param array<string, mixed> $context */
            public static function debug(string $message, array $context = []): void
            {
                ImageEditorSpy::$warnings[] = 'debug: ' . $message;
            }
        }
    }
}
