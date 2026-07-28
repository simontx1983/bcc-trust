<?php

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\PageReadModelRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE single canonical entry point for the trust-score formula and its
 * read-side lookup. Per §A4 — "single source of trust logic."
 *
 * Every PHP path that needs to compute a trust score goes through
 * compute(); every SQL path that needs the formula as a SQL fragment
 * goes through formulaSql(). Other historical copies have been removed:
 *
 *   - PageScore::computeExpectedTotal() now delegates to compute()
 *   - ScoreRepository::TOTAL_SCORE_SQL was removed; all 4 prior call
 *     sites now invoke formulaSql() directly
 *
 * The formula:
 *
 *     trust_score = clamp(
 *         BCC_TRUST_NEUTRAL_SCORE
 *           + (positive_score - negative_score) * 2
 *           + onchain_bonus
 *           + contribution_bonus
 *           + penalty_adjustment
 *           + attestation_bonus,
 *         0, 100
 *     )
 *
 * `endorsement_bonus` is RETIRED (endorse-subsystem retirement, §J.11):
 * the vouch reaction now writes attestations that fold into
 * attestation_bonus, so the legacy term — and its parameter — are gone.
 *
 * `contribution_bonus` is 0 for entity pages; it carries the "Trust
 * Recovery Through Contribution" term on member self-pages (Architecture A —
 * a person is a page; their tier is this formula on their self-page).
 *
 * Two read paths exist for callers who want the trust score for a page:
 *
 *   1) Live computation against vote/endorsement aggregates
 *      → call into ScoreRepository (which now uses formulaSql() inline)
 *   2) Denormalized read model
 *      → PageReadModelRepository.bcc_page_read_model
 *
 * If you change the formula, change it ONLY in compute() and formulaSqlFor()
 * (the private builder behind formulaSql() and nativeFormulaSql()).
 *
 * This class also owns the reputation TIER ladder in both dialects —
 * tierFor() in PHP and tierSql() in SQL. They must stay in lockstep; a
 * parity test asserts they encode the same predicate. As of the §J.12 Elite
 * gate the ladder is no longer a pure score cutoff: `elite` additionally
 * requires the denormalized `elite_eligible` flag and a native-conduct floor
 * that excludes `onchain_bonus`. Do NOT reintroduce a local tier ladder —
 * six had accumulated before tierFor() existed, and one of them (the admin
 * score override) minted ungated elites.
 *
 * @status alive (locked-canonical) — single source of trust-score formula
 *                 truth. Constitutional anchor for §III.10 / §III.11.
 *                 PageScore::computeExpectedTotal delegates here;
 *                 ScoreRepository::TOTAL_SCORE_SQL was removed in favor of
 *                 formulaSql(). Do NOT add a parallel compute path. Phase B
 *                 V-18 classification 2026-05-09. See
 *                 docs/pattern-registry.md "Phase B inventory addendum"
 *                 + the canonical entry under "Reputation".
 */
final class TrustScoreService
{
    private const MIN = 0;
    private const MAX = 100;

    private ScoreRepository $scoreRepository;
    private PageReadModelRepository $readModelRepository;

    public function __construct(
        ScoreRepository $scoreRepository,
        PageReadModelRepository $readModelRepository
    ) {
        $this->scoreRepository     = $scoreRepository;
        $this->readModelRepository = $readModelRepository;
    }

