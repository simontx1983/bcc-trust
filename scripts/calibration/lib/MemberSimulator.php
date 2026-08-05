<?php
/**
 * CalibMemberSimulator / CalibRingSimulator — synthetic member history
 * generation for the Phase 9 calibration harness.
 *
 * REAL pieces used verbatim:
 *   - RankScoringConfig points + sources maps (the routing DATA);
 *   - RankEvidenceIngestor::allocateWithinCap() — the §4.4 event-cap
 *     allocator (public static, pure);
 *   - RankScoreCalculator via CalibRequirementsMirror::assess().
 *
 * MIRRORED pieces (ingest is transaction/repository-bound):
 *   - POINT_KEY_BY_SOURCE (private const on RankEvidenceIngestor —
 *     copied verbatim below; drift = harness bug);
 *   - the R3 zero-credit rule (IndependenceResolver::isZeroCredit →
 *     capped_value 0 for non-representative members of a confirmed
 *     cluster, raw kept) — applied at materialization time.
 *
 * Determinism: mt_srand() with a crc32-derived seed per member (honest
 * cohorts) or per ring; every run reproduces bit-identically on the
 * same PHP major (PHP's Mersenne Twister is stable since 7.1).
 */

declare(strict_types=1);

use BCC\Trust\Rank\Services\RankEvidenceIngestor;
use BCC\Trust\Rank\Services\RankScoreCalculator;
use BCC\Trust\Rank\Support\RankScoringConfig;

final class CalibProfiles
{
    /**
     * Honest-cohort activity profiles. Per-month rates unless noted.
     *
     * BRIEF-SPECIFIED: logins_per_month, posts_per_month,
     * comments_per_active_day, reviews_per_month, and heavy's
     * "occasional attestations received".
     *
     * ASSUMED (documented calibration assumptions — see README ▸
     * "Modeling assumptions"): attestations_received_per_month for the
     * non-heavy profiles, every outcomes rate, and — since the helping
     * emitters landed (2026-08-04) — the two helping evidence streams
     * that model the REAL merged emitters:
     *
     *   helpful_received_per_month / distinct_markers  → the §9.2
     *       credible-Helpful-mark route. A member EARNS helping when
     *       OTHER credible members mark THEIR content helpful; the mark
     *       credits the content author, keyed by the MARKER as the §9.3
     *       relationship id (share-capped at 0.10 × 25 = 2.5 per marker).
     *       So filling helping is DISTINCT-MARKER-bounded, not volume-
     *       bounded: distinct_markers is the size of a member's credible-
     *       marker pool; helpful_received_per_month is the arrival rate,
     *       spread uniformly across that pool. Heavier contributors post
     *       more helpful content → more markers and a higher rate.
     *
     *   owner_period  → the weekly stewardship route. A member who OWNS
     *       an active User-kind community (≥5 active posters) earns one
     *       `stewardship` event per ISO week (→ contribution + helping,
     *       relationship id 0, so all stewardship-helping shares the
     *       single identity-0 bucket capped at 2.5). owner_period p>0
     *       makes members at index % p == 0 active-community owners;
     *       0 = no owners. Stewardship is the ONLY 5th contribution
     *       source-type, so Veteran contribution-diversity-5 is
     *       reachable ONLY by owners.
     *
     * The brief's profiles carry no helping- or outcomes-category
     * actions at all, yet the §4.3 minimums require both — so every rate
     * below is load-bearing for every promotion-day number the harness
     * reports.
     */
    public const HONEST = [
        'casual' => [
            'logins_per_month'           => 8.0,
            'posts_per_month'            => 2.0,
            'comments_per_active_day'    => 1,
            'reviews_per_month'          => 0.0,
            'attest_received_per_month'  => 0.5,  // ASSUMED
            'proven_outcome_per_month'   => 0.0,
            'upheld_report_per_month'    => 0.0,
            'helpful_received_per_month' => 2.0,  // ASSUMED (credible marks received)
            'distinct_markers'           => 4,    // ASSUMED (marker-pool size)
            'owner_period'               => 0,    // no active-community owners
        ],
        'regular' => [
            'logins_per_month'           => 15.0,
            'posts_per_month'            => 4.0,
            'comments_per_active_day'    => 2,
            'reviews_per_month'          => 1.0,
            'attest_received_per_month'  => 1.0,  // ASSUMED
            'proven_outcome_per_month'   => 0.2,  // ASSUMED
            'upheld_report_per_month'    => 0.1,  // ASSUMED
            'helpful_received_per_month' => 6.0,  // ASSUMED
            'distinct_markers'           => 8,    // ASSUMED
            'owner_period'               => 3,    // ASSUMED (~33% own an active community)
        ],
        'heavy' => [
            'logins_per_month'           => 22.0,
            'posts_per_month'            => 8.0,
            'comments_per_active_day'    => 4,
            'reviews_per_month'          => 2.0,
            'attest_received_per_month'  => 2.0,  // brief: "occasional"
            'proven_outcome_per_month'   => 0.5,  // ASSUMED
            'upheld_report_per_month'    => 0.25, // ASSUMED
            'helpful_received_per_month' => 14.0, // ASSUMED
            'distinct_markers'           => 12,   // ASSUMED
            'owner_period'               => 2,    // ASSUMED (50% own an active community)
        ],
        'sporadic' => [
            'logins_per_month'           => 4.0,
            'posts_per_month'            => 1.0,
            'comments_per_active_day'    => 0,
            'reviews_per_month'          => 0.0,
            'attest_received_per_month'  => 0.25, // ASSUMED
            'proven_outcome_per_month'   => 0.0,
            'upheld_report_per_month'    => 0.0,
            'helpful_received_per_month' => 0.4,  // ASSUMED
            'distinct_markers'           => 2,    // ASSUMED
            'owner_period'               => 0,
        ],
    ];

