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
 *           + endorsement_bonus
 *           + onchain_bonus,
 *         0, 100
 *     )
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
     * negative_score, endorsement_bonus, onchain_bonus) which arrive
     * from $wpdb as floats. Output is clamped to [0.0, 100.0].
     */
    public static function compute(
        float $positiveScore,
        float $negativeScore,
        float $endorsementBonus,
        float $onchainBonus
    ): float {
        $base  = (float) self::neutral() + ($positiveScore - $negativeScore) * 2.0;
        $bonus = $endorsementBonus + $onchainBonus;
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
        return 'LEAST(' . self::MAX . ', GREATEST(' . self::MIN . ', '
             . self::neutral()
             . ' + (positive_score - negative_score) * 2 + endorsement_bonus + onchain_bonus))';
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
