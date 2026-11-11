<?php
/**
 * Stub for AlbumPrivacyVocabularyTest.
 *
 * `UserAlbumsEndpoint::privacyLabel()` runs its default through
 * `apply_filters('bcc_album_privacy_label', ...)` so the copy stays
 * editable without a frontend release. That function does not exist in
 * the unit harness.
 *
 * ⚠⚠ DECLARED IN `BCC\Trust\Core\REST`, NOT GLOBALLY, and that matters.
 * Defining a shared WordPress function globally in a test stub is how 93
 * sibling tests were once silently broken in this suite: whoever
 * declares it first wins for the whole process, and a later file's
 * `function_exists` guard then skips its own — differently-behaved —
 * version without complaint.
 *
 * Shadowing it inside the one namespace whose code calls it is the
 * pattern this suite already uses (`cron-schedule-stubs.php` shadows
 * `apply_filters` inside `BCC\Trust\Core\Services`, `page-claim-endpoint-stubs.php`
 * inside `BCC\Core`). Verified before writing this: nothing else shadows
 * it in `BCC\Trust\Core\REST`.
 *
 * It is a pass-through. The filter is NOT the subject here — the default
 * label is — and a stub that transformed the value would test itself.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST {
    if (!\function_exists('BCC\Trust\Core\REST\apply_filters')) {
        /**
         * @param mixed $value
         * @param mixed ...$args
         * @return mixed
         */
        function apply_filters(string $hook, $value, ...$args)
        {
            unset($hook, $args);

            return $value;
        }
    }
}