    /**
     * ID base for a member's synthetic credible-marker pool. Marker ids
     * are MARKER_ID_BASE + memberId * MARKER_ID_STRIDE + k (k in
     * 0..distinct_markers-1) — distinct per (member, marker) and far
     * clear of both the 1..cohortSize counterparty ids and the
     * identity-0 stewardship bucket, so each marker is its own §9.3
     * helping-cap identity. Non-credible marks mint no evidence in the
     * real emitter, so the harness only ever emits credible ones.
     */
    public const MARKER_ID_BASE   = 700000;
    public const MARKER_ID_STRIDE = 100;

    /**
     * Modeled day a new owner's community first clears the ≥5-active-
     * poster bar (ASSUMED — a community needs ~2 weeks to warm up). No
     * stewardship credit before this day.
     */
    public const STEWARDSHIP_ACTIVATION_DAY = 14;

    /** Stewardship cadence: one event per this many days (weekly sweep). */
    public const STEWARDSHIP_PERIOD_DAYS = 7;

    /**
     * Deterministic active-community ownership by (profile, cohort
     * index). owner_period p: owner ⇔ p > 0 AND index % p == 0.
     */
    public static function isCommunityOwner(string $profileName, int $index): bool
    {
        $period = self::HONEST[$profileName]['owner_period'] ?? 0;
        return $period > 0 && ($index % $period) === 0;
    }

    /**
     * Ring cadence (heavy self-activity + mutual recognition/helping).
     * Per-ordered-pair monthly rates for attest/help.
     */
    public const RING = [
        'logins_per_month'        => 22.0,
        'posts_per_month'         => 8.0,
        'comments_per_active_day' => 4,
        'reviews_per_month'       => 2.0,
        'attest_per_pair_month'   => 2.0,
        'help_per_pair_month'     => 2.0,
    ];

    /** Members per honest profile (brief: N=200 × 4 profiles). */
    public const COHORT_N = 200;

    /** Modeled trusted-tier start day for tier-qualified members (ASSUMED). */
    public const TRUSTED_FROM_DAY = 90;
}

