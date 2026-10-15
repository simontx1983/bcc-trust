<?php

/**
 * A5 — §4.32.7 announcement comment policy. Pure decisions, no WordPress.
 *
 * Everything here is a function of its arguments, which is why it carries the
 * two rules that are easiest to get subtly wrong:
 *
 *   1. WHO MAY REMOVE, AND WHAT ACTOR TYPE IS RECORDED. §4.32.7's table is not
 *      a role lookup: "An operator removing THEIR OWN comment is recorded as
 *      `author` — the actor's relationship to the comment wins over their
 *      role." Implemented as a relationship test FIRST, role second.
 *   2. WHAT A TOMBSTONE MAY SAY. The label is server-authored and is one of
 *      exactly two strings. The vocabulary is closed here so no caller can
 *      invent a third.
 *
 * ⚠ Body handling is NORMALISE-AND-KEEP, not strip-and-hope. §4.32.7 says the
 * body is plain text and that there is "no executable HTML and no Markdown
 * rendering" — that is a statement about RENDERING, not a licence to rewrite
 * what a member typed. Stripping tags would silently mangle ordinary text
 * ("5 < 10 and 20 > 15" loses its middle), and rejecting them would refuse a
 * legitimate comment. So the body is stored verbatim after normalisation and
 * is never rendered as HTML by this codebase; escaping at the render boundary
 * is the consumer's job, and the API returns a JSON string, never markup.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Announcements;

if (!defined('ABSPATH')) {
    exit;
}

final class AnnouncementCommentPolicy
{
    /** §4.32.7 — 1–2000 characters AFTER normalisation. */
    public const MIN_LEN = 1;
    public const MAX_LEN = 2000;

    /** Recorded actor types. A closed set. */
    public const ACTOR_AUTHOR    = 'author';
    public const ACTOR_VALIDATOR = 'validator';

    /**
     * ⚠⚠ The two tombstone labels, verbatim from §4.32.7. Server-authored and
     * closed: a tombstone may say one of exactly these two things.
     */
    public const LABEL_AUTHOR    = 'Comment removed by author';
    public const LABEL_VALIDATOR = 'Comment removed by validator';

    /** Audit actions — §4.32.7 requires three DISTINCT ones. */
    public const AUDIT_REMOVED_BY_AUTHOR    = 'announcement_comment_removed_by_author';
    public const AUDIT_REMOVED_BY_VALIDATOR = 'announcement_comment_removed_by_validator';
    public const AUDIT_RESTORED_BY_ADMIN    = 'announcement_comment_restored_by_admin';

    /** Error reasons this policy can produce. */
    public const REASON_REPLIES_UNSUPPORTED = 'replies_not_supported';
    public const REASON_NOT_COMMENT_AUTHOR  = 'not_comment_author';
    public const REASON_COMMENTS_DISABLED   = 'comments_disabled';
    public const REASON_ARCHIVED            = 'announcement_archived';
    public const REASON_NOT_PUBLISHED       = 'announcement_not_published';

    /**
     * Normalise a submitted body, or refuse.
     *
     * Refuses (returns null) rather than repairing, which is the house rule
     * already established by `Support\BoundedIntParam` and
     * `AnnouncementTransition::normalizeInstant()`: a value that is not what
     * the caller claimed is a 400, not a different value served silently.
     *
     * Normalisation, in order:
     *   - reject non-string and invalid UTF-8 outright;
     *   - normalise CRLF and bare CR to LF, so a Windows client and a Unix
     *     client storing the same text store the same bytes;
     *   - reject C0 control characters other than LF and TAB, which cannot be
     *     typed deliberately and are the shape of a smuggled payload;
     *   - trim leading/trailing whitespace;
     *   - bound the result by CHARACTER count, not bytes.
     *
     * ⚠ Length is measured with `mb_strlen`, deliberately. A 2000-character
     * limit measured in bytes would cut a CJK comment to a third of the
     * allowance and an emoji-bearing one to a quarter.
     */
    public static function normalizeBody(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $raw);

        // C0 controls except LF (0x0A) and TAB (0x09), plus DEL.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            return null;
        }

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $len = mb_strlen($text, 'UTF-8');
        if ($len < self::MIN_LEN || $len > self::MAX_LEN) {
            return null;
        }

        return $text;
    }

    /**
     * Which actor type is recorded for a removal, or null if not permitted.
     *
     * ⚠⚠ RELATIONSHIP BEFORE ROLE. §4.32.7: "An operator removing their own
     * comment is recorded as `author` — the actor's relationship to the comment
     * wins over their role." Getting this backwards would publish a tombstone
     * saying "removed by validator" on a comment the operator wrote
     * themselves, which misrepresents a moderation action that never happened.
     *
     * ⚠ An administrator is NOT listed. §4.32.7's table gives administrators
     * "— (restore only)", so this returns null for an admin who is neither the
     * author nor the resolved operator. Restoration is a separate decision
     * ({@see mayRestore()}).
     */
    public static function removalActorType(
        int $actorId,
        int $commentAuthorId,
        bool $actorIsOperator
    ): ?string {
        if ($actorId <= 0) {
            return null;
        }

        // Relationship first.
        if ($commentAuthorId > 0 && $actorId === $commentAuthorId) {
            return self::ACTOR_AUTHOR;
        }

        if ($actorIsOperator) {
            return self::ACTOR_VALIDATOR;
        }

        return null;
    }

    /**
     * The server-authored tombstone label for a recorded actor type.
     *
     * Returns null for anything outside the closed set, so an unexpected
     * stored value produces a refusal rather than a tombstone with an
     * attacker-chosen caption.
     */
    public static function labelFor(string $actorType): ?string
    {
        return match ($actorType) {
            self::ACTOR_AUTHOR    => self::LABEL_AUTHOR,
            self::ACTOR_VALIDATOR => self::LABEL_VALIDATOR,
            default               => null,
        };
    }

    /** The distinct audit action for a removal, per §4.32.7. */
    public static function auditActionFor(string $actorType): ?string
    {
        return match ($actorType) {
            self::ACTOR_AUTHOR    => self::AUDIT_REMOVED_BY_AUTHOR,
            self::ACTOR_VALIDATOR => self::AUDIT_REMOVED_BY_VALIDATOR,
            default               => null,
        };
    }

    /**
     * ⚠ "Comment authors cannot restore; restoration is administrator-only."
     *
     * Expressed as its own predicate rather than folded into
     * {@see removalActorType()}, because the two questions have different
     * answers for the same person: an author may remove and may not restore.
     */
    public static function mayRestore(bool $actorIsAdministrator): bool
    {
        return $actorIsAdministrator;
    }

    /**
     * Is this a flat comment? §4.32.7 defers replies, and sending `parent_id`
     * is a `400 replies_not_supported` rather than a silently ignored field.
     *
     * ⚠ Any PRESENT parent_id is a refusal, including `0` and `''`. A client
     * that sends the field at all is using a threading model this version does
     * not have, and answering 201 would tell it the reply was threaded.
     */
    public static function isFlatSubmission(bool $parentIdWasSent): bool
    {
        return !$parentIdWasSent;
    }
}
