<?php
/**
 * ContestedStateExplainer — server-pinned copy for the §J.5 self-mirror
 * "why am I in this state" surface.
 *
 * Plan §9 critical-risk-mitigation item #7. Renders the operator's
 * current `divergence_state` as plain-language headline + body, surfaced
 * on `/me/reliability` only (self-only by construction — third-party
 * endpoints carry the state slug WITHOUT the explainer).
 *
 * **Why server-pinned (Phillip's PR-8a directive):**
 *
 * The explainer copy is contract-stable per §A2. Server is the single
 * source of truth; the FE renders `headline` + `body` verbatim. Copy
 * changes go through the contract (= this file), not a frontend deploy.
 * This is the PHP-side analogue of the existing
 * `bcc-frontend/src/lib/copy/trust-layer.ts` convention.
 *
 * **§2.7 status-anxiety mitigation rule:** the body avoids any "you
 * should X" nudge. It explains the *state*, not prescribes a remedy.
 * Crucial — once the surface tells operators "to fix this, attest
 * more," it becomes a cadence-pressure vector (failure mode §2.7).
 * Every body string passes the test: would a thoughtful operator
 * read this and feel pushed to act? If yes, the copy is wrong.
 *
 * **Per-state framing:**
 *   - `untested`        — neutral starting state, framed positively.
 *                         No "absence is bad" reading possible.
 *   - `well_regarded`   — earned without celebration. No leaderboard
 *                         framing.
 *   - `poorly_regarded` — factual observation about pattern. No
 *                         "you have a problem" framing — the state
 *                         is OUTCOME data, not character judgment.
 *   - `polarizing`      — explicit signal-worth-examining framing
 *                         per §J.2; not negative, not positive.
 *   - `disputed`        — fact-of-active-dispute, never verdict.
 *                         Body points at the resolution path without
 *                         demanding action.
 *
 * Copy is locked here. To change wording: amend §J.5 in the contract,
 * then mirror the change in this constants block, then ship.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer PR-8a (2026-05-14)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class ContestedStateExplainer
{
    /**
     * Per-state copy blocks. Both headline and body are required for
     * the §J.5 wire shape. Body strings are calibrated per the
     * §2.7 mitigation — descriptive, not prescriptive.
     *
     * @var array<string, array{headline: string, body: string}>
     */
    private const COPY = [
        DivergenceStateClassifier::STATE_UNTESTED => [
            'headline' => 'No reads yet.',
            'body' =>
                "The floor hasn't formed a read on you yet — most operators sit "
                . "here for a while. The graph doesn't grade silence as either "
                . "good or bad; it just waits for evidence.",
        ],
        DivergenceStateClassifier::STATE_WELL_REGARDED => [
            'headline' => 'Well regarded.',
            'body' =>
                "Operators who've attested to you have stayed with the call. "
                . "Most reads on you are positive and active — no current dispute, "
                . "no pattern of withdrawn judgment.",
        ],
        DivergenceStateClassifier::STATE_POORLY_REGARDED => [
            'headline' => 'Pattern of withdrawn reads.',
            'body' =>
                "More operators have withdrawn attestations on you than currently "
                . "stand behind their call. The floor reads this as a pattern, not "
                . "a verdict. The state changes as new evidence accrues.",
        ],
        DivergenceStateClassifier::STATE_POLARIZING => [
            'headline' => 'Polarizing.',
            'body' =>
                "Reliable operators disagree about you in roughly equal numbers — "
                . "the floor reads this as a signal worth examining, not as "
                . "condemnation. Polarizing entities are often the most interesting "
                . "to read up on.",
        ],
        DivergenceStateClassifier::STATE_DISPUTED => [
            'headline' => 'Under active dispute.',
            'body' =>
                "A panel review is open on a recent vote about you. The dispute "
                . "moves through the panel mechanic; the state lifts when the "
                . "panel resolves. This is procedural — it isn't a judgment.",
        ],
    ];

    /** Defensive fallback when an unknown state slug arrives. */
    private const FALLBACK = [
        'headline' => 'Read forming.',
        'body' =>
            "The floor is building a read on you. No specific state classification "
            . "yet.",
    ];

    /**
     * Resolve the headline+body for a given divergence-state slug.
     *
     * @param string $state One of `DivergenceStateClassifier::STATE_*`.
     * @return array{state: string, headline: string, body: string}
     */
    public function explain(string $state): array
    {
        $copy = self::COPY[$state] ?? self::FALLBACK;
        return [
            'state'    => $state,
            'headline' => $copy['headline'],
            'body'     => $copy['body'],
        ];
    }
}
