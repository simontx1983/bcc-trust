<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Announcements;

/**
 * The announcement write vocabulary, as pure policy.
 *
 * ── WHY THIS IS A SEPARATE CLASS ────────────────────────────────────────────
 * api-contract-v1.md §4.32.13 gives writes a vocabulary of **verbs** and reads a
 * vocabulary of **states**, and they are deliberately different sets. The verb →
 * stored-state mapping is the single place that difference is resolved, so it
 * lives alone, needs no WordPress, and is unit-testable without core. The
 * service that performs the write consumes it; it does not re-derive it.
 *
 * ⚠⚠ `schedule` MEANS `post_status = 'future'` — NEVER `publish` with a future
 * date. A row stored `publish` with a future `post_date_gmt` fails visibility
 * predicate 2 today, so it is invisible publicly; but WordPress's own scheduler
 * only promotes rows whose status is `future`, so such a row would be invisible
 * to the public AND invisible to the promoter — permanently stuck. The
 * announcements-crud-transition design doc §2 names this as the mapping that is
 * not obvious, and it is pinned here by test.
 *
 * ⚠ `blocked` is NOT a write verb and has no mapping. §4.32.13 defines it as a
 * state reached by reconciler arm C, which does not exist. It is rejected as an
 * input rather than silently mapped onto `draft` — mapping it would make a
 * refusal look like a successful write.
 */
final class AnnouncementTransition
{
    /** The three accepted write verbs (§4.32.13). A closed set. */
    public const VERB_DRAFT    = 'draft';
    public const VERB_PUBLISH  = 'publish';
    public const VERB_SCHEDULE = 'schedule';

    /** Stored WordPress statuses the verbs map onto. */
    public const STATUS_DRAFT  = 'draft';
    public const STATUS_PUBLIC = 'publish';
    public const STATUS_FUTURE = 'future';

    /**
     * ⚠ Order matters only for readability; membership is what is asserted.
     *
     * @return list<string>
     */
    public static function verbs(): array
    {
        return [self::VERB_DRAFT, self::VERB_PUBLISH, self::VERB_SCHEDULE];
    }

    public static function isVerb(string $verb): bool
    {
        return in_array($verb, self::verbs(), true);
    }

    /**
     * The verb → stored `post_status` mapping, and the whole of it.
     *
     * @throws \InvalidArgumentException on anything that is not one of the three
     *         verbs — including `blocked`, deliberately.
     */
    public static function statusFor(string $verb): string
    {
        switch ($verb) {
            case self::VERB_DRAFT:
                return self::STATUS_DRAFT;
            case self::VERB_PUBLISH:
                return self::STATUS_PUBLIC;
            case self::VERB_SCHEDULE:
                return self::STATUS_FUTURE;
        }

        throw new \InvalidArgumentException('not an announcement write verb: ' . $verb);
    }

    /**
     * Whether this verb authorizes publication, i.e. whether the publication
     * marker must be written.
     *
     * ⚠⚠ TRUE FOR `schedule` AS WELL AS `publish`, and that is the §6.1
     * decision this slice owns.
     *
     * ⚠⚠ THIS IS NOW `publish` ONLY, AND THAT REVERSES THE FIRST VERSION OF
     * THIS SLICE ON MEASURED EVIDENCE. The old reasoning is replaced rather
     * than left standing beside the new behaviour.
     *
     * The first version wrote the marker at schedule time as well, arguing that
     * a scheduled row is stored `future` so predicate 1 keeps it private. §6.1
     * of the CRUD design doc had already warned what that choice costs:
     * *"choosing between them decides whether predicate 3 or predicate 2 is the
     * one keeping it private."* It does — and predicate 2 is not up to the job:
     *
     *   - an OVERDUE scheduled row (status `future`, due instant passed, cron
     *     not yet run) has a PAST date, so predicate 2 stops refusing, and a
     *     non-cron publish then satisfies all three predicates;
     *   - a published row demoted to `draft` keeps its marker, so the same
     *     holds for it.
     *
     * Both were MEASURED publicly readable inside the interval between core's
     * status write and arm C's backstop — `findPublic()` true, present in the
     * public list and rotator, detail 200. See
     * `scripts/tests/announcement-publication-interval.php` cases B, E and F.
     *
     * So the marker is written at **scheduled promotion** instead — the SECOND
     * of the three moments §6.1 named, not a new invention and not a third
     * marker. `AnnouncementPublicationGuard` writes it at the
     * `publish_future_post` veto hook, after a fresh authority check and before
     * core publishes. A row that is not yet public therefore never carries the
     * marker, so predicate 3 refuses throughout the interval on every path.
     *
     * ⚠ The earlier objection to this moment — "the promoting actor would have
     * to mint an authorization it does not have" — was wrong in one word. The
     * guard mints nothing: it re-resolves the SAME operator authority for the
     * user the row records, and writes the marker only if it still holds.
     */
    public static function authorizesPublication(string $verb): bool
    {
        return self::statusFor($verb) === self::STATUS_PUBLIC;
    }

    /**
     * Whether a verb needs a caller-supplied instant.
     *
     * Only `schedule` does. §4.32.5 makes server time authoritative for
     * everything else, so `draft` and `publish` take the server clock and
     * ignore any client timestamp entirely.
     */
    public static function requiresInstant(string $verb): bool
    {
        return $verb === self::VERB_SCHEDULE;
    }

    /**
     * Validate a scheduling instant.
     *
     * ⚠ STRICTLY future, compared against a caller-supplied `$nowUtc` rather
     * than reading the clock itself, so the rule is testable without freezing
     * time. A non-future instant is refused rather than clamped to now: a
     * clamp would turn "schedule" into "publish" silently, which is the class
     * of rewrite that `absint` performed on request bounds and that this
     * codebase has already paid for once.
     *
     * @return string|null the normalised `Y-m-d H:i:s` UTC instant, or null if
     *                     the input is not an acceptable strictly-future instant
     */
    public static function normalizeInstant(string $raw, string $nowUtc): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // ⚠ A fixed format, parsed strictly. `strtotime()` would accept
        // "next tuesday" and "+1 day", which are not contract inputs and whose
        // meaning depends on the server's locale and clock.
        $dt = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $raw,
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($dt === false) {
            return null;
        }
        // ⚠ getLastErrors() returns false (PHP >= 8.2) or an array; a non-empty
        // warning/error count means the string parsed only by coercion, e.g.
        // "2026-02-31 00:00:00" rolling into March. Refuse those.
        if (is_array($errors)
            && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0)
        ) {
            return null;
        }

        $normalised = $dt->format('Y-m-d H:i:s');
        if ($normalised !== $raw) {
            // Round-trip mismatch: the input was not a canonical instant.
            return null;
        }

        if ($normalised <= $nowUtc) {
            return null;
        }

        return $normalised;
    }
}
