<?php
/**
 * DbWriteCheck — "did the database accept this write?" for callers outside
 * the Repositories layer (§1: $wpdb is only touched here).
 *
 * Used by MyProfilePrefsEndpoint's Phase 2 (#449 review, M1), where each
 * write goes through a WordPress API (update_user_meta, PeepSo's
 * update_peepso_user) whose RETURN VALUE cannot be used as the signal:
 * update_user_meta() answers false for an UNCHANGED value exactly as for a
 * failure. `$wpdb->last_error` is the signal instead: it is cleared before
 * the write and is non-empty afterwards only if a query in it failed.
 *
 * ⚠ LAST-RESORT CHECK, NOT EXHAUSTIVE. wpdb::query() has failure paths that
 * return false WITHOUT setting last_error: the `! $this->ready` early return
 * after a failed reconnect, and an empty result from the `query` filter.
 * A write lost that way leaves last_error EMPTY, so run() reports it as
 * accepted (counted as saved). Read paths can detect it through query()'s
 * own return (see HiddenActivityRepository::runRead()); a write made
 * through a WordPress API cannot, because that API hides query()'s return.
 *
 * @package BCC\Trust\Core\Repositories
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class DbWriteCheck
{
    /**
     * Run $write and report whether the database accepted it.
     *
     * @param callable(): mixed $write
     */
    public static function run(callable $write): bool
    {
        self::clearLastError();
        $write();

        return self::lastError() === '';
    }

    /** The database error the last failed write left, or '' (for logging). */
    public static function lastError(): string
    {
        /** @var \wpdb|null $wpdb */
        global $wpdb;
        return $wpdb !== null ? (string) $wpdb->last_error : '';
    }

    // Separate from lastError() so static analysis does not narrow last_error
    // to '' across the write that mutates it (HiddenActivityRepository's shape).
    private static function clearLastError(): void
    {
        /** @var \wpdb|null $wpdb */
        global $wpdb;
        if ($wpdb !== null) {
            $wpdb->last_error = '';
        }
    }
}
