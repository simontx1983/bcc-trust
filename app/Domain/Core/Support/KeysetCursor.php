<?php
/**
 * Keyset pagination cursors — the house idiom, as one producer, with the
 * cross-surface isolation the existing copies only claim.
 *
 * ── WHY THIS CLASS EXISTS (§11) ──────────────────────────────────────────
 * The idiom — base64url-encoded JSON carrying a sort key plus a unique row
 * id, decoded defensively so a malformed cursor resets to the first page
 * rather than erroring — is open-coded **privately** in two services:
 *
 *   CommentService::encodeCursor / ::decodeCursor   (`k`, legacy `t`)
 *   BlogService::encodeCursor    / ::decodeCursor   (`t`)
 *
 * `docs/pattern-registry.md` names **no canonical** for it, so unlike the
 * `{allowed, unlock_hint, reason_code}` triple there was not even an
 * aspirational one. This is that canonical.
 *
 * ── ⚠⚠ THE FINDING THAT SHAPED THE DESIGN ────────────────────────────────
 * `BlogService` documents the duplication as deliberate:
 *
 *     "Kept independent so ranker / non-ranker surfaces don't accidentally
 *      cross-decode each other."
 *
 * **That property does not hold.** `CommentService::decodeCursor()` reads
 * `$data['k'] ?? $data['t']` — the `t` fallback exists for its own legacy
 * cursors — and requires only that `id` be set. A `BlogService` cursor is
 * exactly `{"t":"<iso8601>","id":N}`, so it satisfies both and decodes
 * cleanly as a comment cursor. Verified by constructing one.
 *
 * So the isolation rests on the two codecs *happening* to live in separate
 * files, not on anything the format enforces — and the one explicit
 * compatibility fallback is what breaks it.
 *
 * This class delivers the property instead of asserting it: the surface is
 * **baked into the payload** and a cursor from another surface is refused.
 * That is strictly stronger than separate code, because it survives
 * copy-paste.
 *
 * ⚠ The two existing copies are deliberately **not** retrofitted here.
 * `CommentService`'s decode carries live legacy-cursor compatibility that
 * must not be dropped in a hurried extraction, and its key semantics are
 * sort-dependent (a datetime for `new`, a number for `top`/`relevant`)
 * with its own rejection rules. Migrating them is a separate reviewable
 * change; what belongs here is the canonical the new surface uses. The
 * unenforced-isolation defect above is reported, not silently patched
 * across surfaces this branch has no business touching.
 *
 * ── ⚠ NOT SIGNED, AND DOES NOT NEED TO BE ────────────────────────────────
 * A base64url JSON cursor is trivially forgeable, and that is acceptable
 * because a cursor cannot widen visibility: it only ever adds a
 * `(key, id)` comparison to a query whose authorization and visibility
 * predicates are applied independently. A forged cursor repositions the
 * reader inside the set they could already see. Stated explicitly so a
 * reviewer does not have to work out whether the omission was considered —
 * and so that if a future surface ever lets the cursor carry something
 * authorization-bearing, the reasoning on record is visibly no longer
 * true.
 *
 * Pure: no WordPress, no DB, no clock, no state. Exhaustively testable.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class KeysetCursor
{
    /**
     * Payload version.
     *
     * ⚠ Not speculative generality: `CommentService` already carries a
     * de-facto second format (`t` alongside `k`) with no way to tell them
     * apart except by probing for keys, and that accidental fallback is
     * precisely what breaks the isolation documented above. Six bytes buys
     * the ability to retire a format deliberately instead.
     */
    public const VERSION = 1;

    /**
     * Surface tags. One per paginated collection, because §4.32.12 gives
     * each a DIFFERENT order — so a cursor minted for one is meaningless
     * against another, and silently applying it would skip or repeat rows.
     */
    public const SURFACE_ANNOUNCEMENTS_ACTIVE   = 'ann_active';
    public const SURFACE_ANNOUNCEMENTS_ARCHIVED = 'ann_archived';
    public const SURFACE_ANNOUNCEMENTS_OWNER    = 'ann_owner';
    public const SURFACE_ANNOUNCEMENT_COMMENTS  = 'ann_comments';

    /**
     * ⚠ A cap on the ENCODED length, checked before any decoding work.
     *
     * `base64_decode()` then `json_decode()` on an unbounded
     * attacker-supplied string is avoidable work on a public read path.
     * 512 bytes is roughly eight times the largest cursor this class can
     * mint, so it cannot refuse a legitimate one.
     */
    public const MAX_ENCODED_LENGTH = 512;

    /**
     * Mint a cursor for one surface from the last row of a page.
     *
     * `$key` is that row's ordering value, already rendered as a string by
     * the caller — a UTC MySQL datetime, a numeric score, or a composite.
     * This class does not interpret it; it carries it.
     *
     * ⚠ `$id` is the UNIQUE TIEBREAK and is required. §4.32.12: ordering by
     * a timestamp alone is not a total order, two rows sharing a second
     * page unstably, and `?state=active` is ordered by a field the operator
     * can change — pinning moves a row to the front mid-scroll. Making the
     * id a required parameter rather than an optional one is why a caller
     * cannot produce the unstable form.
     *
     * @throws \InvalidArgumentException on an empty surface or key, or a
     *         non-positive id — each of which would mint a cursor that
     *         {@see self::decode()} must then refuse, i.e. a page break
     *         that silently resets to the start.
     */
    public static function encode(string $surface, string $key, int $id): string
    {
        if ($surface === '') {
            throw new \InvalidArgumentException('Cursor surface must not be empty.');
        }
        if ($key === '') {
            throw new \InvalidArgumentException('Cursor key must not be empty.');
        }
        if ($id <= 0) {
            throw new \InvalidArgumentException('Cursor id must be a positive row id.');
        }

        $payload = json_encode(
            ['v' => self::VERSION, 's' => $surface, 'k' => $key, 'id' => $id],
            JSON_UNESCAPED_SLASHES
        );

        // json_encode can only fail here on malformed UTF-8 in the key.
        // Refusing is right: a cursor that cannot round-trip is worse than
        // no cursor, because the caller would emit it and the next request
        // would silently restart the list.
        if (!is_string($payload)) {
            throw new \InvalidArgumentException('Cursor key is not encodable (invalid UTF-8?).');
        }

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * Read a cursor for one surface.
     *
     * Returns `null` for **every** rejection — absent, malformed,
     * truncated, foreign-surface, wrong-version, tampered — and the caller
     * treats `null` as "start from the first page". There is no exception
     * path and no error code, because a bad cursor is a navigation
     * accident (a stale bookmark, a truncated URL, a cursor from another
     * tab), not a client fault worth a 400.
     *
     * ⚠⚠ `$expectedSurface` is what makes cross-decoding impossible rather
     * than merely unlikely. A cursor minted for the active list is refused
     * by the archived list even though both carry a datetime and an id —
     * and the two are ordered by DIFFERENT columns (`published_at` vs
     * `archived_at`), so applying one to the other would skip or repeat
     * rows with nothing in the response to show it happened.
     *
     * @return array{key: string, id: int}|null
     */
    public static function decode(string $expectedSurface, ?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        if (strlen($cursor) > self::MAX_ENCODED_LENGTH) {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            return null;
        }

        // Version first: an unknown version means this class cannot
        // interpret the remaining fields, so probing them would be reading
        // a format it does not know.
        if (($data['v'] ?? null) !== self::VERSION) {
            return null;
        }

        // ⚠ Strict surface match. No fallback, no "missing means legacy" —
        // that exact accommodation in CommentService is what breaks the
        // isolation this parameter exists to provide.
        if (($data['s'] ?? null) !== $expectedSurface) {
            return null;
        }

        $key = $data['k'] ?? null;
        if (!is_string($key) || $key === '') {
            return null;
        }

        // ⚠ The id must be an INT or an integral string, never a float or
        // a numeric-ish string: `(int) '4471abc'` is 4471, which would
        // silently reposition the page rather than reset it.
        $rawId = $data['id'] ?? null;
        if (is_int($rawId)) {
            $id = $rawId;
        } elseif (is_string($rawId) && preg_match('/^[1-9][0-9]*$/', $rawId) === 1) {
            $id = (int) $rawId;
        } else {
            return null;
        }

        if ($id <= 0) {
            return null;
        }

        return ['key' => $key, 'id' => $id];
    }

    /**
     * Whether a surface tag is one this class knows.
     *
     * Published so a caller can assert its own constant rather than a
     * string literal, and so a test can pin the inventory — a surface
     * added without a tag would silently share another surface's cursors.
     *
     * @return list<string>
     */
    public static function knownSurfaces(): array
    {
        return [
            self::SURFACE_ANNOUNCEMENTS_ACTIVE,
            self::SURFACE_ANNOUNCEMENTS_ARCHIVED,
            self::SURFACE_ANNOUNCEMENTS_OWNER,
            self::SURFACE_ANNOUNCEMENT_COMMENTS,
        ];
    }
}