final class CalibMemberSimulator
{
    /**
     * MIRROR of RankEvidenceIngestor::POINT_KEY_BY_SOURCE (private
     * const). Copied verbatim; drift = harness bug.
     */
    public const POINT_KEY_BY_SOURCE = [
        'post'          => 'post',
        'comment'       => 'comment',
        'review'        => 'review',
        'report_upheld' => 'upheld_report',
        'helpful_mark'  => 'helping_min',
        'recognition'   => 'recognition_weight_factor',
        'outcome'       => 'proven_outcome',
        'stewardship'   => 'stewardship',
        'onboarding'    => 'onboarding_assist',
    ];

    /**
     * Materialize one evidence event into ledger rows, mirroring the
     * ingest path: real sources map → real points map → REAL
     * allocateWithinCap() → per-category rows; the R3 zero-credit rule
     * zeroes capped_value for confirmed-cluster non-representatives.
     *
     * @param list<object> $rows Appended in place.
     */
    public static function ingest(
        array &$rows,
        RankScoringConfig $config,
        string $sourceType,
        int $relationshipUserId,
        int $day,
        string $uid,
        bool $zeroCredit = false
    ): void {
        $categories = $config->sources[$sourceType] ?? null;
        $pointKey   = self::POINT_KEY_BY_SOURCE[$sourceType] ?? null;
        if ($categories === null || $pointKey === null) {
            return; // Mirror: unknown source is a fail-safe no-op.
        }
        $raw = $config->points[$pointKey] ?? 0.0;
        if ($raw <= 0.0) {
            return;
        }

        // REAL §4.4 event-cap allocator.
        $capped = RankEvidenceIngestor::allocateWithinCap(
            array_fill_keys($categories, $raw),
            $config->eventCap
        );

        foreach ($categories as $category) {
            $rows[] = (object) [
                'category'             => $category,
                'source_type'          => $sourceType,
                'relationship_user_id' => $relationshipUserId,
                'capped_value'         => $zeroCredit ? 0.0 : $capped[$category],
                'sim_day'              => $day,
                'sim_uid'              => $uid,
            ];
        }
    }

    /** Deterministic per-member seed. */
    public static function seed(string $label): int
    {
        return crc32($label) & 0x7fffffff;
    }

    /** Bernoulli draw from the current mt_rand stream. */
    private static function chance(float $p): bool
    {
        if ($p <= 0.0) {
            return false;
        }
        return mt_rand() / mt_getrandmax() < $p;
    }