    /**
     * Compute trust score from raw components. Pure function — same input,
     * same output. THE canonical PHP implementation per §A4 — all other
     * compute sites (PageScore::computeExpectedTotal, debug panel,
     * repair service, intent-guard) delegate to this method.
     *
     * Floats throughout: callers pass DECIMAL columns (positive_score,
     * negative_score, onchain_bonus) which arrive from $wpdb as floats.
     * Output is clamped to [0.0, 100.0].
     */
    public static function compute(
        float $positiveScore,
        float $negativeScore,
        float $onchainBonus,
        float $contributionBonus = 0.0,
        float $penaltyAdjustment = 0.0,
        float $attestationBonus = 0.0
    ): float {
        $base  = (float) self::neutral() + ($positiveScore - $negativeScore) * 2.0;
        // penalty_adjustment is stored NEGATIVE (dispute/admin penalties
        // subtract); it's an additive term like the bonuses, clobber-safe
        // against vote recalcs because it lives in its own column.
        // attestation_bonus (Slice E) is the trust-attestation synthesis term —
        // bounded upstream by AttestationScoreSynthesis (decay + caps + ceiling).
        //
        // endorsement_bonus is RETIRED (endorse-subsystem retirement) and no
        // longer a term — the vouch reaction now feeds attestation_bonus.
        $bonus = $onchainBonus + $contributionBonus + $penaltyAdjustment + $attestationBonus;
        $total = $base + $bonus;
        return max((float) self::MIN, min((float) self::MAX, $total));
    }

    /**
     * Compute the NATIVE trust score — the score a page would have from BCC
     * conduct alone, with the on-chain wallet bonus excluded.
     *
     * Deliberately a delegation, not a second formula: `compute()` already
     * takes `$onchainBonus` as a discrete parameter and clamps the result
     * independently, so passing 0.0 IS the native score. Adding parallel
     * arithmetic here would violate the single-formula rule this class exists
     * to enforce. The named alias exists only so call sites read as intent
     * ("native score") rather than as a magic zero.
     *
     * Used by the §J.12 Elite gate: on-chain depth is a property of a wallet,
     * not of conduct on BCC, so it must not be able to carry a page over the
     * elite threshold by itself. See tierFor().
     */
    public static function computeNative(
        float $positiveScore,
        float $negativeScore,
        float $contributionBonus = 0.0,
        float $penaltyAdjustment = 0.0,
        float $attestationBonus = 0.0
    ): float {
        return self::compute(
            $positiveScore,
            $negativeScore,
            0.0,
            $contributionBonus,
            $penaltyAdjustment,
            $attestationBonus
        );
    }

    /**
     * The canonical SQL fragment used inside SELECT/UPDATE/INSERT statements
     * that need to compute trust_score in-DB. Single-source replacement for
     * scattered string literals.
     *
     * Caller is responsible for the column-name context (e.g. table alias).
     * Returns the bare expression — wrap it in `AS trust_score` at the call site.
     */
    public static function formulaSql(): string
    {
        return self::formulaSqlFor(true);
    }

    /**
     * The SQL twin of computeNative() — the canonical formula with the
     * `onchain_bonus` term removed, clamped INDEPENDENTLY.
     *
     * The independent clamp is load-bearing. Subtracting `onchain_bonus` from
     * the already-clamped total_score does NOT yield this value: once the raw
     * sum exceeds MAX the subtraction reads back a number that no longer
     * reflects native conduct at all. Two pages with wildly different conduct
     * (positive_score 20 vs 5, both with onchain 20 + attestation 20) both
     * clamp to 100 and both would report a "native" 80. This expression
     * recomputes from the terms instead, so those two read 90 and 60.
     *
     * Caller is responsible for the column-name context, same as formulaSql().
     */
    public static function nativeFormulaSql(): string
    {
        return self::formulaSqlFor(false);
    }

    /**
     * Shared builder for the two formula expressions above. Exists so the
     * clamp bounds, the neutral baseline and the term list cannot drift
     * between the full and native variants — the drift this class was created
     * to prevent (see the class docblock).
     *
     * endorsement_bonus RETIRED (endorse-subsystem retirement) — no longer
     * part of the canonical formula; the vouch backing now lives in
     * attestation_bonus. The physical column is dropped by a follow-up
     * migration.
     */
    private static function formulaSqlFor(bool $includeOnchain): string
    {
        $terms = '(positive_score - negative_score) * 2';
        if ($includeOnchain) {
            $terms .= ' + onchain_bonus';
        }
        $terms .= ' + contribution_bonus + penalty_adjustment + attestation_bonus';

        return 'LEAST(' . self::MAX . ', GREATEST(' . self::MIN . ', '
             . self::neutral() . ' + ' . $terms . '))';
    }

