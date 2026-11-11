<?php
/**
 * Announcement moderation policy — api-contract-v1.md §4.32.16.
 *
 * Announcements ARE reportable, through the moderation workflow that
 * already exists. The decision carries three prohibitions, and this class
 * exists because exactly one of them is not already true.
 *
 * ── ⚠⚠⚠ WHAT THIS CLASS PREVENTS ────────────────────────────────────────
 * The auto-hide path subscribes to `bcc_content_reported`, counts distinct
 * pending reporters against the target, and at the configured threshold
 * (default 3) marks the activity hidden — after which the next feed read
 * filters it out for non-moderators.
 *
 * An announcement projects exactly ONE feed activity (§4.32.8), and that
 * activity is the same `feed_item` a report targets. So without this
 * class, **three reporters would silently take down a validator's
 * official notice** — no operator action, no administrator in the loop,
 * and nothing in the response to say it had happened.
 *
 * §4.32.16 forbids that. Reporting an announcement escalates it to a
 * human; it does not hide it, and it does not accumulate against the
 * person who wrote it.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ─────────────────────────────────────
 * It does not stop a report being FILED, does not stop the admin queue
 * showing it, and does not stop an administrator hiding an announcement
 * by hand. Manual moderation is the whole point of making announcements
 * reportable. Only the AUTOMATIC threshold action is excluded.
 *
 * It also mints nothing: no reputation, no trust, no fraud signal, and no
 * reaction. That half of §4.32.16 is already true — the report event has
 * exactly one subscriber and it is not a scoring path — so it is kept true
 * by a test rather than by code here.
 *
 * ── ⚠ WHY THIS IS NOT `NoReactionsGate` ──────────────────────────────────
 * `NoReactionsGate` already answers "does this module take no engagement",
 * and reusing it here would have been one line. It is deliberately NOT
 * reused: that gate encodes the REACTIONS policy, and auto-hide is
 * moderation, not engagement. Sharing the predicate would mean a future
 * change to the reactions policy silently changed what moderation does to
 * announcements — a coupling neither reader would expect.
 *
 * What IS reused is the lookup: `PeepSoActivityRepository::getModuleIdByActId()`
 * is the existing act-id → module seam, and this class adds no second
 * query path for it (§11).
 *
 * ── ⚠⚠ THE ONE HONEST TRADE-OFF: AN UNREADABLE MODULE ────────────────────
 * `getModuleIdByActId()` returns `null` for a missing row, an empty module
 * and a failed query alike — it cannot distinguish them. So an
 * unresolvable module is a real state this class has to answer for, and
 * both answers cost something:
 *
 *   - Treat unresolved as hideable → a transient read failure could
 *     auto-hide an announcement, which is the single outcome §4.32.16
 *     forbids, and it would be unobservable after the fact.
 *   - Treat unresolved as NOT hideable → an ordinary post whose module
 *     cannot be read at that instant does not get auto-hidden.
 *
 * This class takes the second, and that is a real behaviour change for
 * ordinary content in that narrow case — recorded rather than glossed.
 * Three reasons it is the right side:
 *
 *   1. You cannot responsibly hide an activity you cannot read. The hide
 *      row would point at a module nobody could name.
 *   2. Nothing is lost permanently: the subscriber runs on EVERY report,
 *      so the next one re-evaluates. A transient failure defers the hide,
 *      it does not cancel it.
 *   3. The failure is logged as a WARNING by the caller, so a persistent
 *      one is visible instead of silent.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Announcements;

use BCC\Core\Repositories\PeepSoActivityRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class AnnouncementModerationPolicy
{
    /**
     * The announcement activity module (§4.32.8).
     *
     * ⚠ A STRING, because `act_module_id` is read back from MySQL as a
     * string and the canonical module→kind map in bcc-core is keyed by
     * string. An int here would make every comparison fail silently in
     * the safe-looking direction — the module would never match, the
     * exclusion would never fire, and nothing would look wrong.
     */
    public const ANNOUNCEMENT_MODULE_ID = '205';

    /** The activity is an ordinary target; the threshold rule applies. */
    public const AUTO_HIDE_ALLOWED = 'allowed';

    /** An announcement. §4.32.16: never automatically hidden. */
    public const AUTO_HIDE_BLOCKED_ANNOUNCEMENT = 'blocked_announcement';

    /**
     * The module could not be read. See the trade-off in the class
     * docblock — reported as its own value so the caller can log it
     * distinctly rather than conflating a policy decision with a fault.
     */
    public const AUTO_HIDE_BLOCKED_UNRESOLVED = 'blocked_unresolved';

    /**
     * Whether the automatic threshold hide may act on this activity.
     *
     * Returns one of the three `AUTO_HIDE_*` values. Deliberately a
     * three-valued answer rather than a bool: the caller logs an
     * unresolved module as a WARNING and an announcement as an INFO, and
     * collapsing them would make a persistent read fault look like
     * ordinary policy.
     *
     * @return self::AUTO_HIDE_ALLOWED|self::AUTO_HIDE_BLOCKED_ANNOUNCEMENT|self::AUTO_HIDE_BLOCKED_UNRESOLVED
     */
    public static function autoHideDecision(int $actId): string
    {
        if ($actId <= 0) {
            // Not an activity at all. There is nothing to hide, and
            // nothing to identify — the same answer as an unreadable row.
            return self::AUTO_HIDE_BLOCKED_UNRESOLVED;
        }

        $module = PeepSoActivityRepository::getModuleIdByActId($actId);

        if (!is_string($module)) {
            return self::AUTO_HIDE_BLOCKED_UNRESOLVED;
        }

        // ⚠⚠ TRIM BEFORE THE EMPTINESS TEST, not after. An earlier
        // revision checked `$module === ''` against the RAW value and then
        // trimmed only for the comparison — so a whitespace-only module
        // ('   ') passed the emptiness check, failed the 205 comparison,
        // and came back ALLOWED. That is an unidentifiable activity being
        // declared hideable: the exact case this method exists to refuse,
        // reached by checking the two things in the wrong order. Caught by
        // the 'whitespace only' case in the suite.
        $module = trim($module);

        if ($module === '') {
            return self::AUTO_HIDE_BLOCKED_UNRESOLVED;
        }

        // ⚠ Exact comparison on the trimmed value. A padded `' 205'` is
        // not something our writer produces, but an import or a
        // hand-written row can, and a padded module that failed to match
        // would hide an announcement.
        if ($module === self::ANNOUNCEMENT_MODULE_ID) {
            return self::AUTO_HIDE_BLOCKED_ANNOUNCEMENT;
        }

        return self::AUTO_HIDE_ALLOWED;
    }

    /**
     * Convenience for the call site that only needs yes/no.
     *
     * Delegates rather than re-deriving — two copies of a fail-closed
     * decision is how divergent answers start.
     */
    public static function mayAutoHide(int $actId): bool
    {
        return self::autoHideDecision($actId) === self::AUTO_HIDE_ALLOWED;
    }
}
