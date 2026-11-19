<?php
/**
 * PeepSoPrivacy::fromGroupPostId() test environment.
 *
 * Loaded ONLY from inside a #[RunTestsInSeparateProcesses] subprocess (see
 * PeepSoPrivacyStrictestRowTest) so this namespaced shim never reaches the
 * rest of the unit run.
 *
 * ⚠ $single IS HONOURED. The whole point of the suite is that the resolver
 * reads EVERY privacy row, so a shim that returned a scalar regardless would
 * make the resolver's `is_array()` check fail and answer Open every time —
 * and every assertion below would pass for the wrong reason.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\ValueObjects {

    /**
     * Rows the shim serves, in meta_id order: group id => list of values.
     *
     * @var array<int, list<mixed>>
     */
    $GLOBALS['__bcc_privacy_rows'] = [];

    if (!function_exists('BCC\\Trust\\Core\\ValueObjects\\get_post_meta')) {
        /** @return mixed */
        function get_post_meta($id, $key = '', $single = false)
        {
            if ($key !== 'peepso_group_privacy') {
                return $single ? '' : [];
            }

            /** @var array<int, list<mixed>> $fixture */
            $fixture = $GLOBALS['__bcc_privacy_rows'] ?? [];
            $rows    = $fixture[(int) $id] ?? [];

            if ($single) {
                // WordPress returns the FIRST row for $single, and '' when
                // there is none. That asymmetry is the defect this suite
                // exists for, so the shim reproduces it faithfully.
                return $rows === [] ? '' : $rows[0];
            }

            return $rows;
        }
    }
}