    /**
     * The canonical SQL `CASE` that maps a score expression to a
     * reputation_tier, mirroring ReputationRepository::calculateTier() /
     * VoteService::determineTier() — single source of truth for the tier
     * thresholds in SQL. Used by score-row writes that recompute total_score
     * inline (endorsement/contribution bonus applies) so reputation_tier never
     * goes stale relative to total_score.
     *
     * Pass the same expression you pass for total_score (e.g. formulaSql()).
     * The caller wraps it as `reputation_tier = <tierSql>` in the SET clause.
     */
    /**
     * @param bool $withEliteGate Whether `elite_eligible` / `elite_eligible_at`
     *        may be referenced. Pass FALSE when the columns are not yet
     *        present (schema migration has not run on this database — see
     *        TableRegistry::columnExists). With the gate off the elite arm
     *        keeps the score threshold and the native-conduct floor, which
     *        are pure expressions over columns that already exist, and drops
     *        only the two cross-table conditions. That degrades to the
     *        pre-gate behaviour for the migration window instead of emitting
     *        SQL against a missing column, which would fail the whole score
     *        write.
     */
    public static function tierSql(string $scoreExpr, ?string $nativeExpr = null, bool $withEliteGate = true): string
    {
        $elite   = self::threshold('BCC_TRUST_TIER_ELITE', 80);
        $trusted = self::threshold('BCC_TRUST_TIER_TRUSTED', 65);
        $neutral = self::threshold('BCC_TRUST_TIER_NEUTRAL', 45);
        $caution = self::threshold('BCC_TRUST_TIER_CAUTION', 30);
        $floor   = self::eliteNativeFloor();

        // Defaulted rather than required so all existing call sites keep
        // working unchanged — they pass formulaSql() as $scoreExpr and want
        // its native twin here.
        $nativeExpr ??= self::nativeFormulaSql();

        // §J.12 Elite gate. Three conditions, all necessary:
        //   1. the score threshold (unchanged)
        //   2. elite_eligible — the denormalized cross-table gate (distinct
        //      attestors / tenure / dispute-free window), maintained by
        //      EliteEligibilityService. `elite_eligible_at IS NULL` means
        //      "never evaluated" and is GRANDFATHERED, so deploying the
        //      column does not demote anyone before the backfill runs.
        //   3. the native-conduct floor — on-chain wallet depth alone must
        //      not mint elite. See nativeFormulaSql().
        $gate = $withEliteGate
            ? ' AND (elite_eligible = 1 OR elite_eligible_at IS NULL)'
            : '';

        return 'CASE'
             . " WHEN ({$scoreExpr}) >= {$elite}"
             . $gate
             . " AND ({$nativeExpr}) >= {$floor} THEN 'elite'"
             . " WHEN ({$scoreExpr}) >= {$trusted} THEN 'trusted'"
             . " WHEN ({$scoreExpr}) >= {$neutral} THEN 'neutral'"
             . " WHEN ({$scoreExpr}) >= {$caution} THEN 'caution'"
             . " ELSE 'risky' END";
    }

    /**
     * The canonical PHP tier ladder — the exact twin of tierSql().
     *
     * Before this existed the ladder was written out SIX times (VoteService,
     * Admin\RepairService, Admin\ModerationService, includes/admin/moderation.php,
     * BehavioralAnalyzer's bare elite comparison, and tierSql itself). Any two
     * drifting apart mis-tiers a page on one write path relative to another.
     * All of them now resolve through here.
     *
     * This matters more than it did before the §J.12 gate: the 5-minute
     * recalculation queue and the hourly recalc write reputation_tier through
     * VoteService, NOT through tierSql. A gate applied only in SQL would be
     * silently reverted within five minutes of every demotion.
     *
     * @param float $score          The clamped total_score.
     * @param float $nativeScore    The clamped score excluding onchain_bonus
     *                              (computeNative()).
     * @param bool  $eliteEligible  The resolved cross-table gate — pass the
     *                              output of resolveEliteEligible(), never a
     *                              raw column value.
     */
    public static function tierFor(float $score, float $nativeScore, bool $eliteEligible): string
    {
        if (
            $score >= (float) self::threshold('BCC_TRUST_TIER_ELITE', 80)
            && $eliteEligible
            && $nativeScore >= (float) self::eliteNativeFloor()
        ) {
            return 'elite';
        }
        if ($score >= (float) self::threshold('BCC_TRUST_TIER_TRUSTED', 65)) {
            return 'trusted';
        }
        if ($score >= (float) self::threshold('BCC_TRUST_TIER_NEUTRAL', 45)) {
            return 'neutral';
        }
        if ($score >= (float) self::threshold('BCC_TRUST_TIER_CAUTION', 30)) {
            return 'caution';
        }
        return 'risky';
    }

