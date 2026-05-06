<?php
/**
 * GroupActivityHeatService — turns raw post counts into the API
 * `activity` block (heat / posts_last_7d / last_activity_at).
 *
 * The frontend's "ghost-town protection" surface: users see heat at a
 * glance and avoid sleepy rooms. Bucketing is server-authoritative so
 * thresholds can be re-tuned (via the `bcc_group_heat_thresholds`
 * filter) without a frontend release.
 *
 * Default thresholds:
 *   cold:  0–2 posts in the last 7 days
 *   warm:  3–9 posts
 *   hot:   10+ posts
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoGroupRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupActivityHeatService {

    public const HEAT_COLD = 'cold';
    public const HEAT_WARM = 'warm';
    public const HEAT_HOT  = 'hot';

    /**
     * Batch fetch the activity block for a set of groups.
     *
     * Groups with no recent activity get a zero-count block with
     * `heat = cold` and `last_activity_at = null` — the field always
     * exists in the response for shape consistency.
     *
     * `heat_label` is the server-authoritative display string for the
     * heat bucket. The frontend renders it verbatim per §A2 / §S — no
     * client-side `heat === "hot" ? "Hot" : ...` mapping.
     *
     * @param int[] $groupIds
     * @return array<int, array{
     *     posts_last_7d: int,
     *     last_activity_at: string|null,
     *     heat: string,
     *     heat_label: string
     * }>
     */
    public function forGroups(array $groupIds): array {
        if ($groupIds === []) {
            return [];
        }

        $raw = PeepSoGroupRepository::getActivityHeat($groupIds);
        [$warmAt, $hotAt] = $this->thresholds();

        $out = [];
        foreach ($groupIds as $groupId) {
            $row = $raw[$groupId] ?? null;
            $posts = $row !== null ? (int) $row->posts : 0;
            $lastAt = $row !== null && $row->last_at !== null
                ? $this->toIso8601((string) $row->last_at)
                : null;
            $heat = $this->bucket($posts, $warmAt, $hotAt);

            $out[$groupId] = [
                'posts_last_7d'    => $posts,
                'last_activity_at' => $lastAt,
                'heat'             => $heat,
                'heat_label'       => $this->heatLabel($heat),
            ];
        }
        return $out;
    }

    /**
     * Server-authoritative display string for a heat bucket. Filterable
     * via `bcc_group_heat_label` so the copy can be re-tuned without a
     * frontend release. Defaults match the in-tree Floor design vocab —
     * "Quiet" reads less judgmental than "Cold" while still steering
     * users to active rooms.
     */
    private function heatLabel(string $heat): string {
        $defaults = [
            self::HEAT_HOT  => 'Hot',
            self::HEAT_WARM => 'Warm',
            self::HEAT_COLD => 'Quiet',
        ];
        $label = $defaults[$heat] ?? 'Quiet';

        /** @var string $filtered */
        $filtered = apply_filters('bcc_group_heat_label', $label, $heat);
        return (string) $filtered;
    }

    private function toIso8601(string $mysqlGmt): ?string {
        // Guard against MySQL zero-dates and any non-parseable input.
        // Returning null > fabricating "now" via fallback — a corrupted
        // timestamp must not surface as "active just now" to the user.
        if ($mysqlGmt === '' || $mysqlGmt === '0000-00-00 00:00:00') {
            return null;
        }
        $ts = strtotime($mysqlGmt . ' UTC');
        if ($ts === false) {
            return null;
        }
        return gmdate('c', $ts);
    }

    /**
     * @return array{0: int, 1: int} [warm_at, hot_at]
     */
    private function thresholds(): array {
        /** @var array{0?: int, 1?: int}|array<string, int> $raw */
        $raw = apply_filters('bcc_group_heat_thresholds', ['warm' => 3, 'hot' => 10]);

        $warmAt = isset($raw['warm']) ? (int) $raw['warm'] : (int) ($raw[0] ?? 3);
        $hotAt  = isset($raw['hot'])  ? (int) $raw['hot']  : (int) ($raw[1] ?? 10);

        if ($warmAt < 1) { $warmAt = 1; }
        if ($hotAt <= $warmAt) { $hotAt = $warmAt + 1; }

        return [$warmAt, $hotAt];
    }

    private function bucket(int $posts, int $warmAt, int $hotAt): string {
        if ($posts >= $hotAt)  { return self::HEAT_HOT; }
        if ($posts >= $warmAt) { return self::HEAT_WARM; }
        return self::HEAT_COLD;
    }
}
