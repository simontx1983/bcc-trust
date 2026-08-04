<?php
/**
 * CalibRequirementsMirror — MIRROR of the RankPromotionEngine::assess()
 * requirement predicate (app/Domain/Rank/Services/RankPromotionEngine.php,
 * the $satisfied[] block + the diversity/headcount pass over active rows).
 *
 * assess() itself is repository-bound (RankStateRepository, TierDaysRepository,
 * FindingsRepository, Permissions::is_not_suspended) so it cannot run
 * without WP/DB. The SCORE comes from the REAL RankScoreCalculator; only
 * the boolean requirement predicate and the tier-days window count are
 * mirrored here. Simulation baseline: no suspensions, no misconduct
 * findings (suspensionClear = true, ceiling = veteran, findingPenalty = 0).
 *
 * Mirrored terms (per rung, from the production $satisfied[] block):
 *   journeyman: total >= thresholds.journeyman (40)
 *               AND contribution/helping/recognition >= category_minimums.journeyman (7.5/7.5/4.5)
 *               AND contribution types >= diversity_minimums.journeyman_categories (3)
 *               AND independent recognizers >= recognizer_minimums.journeyman (3)
 *               AND qualifying tier days >= 45-of-60 at neutral+
 *   veteran:    total >= thresholds.veteran (80)
 *               AND contribution/helping/recognition/outcomes >= category_minimums.veteran (15/15/9/9)
 *               AND contribution types >= diversity_minimums.veteran_categories (5)
 *               AND independent recognizers >= recognizer_minimums.veteran (5)
 *               AND outcomes >= outcome_minimums.veteran_outcomes (5)
 *               AND outcome types >= outcome_minimums.veteran_outcome_types (2)
 *               AND qualifying tier days >= 120-of-180 at trusted+
 *
 * The diversity/headcount pass mirrors assess(): rows with
 * capped_value <= 0 count NOWHERE; recognizer identities collapse
 * through the confirmed-cluster map before being counted.
 *
 * Any drift between this file and RankPromotionEngine is a harness bug —
 * the production file is the source of truth.
 */

declare(strict_types=1);

use BCC\Trust\Rank\Services\RankScoreCalculator;
use BCC\Trust\Rank\Support\RankScoringConfig;

