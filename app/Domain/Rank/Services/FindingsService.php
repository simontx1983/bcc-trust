<?php
/**
 * FindingsService — issuance + reconsideration of formal misconduct
 * findings (Rank redesign Phase 8; spec §15).
 *
 * §15.1 — NO automated issuance paths exist. Raw reports, downvotes,
 * and accusations never touch findings; the only writer is the
 * wp-admin Rank Findings surface calling issue(). The 'fraud_detection'
 * source value exists in the enum but has NO writer yet.
 *
 * §18.4 — no automation from dispute outcomes: a dispute-sourced
 * finding is an ADMIN reading the closed dispute and issuing manually
 * with source='dispute' and the dispute id recorded in evidence_refs.
 *
 * §15.3 — penalty / ceiling / expiry values are SNAPSHOT from the
 * config class at issuance; later config edits never rewrite history.
 *
 * §15.5 — every reconsideration (uphold / reduce / reverse / remand)
 * appends a decision row; nothing ever edits a prior decision. A
 * reduction updates the finding's CURRENT penalty column, but the
 * original snapshot survives in the 'issued' decision row. The same
 * administrator who issued may reconsider.
 *
 * Reversal restores deterministically via the promotion engine
 * re-evaluate — penalties are calculation-time inputs, so dropping the
 * finding from the active set recomputes the score exactly (the same
 * primitive as the `bcc_content_moderation_undone` evidence reverse).
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign Phase 8 (2026-08-04)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

use BCC\Trust\Rank\Repositories\FindingDecisionsRepository;
use BCC\Trust\Rank\Repositories\FindingsRepository;
use BCC\Trust\Rank\Support\RankScoringConfig;

if (!defined('ABSPATH')) {
    exit;
}

class FindingsService
{
    /** Issuance provenance values (§15.2). 'fraud_detection' has no writer yet. */
    public const SOURCES = ['admin', 'dispute', 'fraud_detection'];

    /** Reconsideration decisions (§15.5). 'issued' is reserved for issuance. */
    public const RECONSIDER_DECISIONS = ['upheld', 'reduced', 'reversed', 'remanded'];

    public function __construct(
        private readonly FindingsRepository $findings,
        private readonly FindingDecisionsRepository $decisions,
        private readonly RankPromotionEngine $engine,
        private readonly RankScoringConfig $config
    ) {
    }

    /**
     * Issue one finding: snapshot the §15.3 class values, write the
     * finding + its 'issued' decision row, audit, fire
     * `bcc_rank_finding_issued`, then re-evaluate the subject —
     * immediately (no grace) for classes carrying immediate_demotion.
     *
     * @param string      $severityClass minor|moderate|serious|severe
     * @param string      $source        admin|dispute (fraud_detection reserved — §15.1)
     * @return int Finding id, or 0 on validation/write failure.
     */
    public function issue(
        int $subjectUserId,
        string $findingType,
        string $severityClass,
        ?string $evidenceRefs,
        string $source,
        int $issuerId,
        string $reason,
        ?string $restrictionNote = null
    ): int {
        $findingType = trim($findingType);
        $reason      = trim($reason);
        if ($subjectUserId <= 0 || $issuerId <= 0 || $findingType === '' || $reason === '') {
            return 0;
        }
        if (!isset($this->config->findingClasses[$severityClass])) {
            return 0;
        }
        if (!in_array($source, self::SOURCES, true)) {
            return 0;
        }

        $class = $this->config->findingClasses[$severityClass];
        $now   = gmdate('Y-m-d H:i:s');

        $ceilingRank      = $class['ceiling_rank'];
        $ceilingExpiresAt = null;
        if ($ceilingRank !== null && $class['ceiling_days'] !== null) {
            $ceilingExpiresAt = gmdate('Y-m-d H:i:s', time() + ($class['ceiling_days'] * 86400));
        }

        $findingId = $this->findings->create([
            'subject_user_id'    => $subjectUserId,
            'finding_type'       => substr($findingType, 0, 40),
            'severity'           => $severityClass,
            'evidence_refs'      => $evidenceRefs !== null && trim($evidenceRefs) !== '' ? trim($evidenceRefs) : null,
            'source'             => $source,
            'issued_by'          => $issuerId,
            'created_at'         => $now,
            'effective_at'       => $now,
            'score_penalty'      => $class['score_penalty'],
            'ceiling_rank_slug'  => $ceilingRank,
            'ceiling_expires_at' => $ceilingExpiresAt,
            'penalty_expires_at' => gmdate('Y-m-d H:i:s', time() + ($class['penalty_expiry_days'] * 86400)),
            'restriction_note'   => $restrictionNote !== null && trim($restrictionNote) !== '' ? trim($restrictionNote) : null,
        ]);
        if ($findingId <= 0) {
            return 0;
        }

        // §15.5 — the 'issued' decision row carries the original
        // penalty snapshot forever, whatever later reductions do.
        $this->decisions->append($findingId, 'issued', $issuerId, $reason, $class['score_penalty']);

        \BCC\Core\Log\Logger::audit('rank_finding_issued', [
            'finding_id' => $findingId,
            'subject_id' => $subjectUserId,
            'severity'   => $severityClass,
            'type'       => $findingType,
            'source'     => $source,
            'penalty'    => $class['score_penalty'],
            'issuer_id'  => $issuerId,
        ]);
        do_action('bcc_rank_finding_issued', $subjectUserId, $findingId, $severityClass);

        if ($class['immediate_demotion']) {
            // §15.3 class 4 — demote NOW, no grace.
            $this->engine->evaluateImmediate($subjectUserId);
        } else {
            // Lesser classes flow through ordinary evaluate semantics
            // (a ceiling/penalty loss starts the §14.1 grace).
            $this->engine->evaluate($subjectUserId);
        }

        return $findingId;
    }

    /**
     * Reconsider one finding (§15.5). Always appends a decision row and
     * fires `bcc_rank_finding_reconsidered`; 'reduced'/'reversed'
     * additionally rewrite the finding's current state and re-evaluate
     * the subject (deterministic restoration — penalties are
     * calculation-time inputs).
     *
     * @param string     $decision   upheld|reduced|reversed|remanded
     * @param float|null $newPenalty Required for 'reduced'; must be
     *                               strictly lower than the current
     *                               penalty and above zero (a reduction
     *                               to zero is a reversal — use 'reversed').
     */
    public function reconsider(
        int $findingId,
        int $adminId,
        string $decision,
        string $reason,
        ?float $newPenalty = null
    ): bool {
        $reason = trim($reason);
        if ($findingId <= 0 || $adminId <= 0 || $reason === '') {
            return false;
        }
        if (!in_array($decision, self::RECONSIDER_DECISIONS, true)) {
            return false;
        }

        $finding = $this->findings->getById($findingId);
        if ($finding === null) {
            return false;
        }
        $subjectId      = (int) $finding->subject_user_id;
        $currentPenalty = (float) $finding->score_penalty;

        $penaltyAfter = null;
        $reverse      = false;

        if ($decision === 'reduced') {
            if ((string) $finding->status !== 'active') {
                return false; // nothing left to reduce on a reversed finding
            }
            if ($newPenalty === null || $newPenalty <= 0.0 || $newPenalty >= $currentPenalty) {
                return false; // a reduction must actually lower the penalty
            }
            $penaltyAfter = round($newPenalty, 2);
        } elseif ($decision === 'reversed') {
            if ((string) $finding->status !== 'active') {
                return false; // already reversed — append-only history stays clean
            }
            $reverse = true;
        }

        $applied = $this->findings->applyReconsideration(
            $findingId,
            $subjectId,
            $decision,
            $penaltyAfter,
            $reverse,
            $reverse ? $reason : null
        );
        if (!$applied) {
            return false;
        }

        // §15.5 append-only decision row — never edits a prior one.
        $this->decisions->append($findingId, $decision, $adminId, $reason, $penaltyAfter);

        \BCC\Core\Log\Logger::audit('rank_finding_reconsidered', [
            'finding_id' => $findingId,
            'subject_id' => $subjectId,
            'decision'   => $decision,
            'admin_id'   => $adminId,
            'penalty'    => $penaltyAfter,
        ]);
        do_action('bcc_rank_finding_reconsidered', $subjectId, $findingId, $decision);

        if ($decision === 'reduced' || $decision === 'reversed') {
            // Deterministic restoration: the penalty/ceiling change is a
            // calc-time input, so a plain re-evaluate recomputes exactly.
            $this->engine->evaluate($subjectId);
        }

        return true;
    }
}
