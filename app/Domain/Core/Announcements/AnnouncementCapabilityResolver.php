<?php
/**
 * The three announcement capability scopes — api-contract-v1.md §4.32.11 —
 * as one pure decision, consumed by every announcement response.
 *
 * ── WHAT THIS CLASS IS FOR ───────────────────────────────────────────────
 * §4.32.11 splits capabilities into scopes that are deliberately distinct
 * TYPES rather than one optional bag:
 *
 *   (a) the feature gate            `Card.announcements` — presence IS the gate
 *   (b) owner-level, per PAGE       `can_create` · `can_manage`
 *   (c) row-level, per ANNOUNCEMENT `can_edit` · `can_pin` · `can_archive`
 *                                   `can_manage_comments` · `can_comment`
 *   (d) administrator, per ROUTE    `can_inspect_drafts` · `can_inspect_removed`
 *                                   `can_restore_comment`
 *
 * Each has its own producer below, and the types do not mix. The contract
 * puts two locks on that separation and both are structural here, not
 * commentary:
 *
 *   - `can_comment` MUST NEVER appear in the owner scope. A page-level
 *     "may comment" would authorize commenting on EVERY announcement,
 *     including archived ones and ones not yet published.
 *     {@see self::forOwnerScope()} cannot emit it: it has no row to decide
 *     it from.
 *   - `can_manage` AUTHORIZES NO ROW MUTATION. It opens the management
 *     surface and nothing more. {@see self::forRow()} re-derives every row
 *     capability from that row's own state, so an archived, draft or
 *     scheduled row cannot inherit permission from the page.
 *
 * ── ⚠⚠ THERE IS NO PUBLIC-RESPONSE PRODUCER, AND THAT IS THE POINT ───────
 * The public list and the rotator carry **no** `capabilities`, for any
 * viewer, INCLUDING the resolved operator. That is not enforced by a
 * conditional in this class — it is enforced by the ABSENCE of a method
 * that would produce them. A public serializer has nothing to call.
 *
 * A conditional would have been the obvious design and it is the wrong
 * one: a conditional can be inverted by a later edit, mis-ordered, or
 * reached with the wrong argument, and the failure mode is a private state
 * on a cacheable public response. Nothing can accidentally emit a shape
 * that has no producer.
 *
 * ── ⚠⚠ IT CONSUMES AN OWNERSHIP DECISION; IT NEVER RESOLVES ONE ──────────
 * Every producer takes the ARRAY returned by
 * {@see \BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority::decide()},
 * never a page id, never a user id, never a boolean.
 *
 * That is deliberate and it is the security property, not an ergonomic
 * choice:
 *
 *   - A `bool $isOperator` parameter would let any caller assert
 *     ownership. §4.32.2 forbids `post_author`, mirror claim rows, page
 *     metadata, PeepSo membership and denormalized read models as
 *     authorization inputs — and a boolean accepts all five without a
 *     trace.
 *   - A `(int $pageId, int $viewerId)` signature would make this class
 *     resolve ownership itself, giving the codebase a FIFTH answer to a
 *     question that already had four divergent ones. #319 exists precisely
 *     to collapse those.
 *   - Taking the decision array means the only way to get `true` out of
 *     here is to have obtained it from the single authority. The shape is
 *     also unforgeable in practice: PHPStan level 8 types the parameter,
 *     and a hand-built array with a literal operator decision is exactly
 *     as visible in review as a call to the authority would be.
 *
 * Consequence worth stating: this class is **pure**. No WordPress, no
 * PeepSo, no DB, no option read, no clock. The entire capability matrix is
 * unit-testable with no bootstrap, which is why the suite can be
 * exhaustive rather than representative.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DECIDE ─────────────────────────────────
 * `can_comment` depends on things ownership says nothing about —
 * authentication, member standing, suspension, fraud lock. Those arrive as
 * a {@see self::forRow()} `$viewer` array, computed by the caller from the
 * existing standing services. This class owns the ORDER in which those
 * reasons are reported (§4.32.11's closed inventory) and nothing else:
 * inventing a standing check here would be a second implementation of
 * member standing.
 *
 * ⚠ **Reportability is NOT represented.** There is no reporting
 * capability, and its absence is a recorded decision rather than an
 * oversight: §4.32.15 carries reporting as an **open proposal**, and
 * §4.32.9's reason-code inventory has no entry for it. Adding a capability
 * the contract has not accepted would make the client render a control
 * whose server side does not exist. When the moderation integration is
 * designed, the decision point is `ModerationQueueService`'s queue-kind
 * map plus a new row capability here.
 * {@see self::REPORTABILITY_UNDECIDED}.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Announcements;

use BCC\Trust\Core\Support\Permission;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type Entry from \BCC\Trust\Core\Support\Permission
 */