final class CalibRequirementsMirror
{
    /**
     * @param list<object> $rows          Active ledger rows (calculator shape).
     * @param array<int, int> $clusterMap Confirmed member→representative map.
     * @param array<string, int> $windowQualifying Modeled qualifying tier
     *        days per rung — the TierDaysRepository::countQualifyingDays
     *        substitute (see modeledWindows()).
     * @return array{
     *     categories: array<string, float>,
     *     decay: float,
     *     total: float,
     *     contribution_types: int,
     *     recognizers: int,
     *     outcomes: int,
     *     outcome_types: int,
     *     windows: array<string, int>,
     *     satisfied: array<string, bool>,
     *     terms: array<string, array<string, bool>>,
     *     target: string
     * }
     */
    public static function assess(
        RankScoreCalculator $calculator,
        RankScoringConfig $config,
        array $rows,
        int $distinctLoginMonths,
        ?string $lastLoginDay,
        array $clusterMap,
        string $today,
        array $windowQualifying
    ): array {
        // REAL production score math.
        $result = $calculator->calculate($rows, $distinctLoginMonths, $lastLoginDay, $clusterMap, $today, 0.0);

        // MIRROR of the assess() diversity/headcount pass.
        $contributionTypes = [];
        $recognizers       = [];
        $outcomeCount      = 0;
        $outcomeTypes      = [];
        foreach ($rows as $event) {
            if ((float) $event->capped_value <= 0.0) {
                continue; // Zero-credited (clustered) evidence counts nowhere.
            }
            switch ((string) $event->category) {
                case 'contribution':
                    $contributionTypes[(string) $event->source_type] = true;
                    break;
                case 'recognition':
                    $rel = (int) $event->relationship_user_id;
                    if ($rel > 0) {
                        $recognizers[$clusterMap[$rel] ?? $rel] = true;
                    }
                    break;
                case 'outcomes':
                    $outcomeCount++;
                    $outcomeTypes[(string) $event->source_type] = true;
                    break;
            }
        }

        // MIRROR of the $satisfied[] block. Simulation baseline: no
        // suspension, no finding ceilings, no finding penalties.
        $jTerms = [
            'total'              => $result['total'] >= $config->thresholds['journeyman'],
            'contribution_min'   => $result['categories']['contribution'] >= $config->categoryMinimums['journeyman']['contribution'],
            'helping_min'        => $result['categories']['helping'] >= $config->categoryMinimums['journeyman']['helping'],
            'recognition_min'    => $result['categories']['recognition'] >= $config->categoryMinimums['journeyman']['recognition'],
            'contribution_types' => count($contributionTypes) >= $config->diversityMinimums['journeyman_categories'],
            'recognizers'        => count($recognizers) >= $config->recognizerMinimums['journeyman'],
            'trust_window'       => ($windowQualifying['journeyman'] ?? 0) >= $config->trustWindows['journeyman']['qualifying_days'],
        ];
        $vTerms = [
            'total'              => $result['total'] >= $config->thresholds['veteran'],
            'contribution_min'   => $result['categories']['contribution'] >= $config->categoryMinimums['veteran']['contribution'],
            'helping_min'        => $result['categories']['helping'] >= $config->categoryMinimums['veteran']['helping'],
            'recognition_min'    => $result['categories']['recognition'] >= $config->categoryMinimums['veteran']['recognition'],
            'outcomes_min'       => $result['categories']['outcomes'] >= $config->categoryMinimums['veteran']['outcomes'],
            'contribution_types' => count($contributionTypes) >= $config->diversityMinimums['veteran_categories'],
            'recognizers'        => count($recognizers) >= $config->recognizerMinimums['veteran'],
            'outcome_count'      => $outcomeCount >= $config->outcomeMinimums['veteran_outcomes'],
            'outcome_types'      => count($outcomeTypes) >= $config->outcomeMinimums['veteran_outcome_types'],
            'trust_window'       => ($windowQualifying['veteran'] ?? 0) >= $config->trustWindows['veteran']['qualifying_days'],
        ];

        $satisfied = [
            'apprentice' => true,
            'journeyman' => !in_array(false, $jTerms, true),
            'veteran'    => !in_array(false, $vTerms, true),
        ];

        $target = 'apprentice';
        if ($satisfied['journeyman']) {
            $target = 'journeyman';
        }
        if ($satisfied['journeyman'] && $satisfied['veteran']) {
            $target = 'veteran';
        }

        return [
            'categories'         => $result['categories'],
            'decay'              => $result['decay'],
            'total'              => $result['total'],
            'contribution_types' => count($contributionTypes),
            'recognizers'        => count($recognizers),
            'outcomes'           => $outcomeCount,
            'outcome_types'      => count($outcomeTypes),
            'windows'            => $windowQualifying,
            'satisfied'          => $satisfied,
            'terms'              => ['journeyman' => $jTerms, 'veteran' => $vTerms],
            'target'             => $target,
        ];
    }

    /**
     * Modeled substitute for TierDaysRepository::countQualifyingDays —
     * the tier-days data plane needs a live DB, so the harness models a
     * member's tier timeline (MODELING ASSUMPTION, see README):
     *
     *   - tier-qualified members (80% of the cohort): neutral+ from day 0,
     *     trusted+ from day $trustedFromDay (default 90);
     *   - non-qualified members (20%): caution — never neutral+.
     *
     * One tier-day row per COMPLETED day; the window covers the
     * window_days most recent completed days [day − window, day − 1],
     * matching the repository's `day >= since` count over daily rows.
     *
     * @return array<string, int> rung => qualifying day count
     */
    public static function modeledWindows(
        RankScoringConfig $config,
        int $day,
        bool $tierQualified,
        int $trustedFromDay
    ): array {
        $out = [];
        foreach ($config->trustWindows as $rank => $window) {
            $minOrd = $config->tierOrdFor($window['min_tier']);
            $count  = 0;
            for ($d = max(0, $day - $window['window_days']); $d < $day; $d++) {
                $ord = self::modeledTierOrd($config, $d, $tierQualified, $trustedFromDay);
                if ($ord >= $minOrd) {
                    $count++;
                }
            }
            $out[$rank] = $count;
        }
        return $out;
    }

    /** The modeled tier timeline (see modeledWindows docblock). */
    public static function modeledTierOrd(
        RankScoringConfig $config,
        int $day,
        bool $tierQualified,
        int $trustedFromDay
    ): int {
        if (!$tierQualified) {
            return $config->tierOrdFor('caution');
        }
        return $day >= $trustedFromDay
            ? $config->tierOrdFor('trusted')
            : $config->tierOrdFor('neutral');
    }
}
