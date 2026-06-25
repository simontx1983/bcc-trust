<?php
/**
 * RetentionHealthSnapshot — projects retention-table row counts + an
 * "overgrown" alarm into /system/health (Phase 4 ops-visibility).
 *
 * Mirrors the existing Helius dedup overgrown alarm, generalised to the
 * retention-bounded tables (score_events, fraud_analysis, fingerprints,
 * patterns, content_reports). Contributed via the canonical
 * `bcc_system_health` filter; threshold logic is a pure, unit-tested
 * function. Counts come from RetentionHealthRepository (§1 — COUNT lives
 * in the Repository, not here).
 *
 * The threshold is a CONSERVATIVE runaway-detector, not a capacity gauge:
 * at testnet volume every retention table is well under it, so it only
 * fires when a table is clearly unbounded (cleanup stalled for weeks). Tune
 * per-table from real volume data (capacity-model.md) once it exists.
 *
 * @package BCC\Trust\Core\Services
 * @since Phase 4 ops-visibility (2026-06-25)
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\RetentionHealthRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type TableHealth array{rows: int, threshold: int, overgrown: bool}
 */
final class RetentionHealthSnapshot
{
    /**
     * Conservative default alarm watermark applied to every retention table.
     * Overridable via the `BCC_RETENTION_ALARM_THRESHOLD` constant. Chosen so
     * testnet volumes never false-positive while a months-stalled cleanup does.
     */
    public const DEFAULT_ALARM_THRESHOLD = 250000;

    /**
     * bcc_system_health filter contributor. Gathers live counts, defers the
     * rules to the pure evaluate().
     *
     * @param array<string, mixed> $health
     * @return array<string, mixed>
     */
    public static function contribute(array $health): array
    {
        $counts    = (new RetentionHealthRepository())->rowCounts();
        $threshold = self::threshold();

        $thresholds = [];
        foreach (array_keys($counts) as $table) {
            $thresholds[$table] = $threshold;
        }

        $health['retention'] = self::evaluate($counts, $thresholds);

        return $health;
    }

    /** Effective alarm threshold (constant override → default). */
    private static function threshold(): int
    {
        if (defined('BCC_RETENTION_ALARM_THRESHOLD')) {
            $v = (int) constant('BCC_RETENTION_ALARM_THRESHOLD');
            if ($v > 0) {
                return $v;
            }
        }
        return self::DEFAULT_ALARM_THRESHOLD;
    }

    /**
     * Pure overgrown evaluation — no WP, no DB.
     *
     * @param array<string, int> $counts      table => row count
     * @param array<string, int> $thresholds  table => alarm watermark
     * @return array{summary: array{total: int, overgrown: int, has_overgrown: bool}, tables: array<string, TableHealth>}
     */
    public static function evaluate(array $counts, array $thresholds): array
    {
        $tables    = [];
        $overgrown = 0;

        foreach ($counts as $table => $rows) {
            $limit  = $thresholds[$table] ?? self::DEFAULT_ALARM_THRESHOLD;
            $isOver = $rows > $limit;
            if ($isOver) {
                $overgrown++;
            }
            $tables[$table] = [
                'rows'      => $rows,
                'threshold' => $limit,
                'overgrown' => $isOver,
            ];
        }

        return [
            'summary' => [
                'total'         => count($tables),
                'overgrown'     => $overgrown,
                'has_overgrown' => $overgrown > 0,
            ],
            'tables' => $tables,
        ];
    }
}
