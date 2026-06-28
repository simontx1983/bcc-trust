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
 * If you change the formula, change it ONLY in compute() and formulaSql().
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
     * The canonical SQL fragment used inside SELECT/UPDATE/INSERT statements
     * that need to compute trust_score in-DB. Single-source replacement for
     * scattered string literals.
     *
     * Caller is responsible for the column-name context (e.g. table alias).
     * Returns the bare expression — wrap it in `AS trust_score` at the call site.
     */
    public static function formulaSql(): string
    {
        // endorsement_bonus RETIRED (endorse-subsystem retirement) — no
        // longer part of the canonical formula; the vouch backing now lives
        // in attestation_bonus. The physical column is dropped by a
        // follow-up migration.
        return 'LEAST(' . self::MAX . ', GREATEST(' . self::MIN . ', '
             . self::neutral()
             . ' + (positive_score - negative_score) * 2 + onchain_bonus + contribution_bonus + penalty_adjustment + attestation_bonus))';
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
    public static function tierSql(string $scoreExpr): string
    {
        $elite   = defined('BCC_TRUST_TIER_ELITE')   ? (int) BCC_TRUST_TIER_ELITE   : 80;
        $trusted = defined('BCC_TRUST_TIER_TRUSTED') ? (int) BCC_TRUST_TIER_TRUSTED : 65;
        $neutral = defined('BCC_TRUST_TIER_NEUTRAL') ? (int) BCC_TRUST_TIER_NEUTRAL : 45;
        $caution = defined('BCC_TRUST_TIER_CAUTION') ? (int) BCC_TRUST_TIER_CAUTION : 30;

        return 'CASE'
             . " WHEN ({$scoreExpr}) >= {$elite} THEN 'elite'"
             . " WHEN ({$scoreExpr}) >= {$trusted} THEN 'trusted'"
             . " WHEN ({$scoreExpr}) >= {$neutral} THEN 'neutral'"
             . " WHEN ({$scoreExpr}) >= {$caution} THEN 'caution'"
             . " ELSE 'risky' END";
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