    /**
     * Generate one honest member's full history, day-stepped.
     *
     * Cadence model: the member's OWN actions (post/comment/review/
     * outcome/upheld report) occur only on login days, with per-login-day
     * probability = monthly_rate / logins_per_month.
     *
     * Two evidence streams do NOT depend on the member's own logins,
     * matching the real emitters (see CalibProfiles::HONEST):
     *
     *   - recognition received  (P = rate / 30 per day): an attestation
     *     from a cohort counterparty (identity = the attestor);
     *   - helpful marks received (P = rate / 30 per day): a credible
     *     mark on the member's content, credited to the member and keyed
     *     by ONE of the member's distinct_markers (drawn uniformly from
     *     that fixed pool) — so a small marker pool saturates the §9.3
     *     0.10 (=2.5) per-marker helping cap and volume beyond it is lost;
     *   - stewardship (owners only, weekly from the activation day):
     *     one `stewardship` event → contribution + helping, relationship
     *     id 0 (shared identity-0 helping bucket).
     *
     * @param int|null $stopDay All activity (given AND received) stops
     *        at this day — the decay scenario's inactivity switch.
     * @param bool $isOwner Member owns an active User-kind community →
     *        weekly stewardship accrual (the 5th contribution type).
     * @return array{
     *     rows: list<object>,
     *     prefix: array<int, int>,
     *     login_months_by_day: array<int, int>,
     *     last_login_by_day: array<int, int|null>,
     *     login_days: list<int>
     * }
     */
    public static function generate(
        RankScoringConfig $config,
        array $profile,
        int $memberId,
        int $cohortSize,
        int $seed,
        int $maxDay,
        ?int $stopDay = null,
        bool $isOwner = false
    ): array {
        mt_srand($seed);

        $rows       = [];
        $loginDays  = [];
        $months     = [];
        $monthsByDay = [];
        $lastByDay  = [];
        $prefix     = [];
        $uidSeq     = 0;

        $pLogin       = $profile['logins_per_month'] / 30.0;
        $pPost        = $profile['logins_per_month'] > 0 ? $profile['posts_per_month'] / $profile['logins_per_month'] : 0.0;
        $pReview      = $profile['logins_per_month'] > 0 ? $profile['reviews_per_month'] / $profile['logins_per_month'] : 0.0;
        $pOutcome     = $profile['logins_per_month'] > 0 ? $profile['proven_outcome_per_month'] / $profile['logins_per_month'] : 0.0;
        $pUpheld      = $profile['logins_per_month'] > 0 ? $profile['upheld_report_per_month'] / $profile['logins_per_month'] : 0.0;
        $pAttestRecv  = $profile['attest_received_per_month'] / 30.0;
        $pHelpfulRecv = $profile['helpful_received_per_month'] / 30.0;
        $distinctMarkers = max(0, (int) $profile['distinct_markers']);
        $markerBase   = CalibProfiles::MARKER_ID_BASE + $memberId * CalibProfiles::MARKER_ID_STRIDE;

        $lastLogin = null;

        for ($day = 0; $day <= $maxDay; $day++) {
            $active = $stopDay === null || $day < $stopDay;

            if ($active && self::chance($pLogin)) {
                $loginDays[] = $day;
                $lastLogin   = $day;
                $months[gmdate('Y-m', calib_epoch_ts() + $day * 86400)] = true;

                if (self::chance($pPost)) {
                    self::ingest($rows, $config, 'post', 0, $day, "m{$memberId}:e" . $uidSeq++);
                }
                for ($c = 0; $c < $profile['comments_per_active_day']; $c++) {
                    self::ingest($rows, $config, 'comment', 0, $day, "m{$memberId}:e" . $uidSeq++);
                }
                if (self::chance($pReview)) {
                    self::ingest($rows, $config, 'review', 0, $day, "m{$memberId}:e" . $uidSeq++);
                }
                if (self::chance($pOutcome)) {
                    self::ingest($rows, $config, 'outcome', 0, $day, "m{$memberId}:e" . $uidSeq++);
                }
                if (self::chance($pUpheld)) {
                    self::ingest($rows, $config, 'report_upheld', 0, $day, "m{$memberId}:e" . $uidSeq++);
                }
            }

            // Received evidence — independent of the member's own logins.
            if ($active && self::chance($pAttestRecv)) {
                $attestor = self::otherMember($memberId, $cohortSize);
                self::ingest($rows, $config, 'recognition', $attestor, $day, "m{$memberId}:e" . $uidSeq++);
            }
            if ($active && $distinctMarkers > 0 && self::chance($pHelpfulRecv)) {
                // §9.2: a credible member marks the member's content
                // helpful → helping credit keyed by the MARKER (§9.3 cap).
                $marker = $markerBase + mt_rand(0, $distinctMarkers - 1);
                self::ingest($rows, $config, 'helpful_mark', $marker, $day, "m{$memberId}:e" . $uidSeq++);
            }

            // Weekly stewardship for owners of an active community.
            if (
                $active
                && $isOwner
                && $day >= CalibProfiles::STEWARDSHIP_ACTIVATION_DAY
                && (($day - CalibProfiles::STEWARDSHIP_ACTIVATION_DAY) % CalibProfiles::STEWARDSHIP_PERIOD_DAYS) === 0
            ) {
                self::ingest($rows, $config, 'stewardship', 0, $day, "m{$memberId}:steward{$day}");
            }

            $prefix[$day]      = count($rows);
            $monthsByDay[$day] = count($months);
            $lastByDay[$day]   = $lastLogin;
        }

        return [
            'rows'                => $rows,
            'prefix'              => $prefix,
            'login_months_by_day' => $monthsByDay,
            'last_login_by_day'   => $lastByDay,
            'login_days'          => $loginDays,
        ];
    }

    private static function otherMember(int $memberId, int $cohortSize): int
    {
        do {
            $id = mt_rand(1, $cohortSize);
        } while ($id === $memberId);
        return $id;
    }