final class AnnouncementCapabilityResolver
{
    // ── Row read-states this resolver understands (§4.32.13) ────────────

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_BLOCKED   = 'blocked';

    /**
     * Capability reason codes (§4.32.11). These are CAPABILITY reasons —
     * why a control is disabled on a SUCCESSFUL read — not error codes.
     * §4.32.9 describes a rejected request. The spelling overlap is
     * intentional so the disabled control and the eventual 403 give the
     * same account.
     */
    public const REASON_ARCHIVED          = 'announcement_archived';
    public const REASON_NOT_PUBLISHED     = 'announcement_not_published';
    public const REASON_UNAVAILABLE       = 'announcement_unavailable';
    public const REASON_COMMENTS_DISABLED = 'comments_disabled';
    public const REASON_AUTH_REQUIRED     = 'auth_required';
    public const REASON_SUSPENDED         = 'suspended';
    public const REASON_FRAUD_LOCKED      = 'fraud_locked';
    public const REASON_FEATURE_DISABLED  = 'feature_disabled';

    /**
     * The one wire reason for every ownership refusal, whatever the
     * underlying cause (§4.32.2). Re-exported from the authority rather
     * than re-declared so the two cannot drift: a second literal is how a
     * `claim_ambiguous` finds its way back onto the wire.
     */
    public const REASON_NOT_CLAIMER = ValidatorPageOperatorAuthority::PUBLIC_REASON;

    /**
     * The closed inventory of comment-gate denial reasons (§4.32.11).
     * Published as a constant so a test can assert the set, and so a new
     * reason cannot be introduced without appearing here — the frontend
     * keys distinct copy off each value and closes the composer on an
     * unrecognised one.
     *
     * @var list<string>
     */
    public const CAN_COMMENT_REASONS = [
        self::REASON_AUTH_REQUIRED,
        self::REASON_COMMENTS_DISABLED,
        self::REASON_ARCHIVED,
        self::REASON_UNAVAILABLE,
        self::REASON_SUSPENDED,
        self::REASON_FRAUD_LOCKED,
        self::REASON_FEATURE_DISABLED,
    ];

    /**
     * Marker for the one product decision this class is waiting on. Read
     * by a test that asserts no reportability capability has been added
     * silently — see the class docblock.
     */
    public const REPORTABILITY_UNDECIDED = true;

    // ── (b) Owner-level, per page ────────────────────────────────────────

    /**
     * `Card.announcements` — the whole block, or `null`.
     *
     * Returning `null` is the feature-OFF answer and it is NOT the same as
     * returning the block with everything denied. §4.32.11: presence of
     * the block is the gate, so an emitted-but-denied block would make the
     * client mount the surface and issue requests that are all going to
     * 404. The caller omits the key entirely on `null`.
     *
     * ⚠ The two entries are page-scoped and authorize nothing on any row.
     * The comment gate is structurally absent — there is no row here to
     * decide it from.
     *
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     *        as returned by ValidatorPageOperatorAuthority::decide()
     * @param bool $featureEnabled the `bcc_announcements_enabled` option
     *
     * @return array{capabilities: array{can_create: Entry, can_manage: Entry}}|null
     */
    public static function forOwnerScope(array $decision, bool $featureEnabled): ?array
    {
        if (!$featureEnabled) {
            return null;
        }

        if (!self::isOperator($decision)) {
            // The block is still EMITTED for a non-operator: the feature
            // is on, so the public bar and tab must render. Only the owner
            // affordances are denied, and with the generic reason.
            $denied = Permission::deny(self::REASON_NOT_CLAIMER);
            return ['capabilities' => ['can_create' => $denied, 'can_manage' => $denied]];
        }

        return [
            'capabilities' => [
                'can_create' => Permission::allow(),
                'can_manage' => Permission::allow(),
            ],
        ];
    }

