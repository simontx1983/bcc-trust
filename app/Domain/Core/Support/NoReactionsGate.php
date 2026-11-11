<?php
/**
 * No-Reactions Gate — refuses an engagement write against an activity
 * whose kind takes no reactions at all.
 *
 * One gate, three callers: {@see \BCC\Trust\Core\REST\ReactionsEndpoint},
 * {@see \BCC\Trust\Core\REST\StokeEndpoint} and
 * {@see \BCC\Trust\Core\REST\HelpfulMarkEndpoint}. Deliberately a sibling
 * of {@see GroupInteractionGate} in shape and placement — same
 * `?WP_REST_Response` contract, same position in each handler (after the
 * act id is validated, before any write) — because three copies of a
 * refusal is how the three drift apart.
 *
 * ## The rule is owned by bcc-core, not duplicated here
 *
 * This gate refuses when the activity's module resolves to the
 * **no-reactions grammar** — the one whose kind set is empty. It does NOT
 * hardcode module 205. That matters twice over:
 *
 *   - the policy has ONE home. bcc-core's post_kind → grammar map decides
 *     which kinds take no reactions; this gate only enforces it. A second
 *     kind joining needs no change here at all.
 *   - a hardcoded `205` would silently diverge the moment the map said
 *     something different, and the divergence would be invisible: the read
 *     path would show an empty grammar while the write path still accepted.
 *
 * ⚠ THE GRAMMAR VALUE IS A LOCAL WIRE LITERAL, DELIBERATELY, and this is
 * the one duplication in the design. `ReactionGrammarMap::GRAMMAR_NONE`
 * ships in bcc-core, which deploys FIRST (api-contract-v1.md §4.32.15) —
 * but bcc-trust's static analysis runs against bcc-core's DEFAULT BRANCH,
 * so referencing the constant directly would make this file unanalysable,
 * and the PR unmergeable, until the bcc-core half landed. A compile-time
 * dependency on an unmerged constant is a worse problem than a repeated
 * wire value: the value is already repeated in the contract and in the
 * consuming client's own type union, which is what a wire constant is.
 *
 * What is NOT duplicated is the POLICY. Which kinds take no reactions is
 * still decided solely by bcc-core's map, and this gate learns the answer
 * by asking it. {@see NoReactionsGateTest} asserts the literal equals
 * bcc-core's constant as soon as that constant exists, and SKIPS with a
 * named reason until then — so the skip is the ordering signal, and the
 * drift is caught the moment the dependency is satisfiable.
 *
 * ## Why a write refusal is needed when the grammar already empties
 *
 * The empty kind set already makes `kindBelongsToGrammar()` false for
 * every kind, which closes the *reactions* set-reaction path. It closes
 * nothing else:
 *
 *   - **Stoke** has its own table and its own endpoint and never consults
 *     a grammar.
 *   - **Helpful marks** likewise — and a helpful mark mints rank evidence
 *     crediting the CONTENT AUTHOR, so leaving that path open would let an
 *     announcement move its own operator's standing.
 *   - reaction REMOVAL is not grammar-gated either.
 *
 * So the grammar states intent and closes one of four doors. This gate
 * closes the rest.
 *
 * ## This is the server half of a two-half guarantee
 *
 * The consuming client suppresses the affordances separately, keyed on the
 * item kind. The two are deliberately redundant: this refuses the write
 * even if a client renders a control, and the client renders nothing even
 * if this regresses. Neither half depends on the other being correct, and
 * neither is sufficient alone — a hidden control that the server would
 * still honour is not a policy, and a visible control that always errors
 * is not a product.
 *
 * @package BCC\Trust\Core\Support
 */

namespace BCC\Trust\Core\Support;

use BCC\Core\Feed\ReactionGrammarMap;
use BCC\Core\Repositories\PeepSoActivityRepository;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class NoReactionsGate
{
    /**
     * The one wire reason. A reader who sees this has not hit a transient
     * failure and must not retry — the kind simply has no engagement
     * surface.
     */
    public const REASON = 'reactions_not_applicable';

    /**
     * The wire value of bcc-core's no-reactions grammar. See the class
     * docblock for why this is a literal rather than a reference to
     * `ReactionGrammarMap::GRAMMAR_NONE`, and for the test that ties the
     * two together the moment the constant exists.
     */
    public const GRAMMAR_NONE_WIRE = 'none';

    /**
     * Returns an error response to short-circuit on, or null when the
     * activity's kind does accept engagement.
     *
     * Fails OPEN on an unknown act, deliberately: an act id that resolves
     * to no module is not a no-reactions concern, and swallowing it here
     * would mask the caller's own not-found path. Every caller already
     * surfaces that case, and an unknown act cannot be an announcement.
     */
    public static function check(int $actId): ?WP_REST_Response
    {
        if ($actId <= 0) {
            return null;
        }

        $module = PeepSoActivityRepository::getModuleIdByActId($actId);
        if ($module === null || $module === '') {
            return null;
        }

        if (!self::moduleTakesNoReactions($module)) {
            return null;
        }

        return ApiResponse::error(
            'bcc_forbidden',
            'This kind of post does not take reactions.',
            403,
            ['reason_code' => self::REASON]
        );
    }

    /**
     * Whether this activity module's kind takes no reactions.
     *
     * Exposed so a read path can ask the same question the write path
     * answers — the feed hydrator uses it to omit the engagement fields
     * rather than re-deriving the rule from a module literal.
     */
    public static function moduleTakesNoReactions(string $moduleId): bool
    {
        return ReactionGrammarMap::grammarForModule($moduleId) === self::GRAMMAR_NONE_WIRE;
    }

    /**
     * Same question keyed by post_kind, for callers that already resolved
     * one (the hydrator reads `post_kind` off the row it is decorating and
     * would otherwise have to map back to a module to ask).
     */
    public static function kindTakesNoReactions(string $postKind): bool
    {
        return ReactionGrammarMap::grammarFor($postKind) === self::GRAMMAR_NONE_WIRE;
    }
}