    /**
     * Full mirrored assessment of a generated member at one day.
     *
     * @param array<string, mixed> $gen generate() output.
     * @param array<int, int> $clusterMap
     * @param array<string, bool> $excludedUids Reversed event uids.
     * @return array<string, mixed> CalibRequirementsMirror::assess() shape.
     */
    public static function assessAt(
        RankScoreCalculator $calculator,
        RankScoringConfig $config,
        array $gen,
        int $day,
        bool $tierQualified,
        int $trustedFromDay,
        array $clusterMap = [],
        array $excludedUids = []
    ): array {
        $rows = array_slice($gen['rows'], 0, $gen['prefix'][$day]);
        if ($excludedUids !== []) {
            $rows = array_values(array_filter(
                $rows,
                static fn (object $r): bool => !isset($excludedUids[$r->sim_uid])
            ));
        }

        $lastLogin = $gen['last_login_by_day'][$day];

        return CalibRequirementsMirror::assess(
            $calculator,
            $config,
            $rows,
            $gen['login_months_by_day'][$day],
            $lastLogin === null ? null : calib_day_to_date($lastLogin),
            $clusterMap,
            calib_day_to_date($day),
            CalibRequirementsMirror::modeledWindows($config, $day, $tierQualified, $trustedFromDay)
        );
    }

    /**
     * First day (binary search) on which a rung's every requirement
     * holds, or null if it never holds by $maxDay.
     *
     * Valid because the hold predicate is monotone in this simulation:
     * evidence only accrues (no reversals after $searchFrom), decay is
     * zero for members who keep logging in inside the 365-day grace,
     * time credit and window-qualifying days only grow.
     */
    public static function firstHoldDay(
        RankScoreCalculator $calculator,
        RankScoringConfig $config,
        array $gen,
        string $rung,
        int $maxDay,
        bool $tierQualified,
        int $trustedFromDay,
        array $clusterMap = [],
        array $excludedUids = [],
        int $searchFrom = 0
    ): ?int {
        $holds = static function (int $day) use ($calculator, $config, $gen, $rung, $tierQualified, $trustedFromDay, $clusterMap, $excludedUids): bool {
            $a = self::assessAt($calculator, $config, $gen, $day, $tierQualified, $trustedFromDay, $clusterMap, $excludedUids);
            return $a['satisfied'][$rung];
        };

        if (!$holds($maxDay)) {
            return null;
        }

        $lo = $searchFrom;
        $hi = $maxDay;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($holds($mid)) {
                $hi = $mid;
            } else {
                $lo = $mid + 1;
            }
        }
        return $lo;
    }
}

