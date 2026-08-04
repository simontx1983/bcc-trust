<?php
/**
 * CalibPollMath — MIRROR of the private PollService close-time
 * arithmetic (app/Domain/Rank/Services/PollService.php).
 *
 * decideOutcome()/evaluatePoll() are private and repository-bound, so
 * the harness mirrors ONLY the arithmetic, verbatim:
 *
 *   - dual quorum (§18.1): counted voters >= quorum.voters AND counted
 *     post-cap weight + EPSILON >= quorum.weight        (PollService::decideOutcome)
 *   - majority (§18.2): winning side + EPSILON >= majority × counted
 *     total; exactly 60.00% PASSES                      (PollService::decideOutcome)
 *   - suspected cap (§19.4): C_total = min(W_susp, ratio × W_nc),
 *     pro-rata scale when over                          (PollService::evaluatePoll)
 *
 * Any drift between this file and PollService is a harness bug — the
 * production file is the source of truth.
 */

declare(strict_types=1);

use BCC\Trust\Rank\Support\RankScoringConfig;

final class CalibPollMath
{
    /** Mirrors PollService::EPSILON. */
    public const EPSILON = 1e-9;

    /**
     * §19.4 pro-rata scale applied to every counted suspected ballot.
     * Mirrors the cap block at the end of PollService::evaluatePoll().
     */
    public static function suspectedCapScale(float $suspectedWeight, float $nonClusterWeight, RankScoringConfig $config): float
    {
        $capTotal = min($suspectedWeight, $config->suspectedClusterCapRatio * $nonClusterWeight);
        if ($suspectedWeight > $capTotal && $suspectedWeight > 0.0) {
            return $capTotal / $suspectedWeight;
        }
        return 1.0;
    }

    /** §18.1 dual quorum over POST-cap counted numbers. */
    public static function meetsQuorum(int $countedVoters, float $countedWeight, RankScoringConfig $config): bool
    {
        if ($countedVoters < $config->quorumVoters) {
            return false;
        }
        return !($countedWeight + self::EPSILON < $config->quorumWeight);
    }

    /**
     * Mirrors PollService::decideOutcome() exactly.
     *
     * @return 'passed'|'failed'|null Null = quorum or majority not met.
     */
    public static function decide(int $countedVoters, float $weightFor, float $weightAgainst, RankScoringConfig $config): ?string
    {
        $countedWeight = $weightFor + $weightAgainst;

        if ($countedVoters < $config->quorumVoters) {
            return null;
        }
        if ($countedWeight + self::EPSILON < $config->quorumWeight) {
            return null;
        }
        if ($countedWeight <= 0.0) {
            return null;
        }

        $threshold = $config->majority * $countedWeight;
        if ($weightFor + self::EPSILON >= $threshold) {
            return 'passed';
        }
        if ($weightAgainst + self::EPSILON >= $threshold) {
            return 'failed';
        }
        return null;
    }

    /**
     * Self-check of the mirror against hand-computed PollService
     * semantics. Throws on drift; run.php calls this once per run.
     */
    public static function selfCheck(RankScoringConfig $config): void
    {
        // Dual quorum: 10 voters & 7.5 weight — both required.
        if (self::decide(9, 10.0, 0.0, $config) !== null) {
            throw new RuntimeException('PollMath self-check: voter quorum failed');
        }
        if (self::decide(10, 4.0, 3.0, $config) !== null) {
            throw new RuntimeException('PollMath self-check: weight quorum failed');
        }
        // Exactly 60.00% passes.
        if (self::decide(10, 6.0, 4.0, $config) !== 'passed') {
            throw new RuntimeException('PollMath self-check: exact-60% majority failed');
        }
        if (self::decide(10, 5.9, 4.1, $config) !== null) {
            throw new RuntimeException('PollMath self-check: sub-60% should not decide');
        }
        if (self::decide(10, 4.0, 6.0, $config) !== 'failed') {
            throw new RuntimeException('PollMath self-check: against-majority failed');
        }
        // Suspected cap: W_susp 10 vs W_nc 8 → cap 2.0 → scale 0.2.
        $scale = self::suspectedCapScale(10.0, 8.0, $config);
        if (abs($scale - ($config->suspectedClusterCapRatio * 8.0 / 10.0)) > 1e-12) {
            throw new RuntimeException('PollMath self-check: suspected cap scale failed');
        }
        if (self::suspectedCapScale(1.0, 8.0, $config) !== 1.0) {
            throw new RuntimeException('PollMath self-check: under-cap should not scale');
        }
    }
}
