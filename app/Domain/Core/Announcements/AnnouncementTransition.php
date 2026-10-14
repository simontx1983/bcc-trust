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
     * decision this slice owns. The marker records **who authorized publication
     * and when they did so** — not when the row becomes visible. Writing it at
     * schedule time is safe because a scheduled row is stored `future`, so
     * predicate 1 (`post_status='publish'`) and predicate 2
     * (`post_date_gmt <= now`) BOTH fail while it is pending: the marker cannot
     * leak it early, because predicate 1 is what is keeping it private.
     *
     * The alternative — writing the marker at promotion — would require the
     * promoting actor to mint an authorization it does not have. WordPress's
     * scheduler runs with no operator session, so that actor would have to be
     * invented. Rejected for that reason, not for convenience.
     *
     * ⚠ The residual risk is NAMED and NOT SOLVED here: a claim revoked between
     * scheduling and promotion still lets the row publish under the
     * authorization captured at schedule time. Closing that needs a reconciler
     * (arm C territory) and is not A3's to invent. `AnnouncementWriteServiceTest`
     * pins the current behaviour so a future reconciler has something to change
     * rather than something to discover.
     */
    public static function authorizesPublication(string $verb): bool
    {
        return self::statusFor($verb) !== self::STATUS_DRAFT;
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
