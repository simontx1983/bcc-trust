<?php
/**
 * Which fraud-analysis triggers are RELATIONSHIP OBSERVATIONS rather than
 * fraud signals.
 *
 * These triggers are still emitted by FraudDetector::analyzeFraud(), and
 * deliberately so — they are the durable record of a detected reciprocal
 * relationship and the only input a future review queue could read. But
 * they contribute ZERO points, so a member carrying one is not thereby
 * scored, rate-limited, weight-discounted, rank-blocked or flagged.
 *
 * ⚠ This list exists because the trigger name alone is indistinguishable
 * from an allegation. `in_vote_ring` rendered in a column headed "Triggers"
 * inside a panel headed "Fraud Analysis History" reads as a finding of vote
 * manipulation. It is not one: it is a statement that several members have
 * voted on each other's pages, which is what a trade community does. Any
 * surface that shows a trigger to an operator must consult this list.
 *
 * ⚠ The names are NOT changed here. Renaming them would break the only
 * durable record of every observation already stored, and would change the
 * key a review queue will need to match on. The names stay; the meaning is
 * declared.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class ReciprocityObservation
{
    /**
     * Triggers that record a reciprocal relationship and carry no penalty.
     *
     *  - `vote_ring`    — PAIRWISE. Two members who have each voted on
     *                     BCC_TRUST_RING_MIN_MUTUAL+ of the other's pages.
     *                     No group, no strength gate, no cache dependency.
     *  - `in_vote_ring` — group membership in a mutually-connected
     *                     component that cleared the strength threshold.
     *
     * Not included, because they are not reciprocity signals and still
     * score exactly as before: `uniform_voting`, `multi_account`,
     * `shared_device_*`, and every behavioural, device, trust-rank and
     * account-age trigger.
     *
     * @var list<string>
     */
    public const TRIGGERS = [
        'vote_ring',
        'in_vote_ring',
    ];

    /**
     * Non-reciprocity triggers that are ALSO observations carrying no
     * penalty.
     *
     *  - `uniform_voting` — >= BCC_TRUST_UNIFORM_VOTE_MIN votes in 7 days
     *    at more than 90% or less than 10% positive. It establishes that a
     *    member's ratings are CONSISTENT, which is what an honest rater
     *    with consistent experience produces. A satisfied customer and a
     *    sock puppet are indistinguishable to it, and it penalised the
     *    consumer-protection case (someone who only flags poor work).
     *
     * ⚠ NAMING DEBT, recorded rather than hidden: this class is called
     * ReciprocityObservation and now carries a trigger that is not about
     * reciprocity. The alternative was a second, parallel class with the
     * same shape, which §11 forbids and which would let two surfaces
     * describe the same trigger differently. A rename belongs in its own
     * change, not in a scoring fix.
     *
     * @var list<string>
     */
    public const CONSISTENCY_TRIGGERS = [
        'uniform_voting',
    ];

    /**
     * Short operator-facing qualifier. Rendering is the template's job;
     * this is the wording, held in one place so two surfaces cannot
     * describe the same trigger differently.
     */
    public const QUALIFIER = 'observation, no penalty';

    public static function isNonPunitive(string $trigger): bool
    {
        return in_array($trigger, self::TRIGGERS, true)
            || in_array($trigger, self::CONSISTENCY_TRIGGERS, true);
    }

    /**
     * Every trigger that carries no penalty, reciprocity or otherwise.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(self::TRIGGERS, self::CONSISTENCY_TRIGGERS);
    }

    /**
     * True when EVERY trigger in the set is a non-punitive observation —
     * i.e. nothing here is a fraud signal.
     *
     * This is the question an operator surface actually needs: a member
     * whose only triggers are observations has no fraud finding against
     * them at all, however long the list looks.
     *
     * An empty set is NOT "all observations"; it is "nothing recorded",
     * and returning true for it would let a surface describe a member with
     * no analysis as cleared.
     *
     * @param list<string> $triggers
     */
    public static function allNonPunitive(array $triggers): bool
    {
        if ($triggers === []) {
            return false;
        }

        foreach ($triggers as $trigger) {
            if (!self::isNonPunitive($trigger)) {
                return false;
            }
        }

        return true;
    }
}