    /**
     * Encode the grandfather rule once, so PHP and SQL agree on what an
     * unevaluated row means.
     *
     * A row whose `elite_eligible_at` is NULL has never been looked at by
     * EliteEligibilityService, and is treated as eligible so that shipping the
     * column is not itself a mass demotion. Once the backfill has stamped
     * every row this can only return the flag.
     *
     * @param int|string|bool|null $flag        The elite_eligible column.
     * @param string|null          $evaluatedAt The elite_eligible_at column.
     */
    public static function resolveEliteEligible($flag, ?string $evaluatedAt): bool
    {
        if ($evaluatedAt === null || $evaluatedAt === '' || $evaluatedAt === '0000-00-00 00:00:00') {
            return true;
        }
        return (int) $flag === 1;
    }

    /**
     * The §J.12 native-conduct floor: the minimum score-excluding-onchain a
     * page must reach to be eligible for elite.
     *
     * Filterable so closed-network testing can tune it without a redeploy,
     * matching the convention in includes/config/attestation.php.
     */
    public static function eliteNativeFloor(): int
    {
        $floor = self::threshold('BCC_TRUST_ELITE_NATIVE_FLOOR', 71);
        return (int) apply_filters('bcc_trust_elite_native_floor', $floor);
    }

    /**
     * Resolve a threshold constant with a hardcoded fallback, for load orders
     * where includes/config/tiers.php has not been required yet (early
     * bootstrap, unit tests).
     */
    private static function threshold(string $constant, int $default): int
    {
        return defined($constant) ? (int) constant($constant) : $default;
    }

    /**
     * Read the trust score for a page (and optional category). Prefers the
     * denormalized read model for hot-path reads; falls back to live score
     * computation when the read model row is absent (race window between
     * page creation and the first PageReadModelSync run).
     *
     * Returns null when neither source has data — caller decides whether to
     * default to BCC_TRUST_NEUTRAL_SCORE.
     */
    public function getForPage(int $pageId, ?int $categoryId = null): ?int
    {
        if ($categoryId === null || $categoryId === 0) {
            $row = $this->readModelRepository->getByPageId($pageId);
            if ($row !== null && isset($row->trust_score)) {
                return (int) $row->trust_score;
            }
        }

        $score = $this->scoreRepository->getByPageId($pageId, $categoryId);
        if ($score === null) {
            return null;
        }

        return (int) $score->getTotalScore();
    }

    /**
     * Bulk variant — used by feed/discover/leaderboard surfaces that need
     * many pages at once. Always reads from the denormalized read model;
     * pages without a read-model row return null in the map.
     *
     * @param list<int> $pageIds
     * @return array<int, ?int> page_id => trust_score (null if no data)
     */
    public function getForPages(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        $rows = $this->readModelRepository->getByPageIds($pageIds);
        $out  = [];
        foreach ($pageIds as $id) {
            $row = $rows[$id] ?? null;
            $out[$id] = ($row !== null && isset($row->trust_score)) ? (int) $row->trust_score : null;
        }
        return $out;
    }

    /**
     * The neutral baseline — equivalent to BCC_TRUST_NEUTRAL_SCORE but
     * resolvable at any load order. Defaults to 50 when the constant has
     * not yet been defined (early bootstrap, tests).
     */
    public static function neutral(): int
    {
        return defined('BCC_TRUST_NEUTRAL_SCORE') ? (int) BCC_TRUST_NEUTRAL_SCORE : 50;
    }
}
