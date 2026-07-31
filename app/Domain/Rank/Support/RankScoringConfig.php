<?php
/**
 * RankScoringConfig — immutable, validated view of includes/config/rank-scoring.php.
 *
 * The ONLY way Rank code reads scoring configuration (approved plan A4):
 * the config file returns a pure array; this factory validates it and
 * services receive the resulting object by constructor injection. No
 * define(), no apply_filters(), no global reads inside Rank code.
 *
 * fromArray() REJECTS (InvalidArgumentException): missing keys, unknown
 * top-level keys, wrong types, negative values, impossible percentages,
 * and invariant-breaking combinations — including every configuration
 * invariant from the approved plan's A4 list (category maxima total 100,
 * ordered thresholds, bounded monotonic maturity, ordered trust anchors,
 * the 1.75 ceiling, valid quorum/majority, caps within (0,1], ordered
 * binding windows, ordered community caps, and Master absent everywhere).
 *
 * CI constructs this object from the REAL shipped file and runs the
 * invariant suite against it (tests/Unit/RankScoringConfigTest.php), so
 * a config edit is exercised by the same tests that guard the product
 * invariants.
 *
 * @package BCC\Trust\Rank\Support
 * @since Rank redesign Phase 1 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class RankScoringConfig
{
    private const REQUIRED_KEYS = [
        'category_max',
        'thresholds',
        'category_minimums',
        'event_cap',
        'share_caps',
        'maturity',
        'rank_multipliers',
        'trust_multiplier',
        'vote_ceiling',
        'weight_to_score_scale',
        'quorum',
        'majority',
        'binding_window',
        'suspected_cluster_cap_ratio',
        'time_credit',
        'decay',
        'retention',
        'tier_ord',
        'trust_windows',
        'recognizer_minimums',
        'outcome_minimums',
        'diversity_minimums',
        'community',
        'points',
        'sources',
    ];

    /** The only rungs that may ever exist. §3.2: Master must never appear. */
    private const RANKS = ['apprentice', 'journeyman', 'veteran'];

    /** The five §2 tiers, in qualification order. */
    private const TIERS = ['risky', 'caution', 'neutral', 'trusted', 'elite'];

    /** The five §4.1 categories. */
    private const CATEGORIES = ['contribution', 'helping', 'recognition', 'outcomes', 'time'];

    /** @var array<string, float> */
    public readonly array $categoryMax;
    /** @var array<string, float> */
    public readonly array $thresholds;
    /** @var array<string, array<string, float>> */
    public readonly array $categoryMinimums;
    public readonly float $eventCap;
    /** @var array<string, float> */
    public readonly array $shareCaps;
    public readonly float $maturityFloor;
    public readonly int $maturitySpanDays;
    /** @var array<string, float> */
    public readonly array $rankMultipliers;
    public readonly float $trustMinScore;
    public readonly float $trustMinValue;
    public readonly float $trustMaxScore;
    public readonly float $trustMaxValue;
    public readonly float $voteCeiling;
    public readonly float $weightToScoreScale;
    public readonly int $quorumVoters;
    public readonly float $quorumWeight;
    public readonly float $majority;
    public readonly int $bindingMinDays;
    public readonly int $bindingMaxDays;
    public readonly float $suspectedClusterCapRatio;
    public readonly float $timeCreditPerMonth;
    public readonly float $timeCreditCap;
    public readonly int $decayGraceDays;
    public readonly int $decayStepDays;
    public readonly float $decayPointsPerStep;
    public readonly int $tierDaysRetentionDays;
    /** @var array<string, int> */
    public readonly array $tierOrd;
    /** @var array<string, array{qualifying_days: int, window_days: int, min_tier: string}> */
    public readonly array $trustWindows;
    /** @var array<string, int> */
    public readonly array $recognizerMinimums;
    /** @var array<string, int> */
    public readonly array $outcomeMinimums;
    /** @var array<string, int> */
    public readonly array $diversityMinimums;
    /** @var array<string, int> */
    public readonly array $communityCaps;
    public readonly int $communityCooldownDays;
    /** @var array<string, float> */
    public readonly array $points;
    /** @var array<string, list<string>> */
    public readonly array $sources;

    /**
     * @param array<string, float> $categoryMax
     * @param array<string, float> $thresholds
     * @param array<string, array<string, float>> $categoryMinimums
     * @param array<string, float> $shareCaps
     * @param array<string, float> $rankMultipliers
     * @param array<string, int> $tierOrd
     * @param array<string, array{qualifying_days: int, window_days: int, min_tier: string}> $trustWindows
     * @param array<string, int> $recognizerMinimums
     * @param array<string, int> $outcomeMinimums
     * @param array<string, int> $diversityMinimums
     * @param array<string, int> $communityCaps
     * @param array<string, float> $points
     * @param array<string, list<string>> $sources
     */
    private function __construct(
        array $categoryMax,
        array $thresholds,
        array $categoryMinimums,
        float $eventCap,
        array $shareCaps,
        float $maturityFloor,
        int $maturitySpanDays,
        array $rankMultipliers,
        float $trustMinScore,
        float $trustMinValue,
        float $trustMaxScore,
        float $trustMaxValue,
        float $voteCeiling,
        float $weightToScoreScale,
        int $quorumVoters,
        float $quorumWeight,
        float $majority,
        int $bindingMinDays,
        int $bindingMaxDays,
        float $suspectedClusterCapRatio,
        float $timeCreditPerMonth,
        float $timeCreditCap,
        int $decayGraceDays,
        int $decayStepDays,
        float $decayPointsPerStep,
        int $tierDaysRetentionDays,
        array $tierOrd,
        array $trustWindows,
        array $recognizerMinimums,
        array $outcomeMinimums,
        array $diversityMinimums,
        array $communityCaps,
        int $communityCooldownDays,
        array $points,
        array $sources
    ) {
        $this->categoryMax              = $categoryMax;
        $this->thresholds               = $thresholds;
        $this->categoryMinimums         = $categoryMinimums;
        $this->eventCap                 = $eventCap;
        $this->shareCaps                = $shareCaps;
        $this->maturityFloor            = $maturityFloor;
        $this->maturitySpanDays         = $maturitySpanDays;
        $this->rankMultipliers          = $rankMultipliers;
        $this->trustMinScore            = $trustMinScore;
        $this->trustMinValue            = $trustMinValue;
        $this->trustMaxScore            = $trustMaxScore;
        $this->trustMaxValue            = $trustMaxValue;
        $this->voteCeiling              = $voteCeiling;
        $this->weightToScoreScale       = $weightToScoreScale;
        $this->quorumVoters             = $quorumVoters;
        $this->quorumWeight             = $quorumWeight;
        $this->majority                 = $majority;
        $this->bindingMinDays           = $bindingMinDays;
        $this->bindingMaxDays           = $bindingMaxDays;
        $this->suspectedClusterCapRatio = $suspectedClusterCapRatio;
        $this->timeCreditPerMonth       = $timeCreditPerMonth;
        $this->timeCreditCap            = $timeCreditCap;
        $this->decayGraceDays           = $decayGraceDays;
        $this->decayStepDays            = $decayStepDays;
        $this->decayPointsPerStep       = $decayPointsPerStep;
        $this->tierDaysRetentionDays    = $tierDaysRetentionDays;
        $this->tierOrd                  = $tierOrd;
        $this->trustWindows             = $trustWindows;
        $this->recognizerMinimums       = $recognizerMinimums;
        $this->outcomeMinimums          = $outcomeMinimums;
        $this->diversityMinimums        = $diversityMinimums;
        $this->communityCaps            = $communityCaps;
        $this->communityCooldownDays    = $communityCooldownDays;
        $this->points                   = $points;
        $this->sources                  = $sources;
    }

    /**
     * Build from the includes/config/rank-scoring.php array, validating
     * every key, type, range, and cross-key invariant. Throws
     * InvalidArgumentException naming the offending key on any violation.
     *
     * @param array<mixed> $config
     */
    public static function fromArray(array $config): self
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $config)) {
                throw new \InvalidArgumentException("rank-scoring config: missing key '{$key}'");
            }
        }
        foreach (array_keys($config) as $key) {
            if (!in_array((string) $key, self::REQUIRED_KEYS, true)) {
                throw new \InvalidArgumentException("rank-scoring config: unknown key '{$key}'");
            }
        }

        $categoryMax = self::floatMap($config['category_max'], 'category_max', self::CATEGORIES);
        // Invariant 1: category maxima total exactly 100.
        if (abs(array_sum($categoryMax) - 100.0) > 0.0001) {
            throw new \InvalidArgumentException('rank-scoring config: category_max must total exactly 100');
        }

        $thresholds = self::floatMap($config['thresholds'], 'thresholds', ['journeyman', 'veteran']);
        // Invariant 2: thresholds strictly ordered, within 0–100.
        if ($thresholds['journeyman'] <= 0.0
            || $thresholds['journeyman'] >= $thresholds['veteran']
            || $thresholds['veteran'] > 100.0
        ) {
            throw new \InvalidArgumentException('rank-scoring config: thresholds must satisfy 0 < journeyman < veteran <= 100');
        }

        $categoryMinimums = [];
        foreach (['journeyman', 'veteran'] as $rank) {
            $raw = self::mapEntry($config['category_minimums'], $rank, 'category_minimums');
            $categoryMinimums[$rank] = self::floatMap($raw, "category_minimums.{$rank}", self::CATEGORIES);
            foreach ($categoryMinimums[$rank] as $category => $minimum) {
                if ($minimum > $categoryMax[$category]) {
                    throw new \InvalidArgumentException(
                        "rank-scoring config: category_minimums.{$rank}.{$category} exceeds its category maximum"
                    );
                }
            }
        }

        $eventCap = self::positiveFloat($config['event_cap'], 'event_cap');

        $shareCaps = self::floatMap($config['share_caps'], 'share_caps', ['contribution_type', 'helping_recipient', 'recognizer']);
        // Invariant 7: every cap within (0, 1].
        foreach ($shareCaps as $name => $cap) {
            if ($cap <= 0.0 || $cap > 1.0) {
                throw new \InvalidArgumentException("rank-scoring config: share_caps.{$name} must be within (0, 1]");
            }
        }

        $maturity      = self::mapShape($config['maturity'], 'maturity', ['floor', 'span_days']);
        $maturityFloor = self::positiveFloat($maturity['floor'], 'maturity.floor');
        $maturitySpan  = self::positiveInt($maturity['span_days'], 'maturity.span_days');
        // Invariant 3: floor in (0, 1), span > 0 — linear vesting is then
        // monotonic non-decreasing to 1.0 by construction.
        if ($maturityFloor >= 1.0) {
            throw new \InvalidArgumentException('rank-scoring config: maturity.floor must be within (0, 1)');
        }

        $rankMultipliers = self::floatMap($config['rank_multipliers'], 'rank_multipliers', self::RANKS);
        if ($rankMultipliers['apprentice'] > $rankMultipliers['journeyman']
            || $rankMultipliers['journeyman'] > $rankMultipliers['veteran']
        ) {
            throw new \InvalidArgumentException('rank-scoring config: rank_multipliers must be non-decreasing up the ladder');
        }

        $trust         = self::mapShape($config['trust_multiplier'], 'trust_multiplier', ['min_score', 'min_value', 'max_score', 'max_value']);
        $trustMinScore = self::positiveFloat($trust['min_score'], 'trust_multiplier.min_score');
        $trustMinValue = self::positiveFloat($trust['min_value'], 'trust_multiplier.min_value');
        $trustMaxScore = self::positiveFloat($trust['max_score'], 'trust_multiplier.max_score');
        $trustMaxValue = self::positiveFloat($trust['max_value'], 'trust_multiplier.max_value');
        // Invariant 4: anchors ordered.
        if ($trustMinScore >= $trustMaxScore || $trustMinValue >= $trustMaxValue) {
            throw new \InvalidArgumentException('rank-scoring config: trust_multiplier anchors must be ordered (min < max)');
        }

        $voteCeiling = self::positiveFloat($config['vote_ceiling'], 'vote_ceiling');
        // Invariant 5: max(maturity × rank × trust) ≤ ceiling. Maturity
        // maxes at 1.0 by construction.
        $maxOrdinary = max($rankMultipliers) * $trustMaxValue;
        if ($maxOrdinary - $voteCeiling > 0.0001) {
            throw new \InvalidArgumentException(
                'rank-scoring config: max rank_multiplier x max trust_multiplier exceeds vote_ceiling'
            );
        }

        $weightToScoreScale = self::positiveFloat($config['weight_to_score_scale'], 'weight_to_score_scale');

        $quorum       = self::mapShape($config['quorum'], 'quorum', ['voters', 'weight']);
        $quorumVoters = self::positiveInt($quorum['voters'], 'quorum.voters');
        $quorumWeight = self::positiveFloat($quorum['weight'], 'quorum.weight');

        $majority = self::positiveFloat($config['majority'], 'majority');
        // Invariant 6: majority in (0.5, 1.0].
        if ($majority <= 0.5 || $majority > 1.0) {
            throw new \InvalidArgumentException('rank-scoring config: majority must be within (0.5, 1.0]');
        }

        $window         = self::mapShape($config['binding_window'], 'binding_window', ['min_days', 'max_days']);
        $bindingMinDays = self::positiveInt($window['min_days'], 'binding_window.min_days');
        $bindingMaxDays = self::positiveInt($window['max_days'], 'binding_window.max_days');
        // Invariant 8: day-7 window < day-90 window.
        if ($bindingMinDays >= $bindingMaxDays) {
            throw new \InvalidArgumentException('rank-scoring config: binding_window.min_days must be < max_days');
        }

        $clusterRatio = self::positiveFloat($config['suspected_cluster_cap_ratio'], 'suspected_cluster_cap_ratio');
        if ($clusterRatio > 1.0) {
            throw new \InvalidArgumentException('rank-scoring config: suspected_cluster_cap_ratio must be within (0, 1]');
        }

        $timeCredit         = self::mapShape($config['time_credit'], 'time_credit', ['points_per_month', 'cap']);
        $timeCreditPerMonth = self::positiveFloat($timeCredit['points_per_month'], 'time_credit.points_per_month');
        $timeCreditCap      = self::positiveFloat($timeCredit['cap'], 'time_credit.cap');
        if ($timeCreditCap - $categoryMax['time'] > 0.0001) {
            throw new \InvalidArgumentException('rank-scoring config: time_credit.cap exceeds the time category maximum');
        }

        $decay              = self::mapShape($config['decay'], 'decay', ['grace_days', 'step_days', 'points_per_step']);
        $decayGraceDays     = self::positiveInt($decay['grace_days'], 'decay.grace_days');
        $decayStepDays      = self::positiveInt($decay['step_days'], 'decay.step_days');
        $decayPointsPerStep = self::positiveFloat($decay['points_per_step'], 'decay.points_per_step');

        $retention             = self::mapShape($config['retention'], 'retention', ['tier_days_days']);
        $tierDaysRetentionDays = self::positiveInt($retention['tier_days_days'], 'retention.tier_days_days');

        $tierOrd = self::intMap($config['tier_ord'], 'tier_ord', self::TIERS);
        foreach (self::TIERS as $i => $tier) {
            if ($tierOrd[$tier] !== $i) {
                throw new \InvalidArgumentException('rank-scoring config: tier_ord must encode the five tiers in qualification order 0..4');
            }
        }

        $trustWindows = [];
        foreach (['journeyman', 'veteran'] as $rank) {
            $raw   = self::mapShape(
                self::mapEntry($config['trust_windows'], $rank, 'trust_windows'),
                "trust_windows.{$rank}",
                ['qualifying_days', 'window_days', 'min_tier']
            );
            $entry = [
                'qualifying_days' => self::positiveInt($raw['qualifying_days'], "trust_windows.{$rank}.qualifying_days"),
                'window_days'     => self::positiveInt($raw['window_days'], "trust_windows.{$rank}.window_days"),
                'min_tier'        => is_string($raw['min_tier']) ? $raw['min_tier'] : '',
            ];
            if ($entry['qualifying_days'] > $entry['window_days']) {
                throw new \InvalidArgumentException("rank-scoring config: trust_windows.{$rank} qualifying_days exceeds window_days");
            }
            if (!in_array($entry['min_tier'], self::TIERS, true)) {
                throw new \InvalidArgumentException("rank-scoring config: trust_windows.{$rank}.min_tier is not a known tier");
            }
            $trustWindows[$rank] = $entry;
        }

        $recognizerMinimums = self::intMap($config['recognizer_minimums'], 'recognizer_minimums', ['journeyman', 'veteran']);
        $outcomeMinimums    = self::intMap($config['outcome_minimums'], 'outcome_minimums', ['veteran_outcomes', 'veteran_outcome_types']);
        $diversityMinimums  = self::intMap($config['diversity_minimums'], 'diversity_minimums', ['journeyman_categories', 'veteran_categories']);

        $community             = self::mapShape($config['community'], 'community', ['caps', 'cooldown_days']);
        $communityCaps         = self::intMap($community['caps'], 'community.caps', self::RANKS);
        $communityCooldownDays = self::positiveInt($community['cooldown_days'], 'community.cooldown_days');
        // Invariant 9: community caps strictly ordered up the ladder.
        if ($communityCaps['apprentice'] >= $communityCaps['journeyman']
            || $communityCaps['journeyman'] >= $communityCaps['veteran']
        ) {
            throw new \InvalidArgumentException('rank-scoring config: community.caps must be strictly increasing up the ladder');
        }

        if (!is_array($config['points'])) {
            throw new \InvalidArgumentException('rank-scoring config: points must be a map');
        }
        $points = [];
        foreach ($config['points'] as $action => $value) {
            $points[(string) $action] = self::positiveFloat($value, "points.{$action}");
        }

        if (!is_array($config['sources'])) {
            throw new \InvalidArgumentException('rank-scoring config: sources must be a map');
        }
        $sources = [];
        foreach ($config['sources'] as $source => $categories) {
            if (!is_array($categories) || $categories === []) {
                throw new \InvalidArgumentException("rank-scoring config: sources.{$source} must be a non-empty category list");
            }
            $clean = [];
            foreach ($categories as $category) {
                if (!is_string($category) || !in_array($category, self::CATEGORIES, true)) {
                    throw new \InvalidArgumentException("rank-scoring config: sources.{$source} references an unknown category");
                }
                $clean[] = $category;
            }
            $sources[(string) $source] = $clean;
        }

        // Invariant 10: Master absent from every rank-keyed structure.
        foreach ([
            'thresholds'          => $thresholds,
            'category_minimums'   => $categoryMinimums,
            'rank_multipliers'    => $rankMultipliers,
            'recognizer_minimums' => $recognizerMinimums,
            'community.caps'      => $communityCaps,
            'trust_windows'       => $trustWindows,
            'tier_ord'            => $tierOrd,
        ] as $keyName => $map) {
            if (array_key_exists('master', $map)) {
                throw new \InvalidArgumentException("rank-scoring config: 'master' must not appear in {$keyName}");
            }
        }

        return new self(
            $categoryMax,
            $thresholds,
            $categoryMinimums,
            $eventCap,
            $shareCaps,
            $maturityFloor,
            $maturitySpan,
            $rankMultipliers,
            $trustMinScore,
            $trustMinValue,
            $trustMaxScore,
            $trustMaxValue,
            $voteCeiling,
            $weightToScoreScale,
            $quorumVoters,
            $quorumWeight,
            $majority,
            $bindingMinDays,
            $bindingMaxDays,
            $clusterRatio,
            $timeCreditPerMonth,
            $timeCreditCap,
            $decayGraceDays,
            $decayStepDays,
            $decayPointsPerStep,
            $tierDaysRetentionDays,
            $tierOrd,
            $trustWindows,
            $recognizerMinimums,
            $outcomeMinimums,
            $diversityMinimums,
            $communityCaps,
            $communityCooldownDays,
            $points,
            $sources
        );
    }

    /**
     * Load and validate the real shipped config file. Boot-time entry
     * point (Plugin accessor) and the CI real-file test both use this.
     */
    public static function fromDefaultFile(): self
    {
        $path = dirname(__DIR__, 4) . '/includes/config/rank-scoring.php';
        /** @var array<mixed> $config */
        $config = require $path;
        return self::fromArray($config);
    }

    /**
     * Ordinal for a tier slug; unknown slugs resolve to neutral's ordinal
     * (mirrors ReputationRepository::getTier's missing-row default).
     */
    public function tierOrdFor(string $tier): int
    {
        return $this->tierOrd[$tier] ?? $this->tierOrd['neutral'];
    }

    // ── validation helpers ───────────────────────────────────────────

    /**
     * @param mixed $value
     * @param list<string> $exactKeys
     * @return array<string, float>
     */
    private static function floatMap(mixed $value, string $key, array $exactKeys): array
    {
        $map = self::mapShape($value, $key, $exactKeys);
        $out = [];
        foreach ($exactKeys as $k) {
            $entry = $map[$k];
            if (!is_int($entry) && !is_float($entry)) {
                throw new \InvalidArgumentException("rank-scoring config: {$key}.{$k} must be numeric");
            }
            $float = (float) $entry;
            if ($float < 0.0) {
                throw new \InvalidArgumentException("rank-scoring config: {$key}.{$k} must not be negative");
            }
            $out[$k] = $float;
        }
        return $out;
    }

    /**
     * @param mixed $value
     * @param list<string> $exactKeys
     * @return array<string, int>
     */
    private static function intMap(mixed $value, string $key, array $exactKeys): array
    {
        $map = self::mapShape($value, $key, $exactKeys);
        $out = [];
        foreach ($exactKeys as $k) {
            $entry = $map[$k];
            if (!is_int($entry) || $entry < 0) {
                throw new \InvalidArgumentException("rank-scoring config: {$key}.{$k} must be a non-negative integer");
            }
            $out[$k] = $entry;
        }
        return $out;
    }

    /**
     * Assert $value is an array containing exactly $exactKeys (no more,
     * no fewer). Returns it string-keyed for downstream reads.
     *
     * @param mixed $value
     * @param list<string> $exactKeys
     * @return array<string, mixed>
     */
    private static function mapShape(mixed $value, string $key, array $exactKeys): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("rank-scoring config: {$key} must be a map");
        }
        foreach ($exactKeys as $k) {
            if (!array_key_exists($k, $value)) {
                throw new \InvalidArgumentException("rank-scoring config: {$key} is missing '{$k}'");
            }
        }
        foreach (array_keys($value) as $k) {
            if (!in_array((string) $k, $exactKeys, true)) {
                throw new \InvalidArgumentException("rank-scoring config: {$key} has unknown entry '{$k}'");
            }
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param mixed $container
     * @return mixed
     */
    private static function mapEntry(mixed $container, string $entry, string $key): mixed
    {
        if (!is_array($container) || !array_key_exists($entry, $container)) {
            throw new \InvalidArgumentException("rank-scoring config: {$key} is missing '{$entry}'");
        }
        return $container[$entry];
    }

    /** @param mixed $value */
    private static function positiveFloat(mixed $value, string $key): float
    {
        if ((!is_int($value) && !is_float($value)) || (float) $value <= 0.0) {
            throw new \InvalidArgumentException("rank-scoring config: {$key} must be a positive number");
        }
        return (float) $value;
    }

    /** @param mixed $value */
    private static function positiveInt(mixed $value, string $key): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException("rank-scoring config: {$key} must be a positive integer");
        }
        return $value;
    }
}
