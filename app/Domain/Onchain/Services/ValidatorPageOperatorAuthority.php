<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ClaimRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The ONE answer to "is this viewer the authoritative verified operator
 * of this validator page?" — and the only answer any operator-gated
 * validator write may consult.
 *
 * ## Why this class exists
 *
 * Four different code paths currently answer a question that sounds the
 * same and is not, and they disagree:
 *
 *   1. {@see ClaimRepository::resolveVerifiedOperatorForPage} — validator
 *      leg only, `claim_role = 'operator'` strictly, AMBIGUOUS detected
 *      and never resolved. The strictest and the only one that fails
 *      closed on rival claims.
 *   2. {@see ClaimRepository::userHasVerifiedClaimOnPage} — validator OR
 *      collection leg, `claim_role IN ('operator','creator')`, and it
 *      returns TRUE for ANY ONE of several rivals because it has no
 *      ambiguity concept. This is the live page-image write gate.
 *   3. `CardViewService::viewerHasClaim()` — the best-effort
 *      `entity_type='page'` MIRROR row, status only, no role filter. A
 *      failed mirror write is never repaired, so this both over- and
 *      under-reports. It drives `can_post_as_entity`, `can_edit_bio` and
 *      `can_edit_image`.
 *   4. `BCC\Core\Permissions\Permissions::owns_page()` — resolves through
 *      PeepSo to `wp_posts.post_author`. This is the dispute gate.
 *
 * Only (1) is admissible as an authorization input for operator-authored
 * content. (3) and (4) are explicitly FORBIDDEN: the mirror row is
 * unrepaired state, and `post_author` is moved by the claim flow itself,
 * so treating it as authority makes authorship its own proof.
 *
 * This class wraps (1) and adds the one thing none of the four does:
 * **binding the resolution to the acting viewer**. Every existing caller
 * of the resolver uses it to find out WHERE a message should go; none of
 * them asks whether the CALLER is that operator. That distinction is the
 * whole of an authorization check.
 *
 * ## Fail-closed posture
 *
 * `UNCLAIMED` and `AMBIGUOUS` both deny. `AMBIGUOUS` is a state the
 * application-level claim exclusivity is supposed to make impossible, so
 * it is an incident, not a branch to resolve: picking a winner from
 * unordered SQL output would hand operator rights to whichever row the
 * planner happened to return.
 *
 * ## Identity non-exposure
 *
 * {@see decide()} returns a private reason for audit AND a separate
 * {@see publicReason()} for the wire, which collapses every denial to one
 * value. A caller that leaks the private reason tells an attacker whether
 * a page is unclaimed, held by someone else, or contested — the last of
 * which is exactly what `resolvePageMessagingState` already refuses to
 * reveal ("the wire must not reveal that competing claims exist").
 *
 * ⚠ The accepted contract is INTERNALLY INCONSISTENT here and the
 * conflict is deliberately not resolved in this class: api-contract-v1.md
 * §4.32.2 requires "a single generic 403 that leaks neither operator
 * identity nor claim count", while §4.32.9 enumerates `not_claimer`,
 * `claim_ambiguous` and `claim_absent` as distinct wire `reason_code`
 * values — which distinguish precisely the three states §4.32.2 says must
 * be indistinguishable. This class implements the strict §4.32.2 reading
 * (one wire value) and keeps the distinction available for audit, so
 * adopting the §4.32.9 enumeration later is a one-line change at the
 * serializer rather than a rework here. The product decision is open.
 *
 * ## Scope
 *
 * Validator pages only. Collection/creator claim semantics need their own
 * audit before they confer authorship rights — {@see isValidatorPage()}
 * refuses anything else rather than silently widening to the collection
 * leg the way (2) does.
 *
 * NOT wired to any route. This is the A0 foundation; the announcement
 * surfaces that consume it are A2/A3/A5 and the feature switch is off.
 *
 * @package BCC\Trust\Onchain\Services
 */
final class ValidatorPageOperatorAuthority
{
    /** The viewer IS the single verified operator of this validator page. */
    public const DECISION_OPERATOR = 'operator';

    /** The viewer is not. Every denial carries this decision. */
    public const DECISION_DENIED = 'denied';

    /**
     * Private reasons — for the audit log and for tests. NEVER put one
     * of these on the wire; use {@see publicReason()}.
     */
    public const REASON_AUTH_REQUIRED   = 'auth_required';
    public const REASON_NOT_A_VALIDATOR = 'not_a_validator_page';
    public const REASON_CLAIM_ABSENT    = 'claim_absent';
    public const REASON_CLAIM_AMBIGUOUS = 'claim_ambiguous';
    public const REASON_NOT_CLAIMER     = 'not_claimer';

