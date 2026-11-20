<?php
/**
 * Halls join-gate test stubs.
 *
 * Loaded ONLY from inside a @runInSeparateProcess subprocess
 * (HallsJoinGateTest, ValidatorGroupResolverTest) so the main PHPUnit
 * process never sees these definitions. Defines the handful of WordPress
 * functions that the REAL GroupContextResolver + PeepSoPrivacy call,
 * backed by a per-test fixture map in $GLOBALS['__bcc_halls_gate_fixture']
 * keyed by group post id:
 *
 *   [ <id> => ['post_type' => 'peepso-group', 'meta' => [<key> => <value>]] ]
 *
 * All definitions are guarded so PHP's autoloader / a second require
 * leaves them alone.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Post', false)) {
        final class WP_Post
        {
            public int $ID = 0;
            public string $post_type = 'peepso-group';
            public string $post_title = '';
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!function_exists('BCC\\Trust\\Core\\Services\\get_post')) {
        function get_post($id)
        {
            $fixture = $GLOBALS['__bcc_halls_gate_fixture'][(int) $id] ?? null;
            if ($fixture === null) {
                return null;
            }
            $post = new \WP_Post();
            $post->ID = (int) $id;
            $post->post_type = (string) ($fixture['post_type'] ?? 'peepso-group');
            $post->post_title = (string) ($fixture['title'] ?? '');
            return $post;
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Services\\get_post_meta')) {
        function get_post_meta($id, $key, $single = false)
        {
            return $GLOBALS['__bcc_halls_gate_fixture'][(int) $id]['meta'][$key] ?? '';
        }
    }
}

namespace BCC\Trust\Core\ValueObjects {
    if (!function_exists('BCC\\Trust\\Core\\ValueObjects\\get_post_meta')) {
        function get_post_meta($id, $key, $single = false)
        {
            // ⚠ $single IS HONOURED, and must stay that way.
            // PeepSoPrivacy::fromGroupPostId() asks for the LIST form so it
            // can take the strictest of several privacy rows (F-429
            // follow-up). A shim that ignored $single and returned a scalar
            // would make `is_array()` fail there and resolve EVERY group to
            // Open inside this suite -- every secret-group assertion would
            // then pass for the wrong reason.
            $value = $GLOBALS['__bcc_halls_gate_fixture'][(int) $id]['meta'][$key] ?? null;
            if ($single) {
                return $value ?? '';
            }
            return $value === null ? [] : (is_array($value) ? $value : [$value]);
        }
    }
}
