<?php
/**
 * Shift Log Service — composes the GET /bcc/v1/users/:handle/shift-log
 * view-model per contract §4.4 (the "Shift Log" 52-week phosphor grid
 * from glossary §6).
 *
 * Single PeepSo source: queries peepso_activities (read-only via
 * bcc-core's PeepSoActivityRepository). Server-side intensity bucketing
 * keeps the §A2 "no business logic on frontend" rule honest — the
 * client just renders the named bucket.
 *
 * Phase 1 scope (intentional):
 *   - cells[].kinds returns raw peepso_activities module IDs.
 *   - totals_by_kind returns the same raw module IDs as keys.
 *   - Reaction-derived kinds (vouch, stand_behind) are NOT included
 *     yet — they live in peepso_reactions, not peepso_activities.
 *     A merging aggregator will land alongside the reaction service.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoActivityRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ShiftLogService
{
    private const MAX_WEEKS = 104;

    /**
     * Build the 52-week shift-log payload for one user.
     *
     * @return array{
     *   weeks: int,
     *   as_of: string,
     *   cells: list<array{date: string, count: int, intensity: string, kinds: list<string>}>,
     *   totals_by_kind: array<string, int>
     * }
     */
    public function getShiftLog(int $userId, int $weeks): array
    {
        $weeks      = max(1, min(self::MAX_WEEKS, $weeks));
        $days       = $weeks * 7;
        $now        = time();
        $sinceTs    = $now - ($days * DAY_IN_SECONDS);
        $sinceMysql = gmdate('Y-m-d 00:00:00', $sinceTs);

        // Over-fetch slightly to absorb timezone drift + tests near midnight.
        $byDay    = PeepSoActivityRepository::aggregateByDay($userId, $sinceMysql, $days + 7);
        $byModule = PeepSoActivityRepository::aggregateByModule($userId, $sinceMysql);

        $cells = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date  = gmdate('Y-m-d', $now - ($offset * DAY_IN_SECONDS));
            $entry = $byDay[$date] ?? null;

            $count = $entry !== null ? $entry['count'] : 0;
            $kinds = $entry !== null ? $entry['kinds'] : [];

            $cells[] = [
                'date'      => $date,
                'count'     => $count,
                'intensity' => self::bucketIntensity($count),
                'kinds'     => $kinds,
            ];
        }

        return [
            'weeks'          => $weeks,
            'as_of'          => gmdate('Y-m-d\TH:i:s\Z', $now),
            'cells'          => $cells,
            'totals_by_kind' => $byModule,
        ];
    }

    /**
     * Server-side intensity bucketing. The frontend just renders the
     * named bucket as a CSS class — never derives the level from count
     * (per §A2 / §L5).
     *
     * Bucket thresholds chosen to keep most active days at 'med' or
     * 'high'; 'peak' reserved for outlier days (8+ activities).
     */
    private static function bucketIntensity(int $count): string
    {
        if ($count <= 0) {
            return 'none';
        }
        if ($count === 1) {
            return 'low';
        }
        if ($count <= 3) {
            return 'med';
        }
        if ($count <= 7) {
            return 'high';
        }
        return 'peak';
    }
}