    /**
     * The ONE reason code a denied client ever sees. Collapsing all four
     * denial causes to this value is what keeps claim existence, claim
     * ownership and claim contention indistinguishable from outside.
     */
    public const PUBLIC_REASON = 'not_claimer';

    /** Post type backing every validator page. */
    private const PAGE_POST_TYPE = 'peepso-page';

    /**
     * Resolve the viewer's standing on this page.
     *
     * Exactly one claim resolution per call; no fanout, no caching (a
     * cached authorization is a stale authorization, and claim revocation
     * is a row DELETE that no cache is invalidated by).
     *
     * @return array{
     *     decision: self::DECISION_OPERATOR|self::DECISION_DENIED,
     *     reason: string|null,
     *     operator_user_id: int|null
     * } `operator_user_id` is populated ONLY on DECISION_OPERATOR, where
     *   it necessarily equals $viewerId — it is returned so callers can
     *   stamp an authorization marker without re-resolving, which is the
     *   §4.32.1 publication path.
     */
    public static function decide(int $pageId, int $viewerId): array
    {
        if ($viewerId <= 0) {
            return self::denied(self::REASON_AUTH_REQUIRED);
        }
        if ($pageId <= 0 || !self::isValidatorPage($pageId)) {
            return self::denied(self::REASON_NOT_A_VALIDATOR);
        }

        $resolution = ClaimRepository::resolveVerifiedOperatorForPage($pageId);
        $state      = $resolution['state'] ?? ClaimRepository::OPERATOR_UNCLAIMED;

        // AMBIGUOUS first: it carries user_id === null, so a mis-ordered
        // check that compared ids before states would read as "not the
        // claimer" and lose the fact that this page is contested. The
        // resolver has already recorded the metric and the audit row.
        if ($state === ClaimRepository::OPERATOR_AMBIGUOUS) {
            return self::denied(self::REASON_CLAIM_AMBIGUOUS);
        }
        if ($state !== ClaimRepository::OPERATOR_RESOLVED) {
            // UNCLAIMED, or any state this resolver gains later that this
            // class has not been taught. Unknown states deny.
            return self::denied(self::REASON_CLAIM_ABSENT);
        }

        $operatorId = $resolution['user_id'] ?? null;
        if (!is_int($operatorId) || $operatorId <= 0) {
            // RESOLVED without a usable id is a contradiction. Deny
            // rather than trust half a resolution.
            return self::denied(self::REASON_CLAIM_ABSENT);
        }
        if ($operatorId !== $viewerId) {
            return self::denied(self::REASON_NOT_CLAIMER);
        }

        return [
            'decision'         => self::DECISION_OPERATOR,
            'reason'           => null,
            'operator_user_id' => $operatorId,
        ];
    }

    /**
     * Boolean form, for a gate that only needs yes/no.
     *
     * Deliberately delegates rather than re-implementing the ladder: two
     * copies of a fail-closed sequence is how the four divergent answers
     * above came about in the first place.
     */
    public static function isOperator(int $pageId, int $viewerId): bool
    {
        return self::decide($pageId, $viewerId)['decision'] === self::DECISION_OPERATOR;
    }

    /**
     * The wire reason for any denial. Constant by design — see
     * PUBLIC_REASON. Takes the decision array so a call site reads as a
     * translation step and cannot accidentally pass the private reason
     * through.
     *
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     */
    public static function publicReason(array $decision): ?string
    {
        return $decision['decision'] === self::DECISION_OPERATOR ? null : self::PUBLIC_REASON;
    }

    /**
     * Whether a validator entity actually backs this page.
     *
     * Two conditions, both required: the post is a published
     * `peepso-page`, and at least one validator row binds to it. The
     * second is what keeps this from widening to collection-backed pages
     * — the resolver would return UNCLAIMED for those anyway, but then a
     * creator-claimed collection page would deny with `claim_absent`,
     * which reads as "nobody has claimed this" when the truth is "this
     * page is out of scope". The distinction matters for the audit trail.
     */
    private static function isValidatorPage(int $pageId): bool
    {
        $post = get_post($pageId);
        if (!$post instanceof \WP_Post
            || $post->post_type !== self::PAGE_POST_TYPE
            || $post->post_status !== 'publish'
        ) {
            return false;
        }

        return \BCC\Trust\Onchain\Repositories\ValidatorRepository::findFirstByPageId($pageId) !== null;
    }

    /**
     * @return array{decision: self::DECISION_DENIED, reason: string, operator_user_id: null}
     */
    private static function denied(string $reason): array
    {
        return [
            'decision'         => self::DECISION_DENIED,
            'reason'           => $reason,
            'operator_user_id' => null,
        ];
    }
}
