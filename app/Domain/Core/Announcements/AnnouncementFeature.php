<?php
/**
 * The announcements feature switch — api-contract-v1.md §4.32.10.
 *
 * `bcc_announcements_enabled`, an option, **default OFF**. This is the
 * first and only executable reader of it in the codebase; before A2.4 the
 * string appeared solely in docblocks, which is what "structurally
 * disabled" meant.
 *
 * ── WHAT "OFF" MEANS (§4.32.10) ─────────────────────────────────────────
 *   - every announcement route returns **404**, not 403 and not an empty
 *     list — the feature does not exist, so neither do its URLs;
 *   - the validator Card omits the `announcements` block **entirely**
 *     rather than emitting it with everything denied, because presence of
 *     the block IS the gate and an emitted block would make the client
 *     mount the surface and issue requests that all 404;
 *   - module-205 feed activities are suppressed at READ time;
 *   - stored announcements, comments and activity rows are **untouched**.
 *     Re-enabling restores visibility without rebuilding or duplicating
 *     anything — suppression is a filter, never a delete.
 *
 * ⚠⚠ **The A1 comment-bypass guards and the CPT registration stay active
 * even when this is off**, and that is deliberate: turning the feature off
 * must never *open* the bypass surface. A switch that also disabled the
 * guards would make the safest-looking configuration the least safe one.
 * This class is therefore read by ROUTES and PROJECTIONS only — never by
 * `AnnouncementPostType`, which has a test pinning that it contains no
 * reference to this option.
 *
 * ── ⚠ WHY 404 AND NOT 403 ───────────────────────────────────────────────
 * A 403 asserts "this exists and you may not have it", which is a fact
 * about the feature's existence that an off switch should not disclose —
 * and it would tell a prober that announcements are coming. 404 is also
 * what §4.32.4 already requires for a non-public row read by a non-owner,
 * so the two refusals are indistinguishable on the wire. That is the
 * point: whether a validator has no announcements, has private ones, or
 * the feature is off entirely, an outside reader sees the same thing.
 *
 * ── DEFAULT FALSE, AND STRICTLY SO ──────────────────────────────────────
 * `get_option($k, false)` then a strict comparison. ⚠ NOT a `(bool)` cast:
 * WordPress stores options as strings, so `'0'` round-trips to the string
 * `'0'`, and while `(bool) '0'` is false, `(bool) 'false'` and
 * `(bool) 'off'` are **true**. An operator (or a migration, or a
 * half-written admin screen) writing `'off'` must not switch the feature
 * ON. Only the values WordPress itself produces for a true boolean are
 * accepted.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Announcements;

if (!defined('ABSPATH')) {
    exit;
}

final class AnnouncementFeature
{
    /** §4.32.10. Default OFF. */
    public const OPTION = 'bcc_announcements_enabled';

    /**
     * The values that count as ON.
     *
     * ⚠ A closed allowlist rather than a cast. WordPress persists `true`
     * as `'1'` and `false` as `'0'`, and `update_option` with a real bool
     * round-trips through that — so these four cover every value the
     * platform itself can produce. Anything else (`'off'`, `'false'`,
     * `'yes'`, `'2'`, an array) is **not on**, because a value this class
     * does not recognise is a value whose intent cannot be vouched for.
     *
     * @var list<mixed>
     */
    private const TRUTHY = [true, 1, '1'];

    /**
     * Whether announcements are enabled.
     *
     * No caching, no memoisation: `get_option` is already object-cached by
     * WordPress, and a memoised feature flag is how a request that
     * disables a feature mid-flight keeps serving it.
     */
    public static function isEnabled(): bool
    {
        $raw = get_option(self::OPTION, false);

        foreach (self::TRUTHY as $on) {
            if ($raw === $on) {
                return true;
            }
        }

        return false;
    }

    /**
     * The §4.32.10 refusal, for a route to return verbatim.
     *
     * Published as one method so every route refuses identically — three
     * hand-written 404s is how one of them ends up a 403, or carries a
     * message that admits the feature exists.
     *
     * ⚠ The message says nothing about announcements. A refusal that named
     * the feature would disclose exactly what the switch being off is meant
     * to withhold.
     */
    public static function notFound(): \WP_REST_Response
    {
        return \BCC\Trust\Core\Support\ApiResponse::error(
            'bcc_not_found',
            'Not found.',
            404
        );
    }
}
