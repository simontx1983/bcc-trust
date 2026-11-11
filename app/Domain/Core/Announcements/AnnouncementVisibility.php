<?php
/**
 * The announcement public-visibility predicate — api-contract-v1.md
 * §4.32.1. ONE producer, used by every read path.
 *
 * ── ⚠⚠⚠ WHY PREDICATE 3 EXISTS ──────────────────────────────────────────
 * An announcement is public only when ALL THREE hold:
 *
 *   1. `post_status = 'publish'`
 *   2. `post_date_gmt <= ` now, UTC
 *   3. a valid publication-authorization marker — `…_authorized_at`
 *      present and not future, AND `…_authorized_user_id === post_author`
 *
 * The first two are the obvious ones and they are NOT SUFFICIENT. A row
 * moved to `publish` early — by hand, by a plugin, by a bad migration, by
 * a restored backup — would otherwise become public the moment its date
 * arrived **without ever having passed the ownership recheck**. The marker
 * is written only after an authoritative operator resolution succeeds, so
 * it is the only field that records "a verified operator actually chose to
 * publish this".
 *
 * §4.32.1: **all four read paths — list, detail, comments and feed
 * hydration — enforce all three.** That is why this class emits SQL rather
 * than exposing a PHP row check as well: two implementations of a
 * three-part rule is how one of them ends up with two parts. A test pins
 * that there is exactly one producer.
 *
 * ── ⚠⚠ THE CALLER CANNOT SUPPLY "NOW" ───────────────────────────────────
 * Both time comparisons use MySQL's `UTC_TIMESTAMP()`, not a bound
 * parameter. That is deliberate: §4.32.5 says publication uses
 * authoritative server time and any client-supplied timestamp is ignored,
 * and a `?now=` parameter threaded through four read paths is four chances
 * to pass the wrong clock. There is no argument here to get wrong.
 *
 * Everything reads `post_date_gmt`. Site-local `post_date` is never read
 * for logic (§4.32.5).
 *
 * ── ⚠⚠ WHAT IS DELIBERATELY *NOT* IN THE PREDICATE ───────────────────────
 *
 * **Archived is not here.** An archived announcement is still PUBLIC — it
 * keeps its canonical permalink and its original `published_at`, and
 * appears under `?state=archived` (§4.32.6). Folding archival into this
 * predicate would make archived announcements vanish entirely, which is
 * the opposite of "a public record, not a deletion". Archival is a
 * separate filter applied per collection.
 *
 * **`post_password` is not here either**, and that is contract fidelity
 * rather than an oversight. §4.32.1 names three predicates. A password on
 * one of our rows does not hide it from OUR endpoints — our read paths
 * never consult `post_password` — so adding a fourth predicate would
 * *hide a legitimate announcement* if an import ever set one. The password
 * hazard is real but it belongs to core's REST comment controller, where
 * `post_password_required()` short-circuits `show_in_rest`, and it is
 * closed in A1 by custom capabilities plus forcing the column empty on
 * every save.
 *
 * **`post_type` IS here**, but it is scoping rather than one of the three:
 * without it the predicate would happily describe an ordinary post that
 * happened to carry our meta.
 *
 * Pure string construction — no DB access, no WordPress at all, and no
 * `$wpdb`: the postmeta table name is a PARAMETER (see
 * {@see self::assertIdentifier()}). So the emitted SQL is testable and
 * the same bytes execute against real rows in integration.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Announcements;

if (!defined('ABSPATH')) {
    exit;
}

final class AnnouncementVisibility
{
    /**
     * Written only after an authoritative operator resolution succeeds.
     * Presence is predicate 3's first half.
     */
    public const META_AUTHORIZED_AT = '_bcc_announcement_publish_authorized_at';

    /**
     * Must equal `post_author`. Predicate 3's second half.
     *
     * ⚠ This compares against `post_author`, which §4.32.2 FORBIDS as an
     * authorization input — and that is not a contradiction. Here it is a
     * CONSISTENCY CHECK on a stored row, not a grant: it can only ever
     * REFUSE publication. A check that may only refuse is not an
     * authority. (Same shape as the decoded-JWT consistency check
     * elsewhere in this codebase.)
     */
    public const META_AUTHORIZED_USER_ID = '_bcc_announcement_publish_authorized_user_id';

    /** Set by archival; one-way (§4.32.6). NOT part of the public predicate. */
    public const META_ARCHIVED_AT = '_bcc_announcement_archived_at';

    /** Per-announcement comment switch (§4.32.7). Gates our endpoint only. */
    public const META_COMMENTS_ENABLED = '_bcc_announcement_comments_enabled';

    /**
     * The three predicates, plus type scoping, as a parenthesised SQL
     * boolean that ANDs onto any WHERE.
     *
     * `$alias` is the `wp_posts` alias already in the caller's query.
     * Validated rather than interpolated blindly: it reaches SQL as an
     * identifier, so anything but a bare identifier is a programming
     * error and is refused loudly instead of being escaped into something
     * that looks like it worked.
     *
     * @throws \InvalidArgumentException on a non-identifier alias
     */
    public static function publicPredicateSql(string $metaTable, string $alias = 'p'): string
    {
        self::assertIdentifier($alias, 'Alias');
        self::assertIdentifier($metaTable, 'Meta table');

        $meta = $metaTable;
        $a    = $alias;

        // ⚠ EXISTS rather than JOINs. Two LEFT JOINs onto postmeta would
        // multiply rows when a key is duplicated — and a duplicated
        // `_authorized_user_id` (one correct, one wrong) is exactly the
        // shape a bad migration leaves behind. EXISTS asks "is there a
        // row that satisfies this", which is the question, and it cannot
        // change the cardinality of the outer query.
        return '('
            . " {$a}.post_type = '" . AnnouncementPostType::POST_TYPE . "'"
            . " AND {$a}.post_status = 'publish'"
            . " AND {$a}.post_date_gmt <= UTC_TIMESTAMP()"
            . ' AND EXISTS ('
                . " SELECT 1 FROM {$meta} bcc_aa"
                . " WHERE bcc_aa.post_id = {$a}.ID"
                . " AND bcc_aa.meta_key = '" . self::META_AUTHORIZED_AT . "'"
                . " AND bcc_aa.meta_value <> ''"
                // ⚠ `<= UTC_TIMESTAMP()` so a FUTURE marker does not
                // authorize. A marker dated ahead would otherwise let a
                // row be published by writing tomorrow's date today.
                . ' AND bcc_aa.meta_value <= UTC_TIMESTAMP()'
            . ')'
            . ' AND EXISTS ('
                . " SELECT 1 FROM {$meta} bcc_au"
                . " WHERE bcc_au.post_id = {$a}.ID"
                . " AND bcc_au.meta_key = '" . self::META_AUTHORIZED_USER_ID . "'"
                // ⚠⚠⚠ NUMERIC ONLY — NO STRING COMPARISON, NO REGEXP, NO
                // COLLATION. Two CI failures produced this form, and both
                // earlier versions looked correct.
                //
                // v1 — `REGEXP '^[0-9]+$'` then `CAST(... AS UNSIGNED)`. The
                //   regex was there because `CAST('7abc' AS UNSIGNED)` is 7.
                //   It LEAKS: in MySQL and MariaDB the regex `$` matches
                //   BEFORE A TRAILING NEWLINE, so "7\n" satisfied it, cast to
                //   7, and authorized. Found by the case seeding "7\n".
                //
                // v2 — `meta_value = CAST(post_author AS CHAR)`. Correct on
                //   values, but it compares a LONGTEXT column against a CAST
                //   result carrying the CONNECTION's collation. On MySQL 8
                //   that is `Illegal mix of collations (utf8mb4_unicode_ci,
                //   IMPLICIT) and (utf8mb4_0900_ai_ci, IMPLICIT)` — the
                //   predicate does not merely mis-answer, it ERRORS. ⚠ That
                //   would have been production-breaking wherever the postmeta
                //   collation differs from the server default, and no amount
                //   of value-level reasoning would have revealed it.
                //
                // v3, below — every comparison is a NUMBER, so no collation
                // is involved anywhere and `COLLATE` appears nowhere:
                //
                //   1. the cast must equal the author, and
                //   2. casting BACK must reproduce the stored byte length.
                //
                // The round trip is what closes the coercion hole without a
                // regex: '7abc'→7→'7' (1 vs 4), ' 7' (1 vs 2), '7.9' (1 vs 3),
                // '+7' (1 vs 2), "7\n" (1 vs 2) and '07' (1 vs 2) are each
                // rejected, while '7' (1 vs 1) is accepted. Stricter than the
                // contract requires — '07' is refused — which is the right
                // direction for a check that gates publication.
                . " AND CAST(bcc_au.meta_value AS UNSIGNED) = {$a}.post_author"
                . ' AND LENGTH(bcc_au.meta_value)'
                . ' = LENGTH(CAST(CAST(bcc_au.meta_value AS UNSIGNED) AS CHAR))'
                // ⚠ And author 0 is never a valid operator, so a row with
                // post_author = 0 and meta '0' — which is internally
                // CONSISTENT and passes both rungs above — must not satisfy
                // this.
                . " AND {$a}.post_author > 0"
            . ')'
            . ')';
    }

    /**
     * `AND NOT archived` — the active-collection filter (§4.32.6).
     *
     * Separate from {@see self::publicPredicateSql()} on purpose: an
     * archived announcement is public, so the archival filter belongs to
     * whichever collection is being built, not to visibility.
     *
     * @throws \InvalidArgumentException on a non-identifier alias
     */
    public static function notArchivedSql(string $metaTable, string $alias = 'p'): string
    {
        return self::archivalSql($metaTable, $alias, false);
    }

    /**
     * `AND archived` — the `?state=archived` filter.
     *
     * @throws \InvalidArgumentException on a non-identifier alias
     */
    public static function archivedSql(string $metaTable, string $alias = 'p'): string
    {
        return self::archivalSql($metaTable, $alias, true);
    }

    /**
     * ⚠ The two archival filters are EXACT COMPLEMENTS by construction —
     * one `NOT EXISTS`, one `EXISTS`, over the identical inner condition.
     * Written once because two hand-built halves drift, and a row that
     * satisfied neither would be invisible in both the active list and the
     * archived list: present, public, and unreachable.
     *
     * @throws \InvalidArgumentException on a non-identifier alias
     */
    private static function archivalSql(string $metaTable, string $alias, bool $archived): string
    {
        self::assertIdentifier($alias, 'Alias');
        self::assertIdentifier($metaTable, 'Meta table');

        $meta = $metaTable;

        $inner = " SELECT 1 FROM {$meta} bcc_ar"
               . " WHERE bcc_ar.post_id = {$alias}.ID"
               . " AND bcc_ar.meta_key = '" . self::META_ARCHIVED_AT . "'"
               // ⚠ An empty string is NOT archived. `update_post_meta`
               // with '' leaves a row behind, and a bare key-presence
               // test would read that as archived forever.
               . " AND bcc_ar.meta_value <> ''";

        return '(' . ($archived ? 'EXISTS (' : 'NOT EXISTS (') . $inner . '))';
    }

    /**
     * ⚠ Both the alias and the table name reach SQL as IDENTIFIERS,
     * which cannot be bound as parameters. So they are validated, not
     * escaped: anything other than a bare identifier is a programming
     * error and is refused loudly rather than quoted into something
     * that looks like it worked.
     *
     * ⚠⚠ The table name is a PARAMETER rather than read from `$wpdb`,
     * and that is not stylistic. §1 forbids direct `$wpdb` outside a
     * Repository, and an earlier version of this class used
     * `global $wpdb` to read `$wpdb->postmeta` — six arch-guardrails
     * violations. Taking the name from the caller puts the table
     * choice where the DB seam already is, and has a second benefit:
     * the integration suite can point the predicate at its OWN
     * fixture tables instead of dropping and recreating the shared
     * `wp_postmeta`, which is what poisoned 57 unrelated cases on the
     * first CI run.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertIdentifier(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' must be a bare SQL identifier.');
        }
    }
}
