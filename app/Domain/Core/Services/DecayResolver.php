<?php
/**
 * DecayResolver — read-time piecewise-linear decay of `weight_at_time`.
 *
 * Per Phase 1 plan §6.2 + §12 q1: applies a time-based decay factor to
 * an attestation's stored weight. The factor is the headline synthesis
 * primitive of Slice E — every reputation read, every roster ORDER BY,
 * every future reliability computation flows through this curve.
 *
 * **Curve (locked per §12 q1; tunable via the ANCHOR_* constants):**
 *
 *   age (days) | factor
 *   -----------|--------
 *   0          | 1.00
 *   90         | 0.90    ← end of "fresh" window
 *   365        | 0.70    ← 1-year mark
 *   1095       | 0.40    ← 3-year mark (the explicit design anchor)
 *   1825+      | 0.20    ← 5-year asymptote (floor; never drops below)
 *
 * Five segments linearly interpolate between the anchors. The 1825-day
 * floor approximates the §12 "asymptote=0.20" intent — exposing a true
 * mathematical asymptote inside a SQL CASE would compound floating-
 * point precision risk for no observable difference vs. a flat tail.
 *
 * **Baseline (per §6.1 — reaffirm resets decay):**
 *
 * The decay age = NOW() − COALESCE(reaffirmed_at, created_at). When an
 * operator reaffirms an attestation, the baseline resets to that moment,
 * so a 2-year-old vouch that was reaffirmed yesterday decays from
 * yesterday, not the original cast. PHP callers pass the baseline
 * DateTime explicitly; SQL callers use the COALESCE expression in
 * `decayedWeightSqlExpression()`.
 *
 * **Why piecewise linear vs exponential or smooth curve:**
 *
 * - Read-time performance: a CASE WHEN piecewise expression evaluates
 *   inside MySQL's expression engine in O(constant) per row — no
 *   POW/LOG/EXP function calls. Slice E populates synthesis scores
 *   on potentially every cards-list and roster read; the cost matters.
 * - Tunability without redeployment: anchor values can be re-tuned in
 *   closed-network testing by editing the constants — no curve-shape
 *   regression because the segments stay independent.
 * - Legibility: an engineer debugging "why is this attestation
 *   weighted 0.65?" can read the curve as a table, not solve for `t`
 *   in a continuous function.
 *
 * **Where this function gets called (PR-5 only):**
 *
 *   - `AttestationRepository::listByTarget` via the SQL expression
 *     — drives the §J.4 roster ORDER BY when sort=decayed_weight
 *
 * Phase 1.6+ will add the synthesis layer consumers: reputation score
 * compositor, operator-reliability computer, divergence-state
 * classifier. Each calls `decayedWeight()` directly with PHP DateTimes
 * sourced from the cached/queried attestation rows.
 *
 * **Sibling SQL idiom:** the SQL CASE structure mirrors VoteRepository's
 * `applyTimeDecay` block at lines 828–833 (TIMESTAMPDIFF/86400.0 age,
 * CASE WHEN factor → multiply). Cross-call-site grep `TIMESTAMPDIFF.*86400`
 * surfaces both decay sites — by design.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer PR-5 (2026-05-13)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use DateTimeInterface;

if (!defined('ABSPATH')) {
    exit;
}

final class DecayResolver
{
    /** Anchor 0: at-cast / just-reaffirmed. */
    public const ANCHOR_0_DAYS   = 0;
    public const ANCHOR_0_FACTOR = 1.00;

    /** Anchor 1: end of "fresh" window — 3 months. */
    public const ANCHOR_1_DAYS   = 90;
    public const ANCHOR_1_FACTOR = 0.90;

    /** Anchor 2: 1-year mark. */
    public const ANCHOR_2_DAYS   = 365;
    public const ANCHOR_2_FACTOR = 0.70;

    /** Anchor 3: 3-year mark — the explicit design anchor per §12 q1. */
    public const ANCHOR_3_DAYS   = 1095;
    public const ANCHOR_3_FACTOR = 0.40;

    /** Anchor 4: 5-year floor (asymptote approximation per §12 q1). */
    public const ANCHOR_4_DAYS   = 1825;
    public const ANCHOR_4_FACTOR = 0.20;

    /**
     * Apply the decay curve to a stored `weight_at_time` value.
     *
     * @param float             $weight    The cast-time weight from
     *                                     `bcc_trust_attestations.weight_at_time`.
     * @param DateTimeInterface $baseline  The decay-baseline timestamp.
     *                                     Use `COALESCE(reaffirmed_at, created_at)`
     *                                     on the SQL side; in PHP, pass the
     *                                     equivalent (reaffirmed_at if set,
     *                                     created_at otherwise).
     * @param DateTimeInterface $now       Current time. Tests pass a fixed
     *                                     clock; production passes `new
     *                                     DateTimeImmutable('now', UTC)`.
     */
    public function decayedWeight(
        float $weight,
        DateTimeInterface $baseline,
        DateTimeInterface $now
    ): float {
        $ageSeconds = $now->getTimestamp() - $baseline->getTimestamp();
        if ($ageSeconds <= 0) {
            return $weight * self::ANCHOR_0_FACTOR;
        }
        $ageDays = (int) floor($ageSeconds / 86400);
        return $weight * self::factorForAgeDays($ageDays);
    }

    /**
     * The piecewise factor for a given age in days. Public + static so
     * synthesis paths that already have an integer day count (e.g. a
     * batch worker iterating rows) can call without constructing a
     * service instance.
     */
    public static function factorForAgeDays(int $ageDays): float
    {
        if ($ageDays <= self::ANCHOR_0_DAYS) {
            return self::ANCHOR_0_FACTOR;
        }
        if ($ageDays <= self::ANCHOR_1_DAYS) {
            return self::interpolate(
                $ageDays,
                self::ANCHOR_0_DAYS, self::ANCHOR_1_DAYS,
                self::ANCHOR_0_FACTOR, self::ANCHOR_1_FACTOR
            );
        }
        if ($ageDays <= self::ANCHOR_2_DAYS) {
            return self::interpolate(
                $ageDays,
                self::ANCHOR_1_DAYS, self::ANCHOR_2_DAYS,
                self::ANCHOR_1_FACTOR, self::ANCHOR_2_FACTOR
            );
        }
        if ($ageDays <= self::ANCHOR_3_DAYS) {
            return self::interpolate(
                $ageDays,
                self::ANCHOR_2_DAYS, self::ANCHOR_3_DAYS,
                self::ANCHOR_2_FACTOR, self::ANCHOR_3_FACTOR
            );
        }
        if ($ageDays <= self::ANCHOR_4_DAYS) {
            return self::interpolate(
                $ageDays,
                self::ANCHOR_3_DAYS, self::ANCHOR_4_DAYS,
                self::ANCHOR_3_FACTOR, self::ANCHOR_4_FACTOR
            );
        }
        return self::ANCHOR_4_FACTOR;
    }

    /**
     * Linear interpolation between two anchors. Pure; no side effects.
     */
    private static function interpolate(
        int $age,
        int $aStart,
        int $aEnd,
        float $fStart,
        float $fEnd
    ): float {
        $range = $aEnd - $aStart;
        if ($range <= 0) {
            return $fStart;
        }
        $progress = ($age - $aStart) / $range;
        return $fStart + ($fEnd - $fStart) * $progress;
    }

    /**
     * SQL expression equivalent to `weight_at_time * factorForAgeDays(age)`,
     * suitable for inline ORDER BY use. Returns a SAFE-to-interpolate
     * string — every value is a class constant (never user input).
     *
     * Mirrors VoteRepository's `applyTimeDecay` SQL idiom for cross-
     * call-site consistency. Caller is responsible for ensuring the
     * surrounding table-alias context exposes `weight_at_time`,
     * `reaffirmed_at`, and `created_at` columns by those names. The
     * existing `bcc_trust_attestations` schema does — direct table
     * scans require no aliasing.
     *
     * Returned shape (multi-line for legibility — collapse if needed):
     *
     *   weight_at_time * CASE
     *     WHEN <age> <= 0    THEN 1.00
     *     WHEN <age> <= 90   THEN 1.00 - ((<age> - 0) / 90) * 0.10
     *     WHEN <age> <= 365  THEN 0.90 - ((<age> - 90) / 275) * 0.20
     *     WHEN <age> <= 1095 THEN 0.70 - ((<age> - 365) / 730) * 0.30
     *     WHEN <age> <= 1825 THEN 0.40 - ((<age> - 1095) / 730) * 0.20
     *     ELSE 0.20
     *   END
     */
    public static function decayedWeightSqlExpression(): string
    {
        // Decay baseline: reaffirm wins over original cast (plan §6.1).
        // Mirrors VoteRepository.php:830 — TIMESTAMPDIFF(SECOND,…) / 86400.0
        // — so `git grep TIMESTAMPDIFF.*86400` surfaces both decay sites.
        $age = 'TIMESTAMPDIFF(SECOND, COALESCE(reaffirmed_at, created_at), UTC_TIMESTAMP()) / 86400.0';

        $a0 = self::ANCHOR_0_DAYS;   $f0 = self::ANCHOR_0_FACTOR;
        $a1 = self::ANCHOR_1_DAYS;   $f1 = self::ANCHOR_1_FACTOR;
        $a2 = self::ANCHOR_2_DAYS;   $f2 = self::ANCHOR_2_FACTOR;
        $a3 = self::ANCHOR_3_DAYS;   $f3 = self::ANCHOR_3_FACTOR;
        $a4 = self::ANCHOR_4_DAYS;   $f4 = self::ANCHOR_4_FACTOR;

        // Segment ranges + factor deltas. Cast to float so MySQL does
        // float division rather than truncating int division.
        $r1 = (float) ($a1 - $a0);   $d1 = $f0 - $f1;
        $r2 = (float) ($a2 - $a1);   $d2 = $f1 - $f2;
        $r3 = (float) ($a3 - $a2);   $d3 = $f2 - $f3;
        $r4 = (float) ($a4 - $a3);   $d4 = $f3 - $f4;

        return "weight_at_time * CASE
      WHEN {$age} <= {$a0} THEN {$f0}
      WHEN {$age} <= {$a1} THEN {$f0} - (({$age} - {$a0}) / {$r1}) * {$d1}
      WHEN {$age} <= {$a2} THEN {$f1} - (({$age} - {$a1}) / {$r2}) * {$d2}
      WHEN {$age} <= {$a3} THEN {$f2} - (({$age} - {$a2}) / {$r3}) * {$d3}
      WHEN {$age} <= {$a4} THEN {$f3} - (({$age} - {$a3}) / {$r4}) * {$d4}
      ELSE {$f4}
    END";
    }
}