final class CalibRingSimulator
{
    /**
     * Generate a ring of $r accounts created day 0 with mutual
     * attest/help at heavy cadence and NO outside recognition. Raw
     * event tuples are treatment-independent; materialize() applies the
     * confirmed / suspected / honest-twin treatment.
     *
     * @return array{
     *     events: list<array{day: int, subject: int, source: string, rel: int|null, uid: string}>,
     *     members: array<int, array{login_months_by_day: array<int, int>, last_login_by_day: array<int, int|null>}>
     * }
     */
    public static function generate(int $r, int $seed, int $maxDay): array
    {
        mt_srand($seed);
        $p = CalibProfiles::RING;

        $pLogin  = $p['logins_per_month'] / 30.0;
        $pPost   = $p['posts_per_month'] / $p['logins_per_month'];
        $pReview = $p['reviews_per_month'] / $p['logins_per_month'];
        $pAttest = $p['attest_per_pair_month'] / 30.0;
        $pHelp   = $p['help_per_pair_month'] / 30.0;

        $events  = [];
        $members = [];
        $months  = [];
        $last    = [];
        $uidSeq  = 0;
        for ($i = 0; $i < $r; $i++) {
            $members[$i] = ['login_months_by_day' => [], 'last_login_by_day' => []];
            $months[$i]  = [];
            $last[$i]    = null;
        }

        for ($day = 0; $day <= $maxDay; $day++) {
            for ($i = 0; $i < $r; $i++) {
                if (mt_rand() / mt_getrandmax() < $pLogin) {
                    $last[$i] = $day;
                    $months[$i][gmdate('Y-m', calib_epoch_ts() + $day * 86400)] = true;

                    if (mt_rand() / mt_getrandmax() < $pPost) {
                        $events[] = ['day' => $day, 'subject' => $i, 'source' => 'post', 'rel' => null, 'uid' => 'r' . $uidSeq++];
                    }
                    for ($c = 0; $c < $p['comments_per_active_day']; $c++) {
                        $events[] = ['day' => $day, 'subject' => $i, 'source' => 'comment', 'rel' => null, 'uid' => 'r' . $uidSeq++];
                    }
                    if (mt_rand() / mt_getrandmax() < $pReview) {
                        $events[] = ['day' => $day, 'subject' => $i, 'source' => 'review', 'rel' => null, 'uid' => 'r' . $uidSeq++];
                    }
                }
            }
            // Ordered pairs: i attests / helps j.
            for ($i = 0; $i < $r; $i++) {
                for ($j = 0; $j < $r; $j++) {
                    if ($i === $j) {
                        continue;
                    }
                    if (mt_rand() / mt_getrandmax() < $pAttest) {
                        // Recognition credits the RECIPIENT j; attestor i is the relationship identity.
                        $events[] = ['day' => $day, 'subject' => $j, 'source' => 'recognition', 'rel' => $i, 'uid' => 'r' . $uidSeq++];
                    }
                    if (mt_rand() / mt_getrandmax() < $pHelp) {
                        // Helping credits the HELPER i; recipient j is the relationship identity.
                        $events[] = ['day' => $day, 'subject' => $i, 'source' => 'helpful_mark', 'rel' => $j, 'uid' => 'r' . $uidSeq++];
                    }
                }
            }
            for ($i = 0; $i < $r; $i++) {
                $members[$i]['login_months_by_day'][$day] = count($months[$i]);
                $members[$i]['last_login_by_day'][$day]   = $last[$i];
            }
        }

        return ['events' => $events, 'members' => $members];
    }

    /**
     * Materialize one ring member's ledger under a treatment.
     *
     * Treatments:
     *   'confirmed' — CONFIRMED cluster finding active: non-representative
     *       subjects ingest with capped_value 0 (R3 zero-credit mirror);
     *       relationship ids stay ring-internal (the calculator's
     *       cluster map collapses them).
     *   'suspected' — no representative collapse, no zero-credit; share
     *       caps only.
     *   'twin' — the honest-equivalent control: identical activity, but
     *       every ring-internal counterparty id is remapped to a
     *       distinct INDEPENDENT external id.
     *
     * Member ids inside rows are ring indexes offset by +1 (ids 1..R);
     * twin external ids start at 500001.
     *
     * @param array{events: list<array{day: int, subject: int, source: string, rel: int|null, uid: string}>, members: array<int, mixed>} $ring
     * @return array{rows: list<object>, prefix: array<int, int>}
     */
    public static function materialize(
        array $ring,
        int $memberIdx,
        string $treatment,
        int $representativeIdx,
        RankScoringConfig $config,
        int $maxDay
    ): array {
        $rows   = [];
        $prefix = [];
        $eventPtr = 0;
        $events = $ring['events'];
        $n      = count($events);

        $zeroCredit = $treatment === 'confirmed' && $memberIdx !== $representativeIdx;

        for ($day = 0; $day <= $maxDay; $day++) {
            while ($eventPtr < $n && $events[$eventPtr]['day'] <= $day) {
                $e = $events[$eventPtr];
                $eventPtr++;
                if ($e['subject'] !== $memberIdx) {
                    continue;
                }
                $rel = 0;
                if ($e['rel'] !== null) {
                    $rel = $treatment === 'twin' ? 500001 + $e['rel'] : $e['rel'] + 1;
                }
                CalibMemberSimulator::ingest($rows, $config, $e['source'], $rel, $e['day'], $e['uid'], $zeroCredit);
            }
            $prefix[$day] = count($rows);
        }

        return ['rows' => $rows, 'prefix' => $prefix];
    }
}