    // ── (c) Row-level, per announcement ──────────────────────────────────

    /**
     * The five row capabilities for one announcement.
     *
     * Emitted only by announcement-scoped responses — detail, owner-list
     * rows, and (for the comment gate) the comments page. Never by a
     * public list, which has no producer at all.
     *
     * `$row` is the announcement's own state, already read from storage:
     *   - `status`            one of the four §4.32.13 READ states
     *   - `is_archived`       bool
     *   - `comments_enabled`  bool
     *
     * `$viewer` is the viewer's standing, computed by the caller from the
     * existing services — this class performs no standing check:
     *   - `authenticated`  bool
     *   - `suspended`      bool
     *   - `fraud_locked`   bool
     *   - `good_standing`  bool  (member standing for commenting)
     *
     * Missing keys fail closed: an absent `authenticated` reads as not
     * authenticated, an absent `is_archived` reads as archived. A partial
     * array is a caller bug, and the safe interpretation of a caller bug
     * is the most restrictive one.
     *
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     * @param array<string, mixed> $row
     * @param array<string, mixed> $viewer
     *
     * @return array{
     *     can_edit: Entry, can_pin: Entry, can_archive: Entry,
     *     can_manage_comments: Entry, can_comment: Entry
     * }
     */
    public static function forRow(
        array $decision,
        array $row,
        array $viewer,
        bool $featureEnabled
    ): array {
        $isOperator = self::isOperator($decision);
        $archived   = self::flag($row, 'is_archived', true);   // absent ⇒ archived
        $published  = self::status($row) === self::STATUS_PUBLISHED;

        return [
            'can_edit'            => self::ownerAction($isOperator, $archived, $featureEnabled),
            'can_pin'             => self::pin($isOperator, $archived, $published, $featureEnabled),
            'can_archive'         => self::ownerAction($isOperator, $archived, $featureEnabled),
            // ⚠ STAYS TRUE ON AN ARCHIVED ROW. §4.32.7 keeps an archived
            // discussion visible and read-only while operator moderation
            // remains available — a closed discussion nobody can clean up
            // is worse than a closed discussion.
            'can_manage_comments' => self::manageComments($isOperator, $featureEnabled),
            'can_comment'         => self::comment($row, $viewer, $archived, $published, $featureEnabled),
        ];
    }

    /**
     * The comment gate alone, for the comments response.
     *
     * §4.32.11: the gate travels on the COMMENTS response, not on the
     * announcement, and is returned on **every page** so an archive that
     * happens mid-scroll is reflected rather than the value page 1 was
     * built with. Returning a one-key map rather than the full five keeps
     * the comments response from carrying owner affordances it has no
     * surface for.
     *
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     * @param array<string, mixed> $row
     * @param array<string, mixed> $viewer
     *
     * @return array{can_comment: Entry}
     */
    public static function forCommentsResponse(
        array $decision,
        array $row,
        array $viewer,
        bool $featureEnabled
    ): array {
        $full = self::forRow($decision, $row, $viewer, $featureEnabled);
        return ['can_comment' => $full['can_comment']];
    }

    // ── (d) Administrator — a separate object, never merged ──────────────

