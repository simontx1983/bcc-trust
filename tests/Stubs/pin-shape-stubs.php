<?php
/**
 * Fixtures for PostWritePinShapeTest.
 *
 * Every write stub SNAPSHOTS the superglobals at the moment it is
 * called. That is the only moment the question has an answer: the guard
 * restores the real request in a `finally`, so a test that inspects
 * `$_POST` after the call sees the original whether the pin ran or not.
 *
 * ⚠ Required from setUp(), never at file scope: these declare functions
 * and a class at FQNs other suites also declare, and a file-scope
 * declaration would pollute the PHPUnit discovery parent.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('bcc_pin_shape_snap')) {
        /** @param array<string, mixed> $args */
        function bcc_pin_shape_snap(string $fn, array $args = []): void
        {
            $GLOBALS['__bcc_shape'][] = [
                'fn'      => $fn,
                'args'    => $args,
                // Copied, not referenced — the guard restores the
                // originals the instant the write returns.
                'get'     => $_GET,
                'post'    => $_POST,
                'request' => $_REQUEST,
            ];
        }
    }

    // ── SHAPE 2: a PeepSo MODEL write ────────────────────────────────
    //
    // ⚠ The real `PeepSoGroup` lives in the peepso-groups add-on and is
    // not loadable here, so this double stands in for it. What it
    // doubles is NOT the guard — the guard under test is the real
    // bcc-core class — but the thing being wrapped. Its constructor
    // snapshots the request exactly where `group.php:308`'s
    // `wp_insert_post()` would see it.
    if (!class_exists('PeepSoGroup', false)) {
        class PeepSoGroup
        {
            /** @var array<string, mixed> */
            private array $data;

            /** @param array<string, mixed>|null $data */
            public function __construct(?int $id = null, ?array $data = null)
            {
                $this->data = $data ?? [];

                // TWO arguments CREATE; one only loads. Only the creating
                // form reaches wp_insert_post, so only it is snapshotted.
                if ($data !== null) {
                    bcc_pin_shape_snap('new PeepSoGroup(…, …)', $data);
                    $this->data['id'] = 4242;
                }
            }

            /** @return mixed */
            public function get(string $key)
            {
                return $this->data[$key] ?? null;
            }

            /** @param array<string, mixed> $fields */
            public function update(array $fields): void
            {
                bcc_pin_shape_snap('$group->update(…)', $fields);
            }
        }
    }
}

namespace BCC\Trust\Tests\Fixtures {
    // ── SHAPES 1 and 3, in a namespace of their own ──────────────────
    //
    // Declared here rather than in a production namespace so this
    // fixture cannot shadow a real writer for any other suite.

    if (!function_exists(__NAMESPACE__ . '\\wp_insert_post')) {
        /** @param array<string, mixed> $args */
        function wp_insert_post(array $args, bool $wpError = false): int
        {
            unset($wpError);
            \bcc_pin_shape_snap('wp_insert_post', $args);

            return 7001;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_save_post_revision')) {
        /** @return int|null */
        function wp_save_post_revision(int $postId)
        {
            \bcc_pin_shape_snap('wp_save_post_revision', ['ID' => $postId]);

            return 7002;
        }
    }
}