    /**
     * Administrator recovery rights (§4.32.2): READ-ONLY inspection plus
     * comment restoration. An administrator may not create, edit,
     * schedule, publish, pin or archive, and may not act as the operator.
     *
     * ⚠ This is a THIRD object, never merged into the owner or row scopes.
     * An administrator who is independently the resolved operator gets
     * operator rights through {@see self::forOwnerScope()} and
     * {@see self::forRow()} in the normal way — never through an override
     * here. So `forRow()` deliberately takes no "is administrator" flag:
     * there is no code path by which being an administrator raises a row
     * capability.
     *
     * @return array{
     *     can_inspect_drafts: Entry, can_inspect_removed: Entry,
     *     can_restore_comment: Entry
     * }
     */
    public static function forAdministrator(bool $isAdministrator, bool $featureEnabled): array
    {
        if (!$featureEnabled || !$isAdministrator) {
            $reason = $featureEnabled ? self::REASON_NOT_CLAIMER : self::REASON_FEATURE_DISABLED;
            $denied = Permission::deny($reason);
            return [
                'can_inspect_drafts'  => $denied,
                'can_inspect_removed' => $denied,
                'can_restore_comment' => $denied,
            ];
        }

        return [
            'can_inspect_drafts'  => Permission::allow(),
            'can_inspect_removed' => Permission::allow(),
            'can_restore_comment' => Permission::allow(),
        ];
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * ⚠ Reads the decision ARRAY, and only the one shape the authority
     * emits. Anything else — a bare bool, a truthy string, a missing key —
     * is not an operator.
     *
     * @param array<string, mixed> $decision
     */
    private static function isOperator(array $decision): bool
    {
        return ($decision['decision'] ?? null) === ValidatorPageOperatorAuthority::DECISION_OPERATOR;
    }

    /**
     * Edit and archive share a ladder exactly, so they share a producer.
     * Two copies of a fail-closed sequence is how divergent answers start.
     *
     * @return Entry
     */
    private static function ownerAction(bool $isOperator, bool $archived, bool $featureEnabled): array
    {
        if (!$featureEnabled) {
            return Permission::deny(self::REASON_FEATURE_DISABLED);
        }
        if (!$isOperator) {
            return Permission::deny(self::REASON_NOT_CLAIMER);
        }
        if ($archived) {
            // No unlock hint: archive is one-way (§4.32.6), so there is no
            // action the operator could take to re-enable this. A hint
            // here would promise a path that does not exist.
            return Permission::deny(self::REASON_ARCHIVED);
        }
        return Permission::allow();
    }

    /**
     * Pin carries one rung the others do not: an unpublished row cannot be
     * pinned, because pinning promotes a row on the public validator page
     * and a draft has nothing to promote.
     *
     * ⚠ Archived is checked BEFORE published. An archived announcement was
     * published once and is automatically unpinned (§4.32.6); reporting it
     * as not-published would be false, and would send the client the one
     * reason that implies "publish it and try again".
     *
     * @return Entry
     */
    private static function pin(
        bool $isOperator,
        bool $archived,
        bool $published,
        bool $featureEnabled
    ): array {
        if (!$featureEnabled) {
            return Permission::deny(self::REASON_FEATURE_DISABLED);
        }
        if (!$isOperator) {
            return Permission::deny(self::REASON_NOT_CLAIMER);
        }
        if ($archived) {
            return Permission::deny(self::REASON_ARCHIVED);
        }
        if (!$published) {
            return Permission::deny(self::REASON_NOT_PUBLISHED);
        }
        return Permission::allow();
    }

    /**
     * Moderation survives archival — the one row capability that does.
     *
     * @return Entry
     */
    private static function manageComments(bool $isOperator, bool $featureEnabled): array
    {
        if (!$featureEnabled) {
            return Permission::deny(self::REASON_FEATURE_DISABLED);
        }
        if (!$isOperator) {
            return Permission::deny(self::REASON_NOT_CLAIMER);
        }
        return Permission::allow();
    }

    /**
     * The comment gate. Reasons are reported in a FIXED order, because two
     * conditions can hold at once and the client renders distinct copy per
     * reason — so which one wins is contract, not an implementation
     * detail.
     *
     * Order, most structural first:
     *   1. feature off       — nothing about this surface exists
     *   2. not available     — not published/due, so there is no discussion
     *   3. archived          — the discussion exists and is read-only
     *   4. comments disabled — the operator switched this one off
     *   5. auth required     — a signed-in viewer could comment
     *   6. suspended         — this viewer specifically may not
     *   7. fraud locked      — likewise
     *   8. standing          — likewise, reported as `suspended` (see below)
     *
     * ⚠ Ownership is NOT a rung. An operator is not privileged here, and
     * the generic ownership reason must never appear as a comment-gate
     * reason: it is not in §4.32.11's closed inventory, and emitting it
     * would tell an ordinary member that commenting is an ownership
     * question. Members comment; operators are members too.
     *
     * ⚠ Rungs 2 and 3 come BEFORE the viewer rungs on purpose. They are
     * facts about the announcement and are identical for every viewer, so
     * reporting them first keeps an anonymous reader from learning
     * anything viewer-specific — and keeps the reason stable across
     * viewers for a cacheable surface.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $viewer
     *
     * @return Entry
     */
    private static function comment(
        array $row,
        array $viewer,
        bool $archived,
        bool $published,
        bool $featureEnabled
    ): array {
        if (!$featureEnabled) {
            return Permission::deny(self::REASON_FEATURE_DISABLED);
        }
        if (!$published) {
            return Permission::deny(self::REASON_UNAVAILABLE);
        }
        if ($archived) {
            return Permission::deny(self::REASON_ARCHIVED);
        }
        if (!self::flag($row, 'comments_enabled', false)) {
            return Permission::deny(self::REASON_COMMENTS_DISABLED);
        }
        if (!self::flag($viewer, 'authenticated', false)) {
            return Permission::deny(
                self::REASON_AUTH_REQUIRED,
                'Sign in to join the discussion.'
            );
        }
        if (self::flag($viewer, 'suspended', true)) {
            return Permission::deny(self::REASON_SUSPENDED);
        }
        if (self::flag($viewer, 'fraud_locked', true)) {
            return Permission::deny(self::REASON_FRAUD_LOCKED);
        }
        if (!self::flag($viewer, 'good_standing', false)) {
            // ⚠ Reported as `suspended`, not as a new code. §4.32.11's
            // inventory is CLOSED and has no "standing" entry; the
            // frontend closes the composer on an unrecognised reason, so
            // inventing one here would produce a disabled control with no
            // copy. Standing and suspension present identically to a
            // member: "you may not post right now".
            return Permission::deny(self::REASON_SUSPENDED);
        }
        return Permission::allow();
    }

    /**
     * Read a boolean that may be absent, mistyped, or arrive from a cache
     * or a decoded payload.
     *
     * `$absent` is the value a MISSING or non-boolean key takes, and every
     * caller passes the restrictive one: `is_archived` absent means
     * archived, `comments_enabled` absent means closed, `authenticated`
     * absent means anonymous. ⚠ Strict boolean comparison, never truthy —
     * the string "false", the string "0" and the empty array are each
     * truthy or castable in a direction someone will find surprising.
     *
     * @param array<string, mixed> $source
     */
    private static function flag(array $source, string $key, bool $absent): bool
    {
        $value = $source[$key] ?? null;
        if (!is_bool($value)) {
            return $absent;
        }
        return $value;
    }

    /**
     * The row's READ state. An unrecognised or missing status is NOT
     * published — a row whose state this class has not been taught is a
     * row whose publication cannot be vouched for.
     *
     * ⚠ `blocked` deliberately lands here as "not published", which is
     * what it is: §4.32.13 defines it as a row forced to `publish`
     * WITHOUT a valid publication-authorization marker and moved to a
     * non-public state. Treating it as published would hand the client
     * capabilities over exactly the row the reconciler flagged.
     *
     * @param array<string, mixed> $row
     */
    private static function status(array $row): string
    {
        $status = $row['status'] ?? null;
        if (!is_string($status)) {
            return self::STATUS_DRAFT;
        }
        return in_array($status, [
            self::STATUS_DRAFT,
            self::STATUS_SCHEDULED,
            self::STATUS_PUBLISHED,
            self::STATUS_BLOCKED,
        ], true) ? $status : self::STATUS_DRAFT;
    }
}
