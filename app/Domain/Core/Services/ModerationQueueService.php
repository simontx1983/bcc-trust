<?php
/**
 * Moderation Queue Service — §K1 Phase C admin-side composer.
 *
 * Two read surfaces:
 *   - Pending queue: status=0 reports awaiting decision
 *   - Resolved tab:  status=1 (action_taken) and 2 (dismissed)
 *
 * Each row is hydrated with:
 *   - reporter (handle, display_name)
 *   - target preview (post_kind, body excerpt, author handle, timestamps)
 *   - currently_hidden flag (whether the act_id has a bcc_hidden_activities row)
 *
 * Hydration is bulk per page — N reports → 2 batched lookups (users +
 * activities) + 1 hidden-set check. Linear in page size, not in
 * (page × hops).
 *
 * Admin-only contract: the endpoint enforces capability; this service
 * trusts its caller. No viewer-permission filter.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §K1 Phase C)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ModerationQueueService
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE     = 50;

    /**
     * Status values from the schema. Keep in lockstep with
     * ContentReportRepository's status semantics.
     */
    public const STATUS_PENDING   = 0;
    public const STATUS_RESOLVED  = 1;
    public const STATUS_DISMISSED = 2;

    /** Resolve actions exposed by the admin endpoint. */
    public const ACTION_HIDE     = 'hide';
    public const ACTION_DISMISS  = 'dismiss';
    public const ACTION_RESTORE  = 'restore';

    public function __construct(
        private readonly ContentReportRepository $reportRepo,
        private readonly HiddenActivityRepository $hiddenRepo
    ) {
    }

    /**
     * Hard ceiling on reporter_handle → user_id resolution. Mirrors
     * ContentReportRepository::REPORTER_IDS_MAX so the service and the
     * repo stay in lockstep on the IN-clause cap.
     */
    private const REPORTER_HANDLE_MAX_MATCHES = 50;

    /**
     * Hydrated paginated list for the admin queue.
     *
     * `$filters` accepts the raw user inputs from the endpoint:
     *
     *   - reason           string|null  one of the report reason codes
     *   - reporter_handle  string|null  partial bcc_handle (LIKE)
     *   - post_kind        string|null  one of status|blog|review|photo|gif
     *   - since            string|null  ISO 8601 datetime
     *   - until            string|null  ISO 8601 datetime
     *
     * The service:
     *   - resolves reporter_handle → up to 50 user_ids (early-empty on 0 matches)
     *   - validates ISO 8601 datetimes and converts them to UTC MySQL strings
     *   - drops invalid filter values silently (keeps the queue useful even
     *     when the caller sends garbage; the contract enum still rejects
     *     unknown values up front)
     *   - hands a cleaned filter bag to the repo
     *
     * @param array{
     *   reason?: string|null,
     *   reporter_handle?: string|null,
     *   post_kind?: string|null,
     *   since?: string|null,
     *   until?: string|null
     * } $filters
     * @return array{
     *   items: list<array<string, mixed>>,
     *   pagination: array{
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     total_pages: int
     *   }
     * }
     */
    public function getQueue(?int $status, int $page, int $perPage, array $filters = []): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $offset  = ($page - 1) * $perPage;

        $repoFilters = $this->normalizeFilters($filters);

        // Early-empty when handle resolution returned no users — saves a
        // SQL roundtrip whose answer is guaranteed to be []/0.
        if ($repoFilters === null) {
            return [
                'items'      => [],
                'pagination' => [
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total'       => 0,
                    'total_pages' => 0,
                ],
            ];
        }

        $total = $this->reportRepo->countForAdmin($status, $repoFilters);
        if ($total === 0) {
            return [
                'items'      => [],
                'pagination' => [
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total'       => 0,
                    'total_pages' => 0,
                ],
            ];
        }

        $rows = $this->reportRepo->findForAdmin($status, $perPage, $offset, $repoFilters);

        $items = $this->hydrateRows($rows);

        return [
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Translate the endpoint-shaped filter bag into the repo-shaped one.
     *
     * Returns null when reporter_handle was provided but resolved to
     * zero users — caller treats that as a guaranteed-empty result and
     * skips both SQL queries.
     *
     * @param array{
     *   reason?: string|null,
     *   reporter_handle?: string|null,
     *   post_kind?: string|null,
     *   since?: string|null,
     *   until?: string|null
     * } $filters
     * @return array{
     *   reason: string|null,
     *   reporter_user_ids: list<int>|null,
     *   post_kind: string|null,
     *   since_mysql: string|null,
     *   until_mysql: string|null
     * }|null
     */
    private function normalizeFilters(array $filters): ?array
    {
        $reason = isset($filters['reason']) && is_string($filters['reason']) && $filters['reason'] !== ''
            ? $filters['reason']
            : null;

        $postKind = isset($filters['post_kind']) && is_string($filters['post_kind']) && $filters['post_kind'] !== ''
            ? $filters['post_kind']
            : null;

        $sinceMysql = isset($filters['since']) && is_string($filters['since'])
            ? self::isoToMysqlUtc($filters['since'])
            : null;
        $untilMysql = isset($filters['until']) && is_string($filters['until'])
            ? self::isoToMysqlUtc($filters['until'])
            : null;

        $reporterUserIds = null;
        $handleRaw       = $filters['reporter_handle'] ?? null;
        if (is_string($handleRaw) && trim($handleRaw) !== '') {
            $resolved = self::resolveHandleToUserIds(trim($handleRaw));
            if ($resolved === []) {
                // Sentinel: caller skips the SQL entirely.
                return null;
            }
            $reporterUserIds = $resolved;
        }

        return [
            'reason'            => $reason,
            'reporter_user_ids' => $reporterUserIds,
            'post_kind'         => $postKind,
            'since_mysql'       => $sinceMysql,
            'until_mysql'       => $untilMysql,
        ];
    }

    /**
     * Look up user_ids whose bcc_handle LIKEs $handle. Bounded at
     * REPORTER_HANDLE_MAX_MATCHES so the repo's IN-clause cap is never
     * exceeded.
     *
     * @return list<int>
     */
    private static function resolveHandleToUserIds(string $handle): array
    {
        // get_users with meta_compare=LIKE — the meta_value passed to
        // get_users is treated as the LIKE pattern body (caller must
        // include % wildcards if substring match is desired). We wrap it
        // ourselves for partial-match semantics so admins can type any
        // fragment of a handle.
        $pattern = '%' . $handle . '%';
        $users = get_users([
            'meta_key'     => 'bcc_handle',
            'meta_compare' => 'LIKE',
            'meta_value'   => $pattern,
            'fields'       => 'ID',
            'number'       => self::REPORTER_HANDLE_MAX_MATCHES,
        ]);

        $out = [];
        foreach ($users as $u) {
            // get_users(fields=ID) returns scalars — could be int or
            // numeric-string depending on WP version. Coerce both ways.
            if (is_numeric($u)) {
                $id = (int) $u;
                if ($id > 0) {
                    $out[] = $id;
                }
            }
        }
        return $out;
    }

    /**
     * Convert a caller-supplied ISO 8601 datetime to a 'Y-m-d H:i:s'
     * UTC string suitable for compare against bcc_content_reports.created_at.
     * Returns null when the input doesn't parse — caller drops the filter.
     */
    private static function isoToMysqlUtc(string $iso): ?string
    {
        $iso = trim($iso);
        if ($iso === '') {
            return null;
        }
        // Try strict ISO 8601 first (handles 'Z' and ±HH:MM offsets).
        try {
            $dt = new \DateTimeImmutable($iso);
        } catch (\Exception $e) {
            return null;
        }
        $dt = $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Apply an action to a single report.
     *
     * Action effects:
     *   - 'hide'    → hide the target activity + mark report status=resolved
     *   - 'dismiss' → leave activity alone + mark report status=dismissed
     *   - 'restore' → un-hide the target activity + mark report status=resolved
     *                 (used when a previously auto-hidden post is restored)
     *
     * @return array{
     *   ok: true,
     *   report_id: int,
     *   action: string,
     *   currently_hidden: bool
     * }|array{error: string, message: string}
     */
    public function resolve(int $reportId, string $action, int $resolverUserId): array
    {
        if ($reportId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Report id required.'];
        }
        if (!in_array($action, [self::ACTION_HIDE, self::ACTION_DISMISS, self::ACTION_RESTORE], true)) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Unknown action.'];
        }

        $report = $this->reportRepo->findById($reportId);
        if ($report === null) {
            return ['error' => 'bcc_not_found', 'message' => 'Report not found.'];
        }

        $targetKind = (string) $report->target_kind;
        $targetId   = (int) $report->target_id;

        if ($targetKind !== 'feed_item' || $targetId <= 0) {
            // V1 only knows feed_item targets — extend here when more
            // kinds land. Defensive: dismiss a report whose target
            // we don't understand rather than 500.
            $this->reportRepo->updateStatus($reportId, self::STATUS_DISMISSED, $resolverUserId);
            return [
                'ok'               => true,
                'report_id'        => $reportId,
                'action'           => self::ACTION_DISMISS,
                'currently_hidden' => false,
            ];
        }

        if ($action === self::ACTION_HIDE) {
            $added = $this->hiddenRepo->add(
                $targetId,
                $resolverUserId,
                'admin_action',
                $reportId
            );
            if (!$added) {
                Logger::warning('[ModerationQueueService] failed to hide target', [
                    'report_id' => $reportId,
                    'target_id' => $targetId,
                ]);
                return ['error' => 'bcc_unavailable', 'message' => 'Could not hide the post.'];
            }
            $this->reportRepo->updateStatus($reportId, self::STATUS_RESOLVED, $resolverUserId);
            do_action('bcc_content_hidden_by_admin', $targetId, $resolverUserId, $reportId);

            return [
                'ok'               => true,
                'report_id'        => $reportId,
                'action'           => self::ACTION_HIDE,
                'currently_hidden' => true,
            ];
        }

        if ($action === self::ACTION_RESTORE) {
            $this->hiddenRepo->remove($targetId);
            $this->reportRepo->updateStatus($reportId, self::STATUS_RESOLVED, $resolverUserId);
            do_action('bcc_content_restored_by_admin', $targetId, $resolverUserId, $reportId);

            return [
                'ok'               => true,
                'report_id'        => $reportId,
                'action'           => self::ACTION_RESTORE,
                'currently_hidden' => false,
            ];
        }

        // Dismiss — leave the activity alone, just close the report.
        $this->reportRepo->updateStatus($reportId, self::STATUS_DISMISSED, $resolverUserId);
        do_action('bcc_content_report_dismissed', $reportId, $resolverUserId);

        return [
            'ok'               => true,
            'report_id'        => $reportId,
            'action'           => self::ACTION_DISMISS,
            'currently_hidden' => $this->hiddenRepo->exists($targetId),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Hydration — bulk lookups
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param list<object{
     *   id: int|numeric-string,
     *   target_kind: string,
     *   target_id: int|numeric-string,
     *   reporter_user_id: int|numeric-string,
     *   reason_code: string,
     *   comment: string|null,
     *   status: int|numeric-string,
     *   created_at: string
     * }> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        // Bulk lookup the reporter ids (one query) and the target
        // act_ids (one query; only feed_item rows). All the lookups
        // ride on existing repo methods.
        $reporterIds = [];
        $actIds      = [];
        foreach ($rows as $row) {
            $reporterIds[(int) $row->reporter_user_id] = true;
            if ($row->target_kind === 'feed_item') {
                $actIds[(int) $row->target_id] = true;
            }
        }

        $reporters = self::lookupUsersBulk(array_keys($reporterIds));
        $activities = self::lookupActivitiesBulk(array_keys($actIds));

        // Hidden-set probe is a single cached call.
        $hiddenSet = [];
        foreach ($this->hiddenRepo->getAllHiddenIds() as $hid) {
            $hiddenSet[$hid] = true;
        }

        $items = [];
        foreach ($rows as $row) {
            $reporterId = (int) $row->reporter_user_id;
            $actId      = (int) $row->target_id;
            $statusInt  = (int) $row->status;

            $items[] = [
                'id'              => (int) $row->id,
                'status'          => $statusInt,
                'status_label'    => self::statusLabel($statusInt),
                'reason_code'     => (string) $row->reason_code,
                'comment'         => is_string($row->comment) ? $row->comment : '',
                'created_at'      => self::toIso8601((string) $row->created_at),
                'reporter'        => $reporters[$reporterId] ?? self::fallbackUser($reporterId),
                'target'          => self::shapeTarget($row, $activities[$actId] ?? null),
                'currently_hidden'=> isset($hiddenSet[$actId]),
            ];
        }
        return $items;
    }

    /**
     * @param list<int> $userIds
     * @return array<int, array{user_id: int, handle: string, display_name: string, profile_url: string}>
     */
    private static function lookupUsersBulk(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $users = get_users([
            'include' => $userIds,
            'fields'  => ['ID', 'display_name', 'user_login'],
            'number'  => count($userIds),
        ]);

        $out = [];
        foreach ($users as $u) {
            $id        = (int) $u->ID;
            $handleRaw = get_user_meta($id, 'bcc_handle', true);
            $handle    = is_string($handleRaw) && $handleRaw !== '' ? $handleRaw : (string) $u->user_login;
            $out[$id]  = [
                'user_id'      => $id,
                'handle'       => $handle,
                'display_name' => $u->display_name !== '' ? (string) $u->display_name : (string) $u->user_login,
                'profile_url'  => $handle !== '' ? '/u/' . $handle : '',
            ];
        }
        return $out;
    }

    /**
     * Bulk lookup peepso_activities rows by act_id. The repo doesn't
     * expose a multi-id read; we walk getById per id since blogs +
     * status posts are the V1 targets and the page size is bounded
     * (≤ MAX_PER_PAGE = 50). When a richer multi-id read lands in
     * bcc-core, swap this in.
     *
     * @param list<int> $actIds
     * @return array<int, object{act_id: int|numeric-string, act_user_id: int|numeric-string, act_module_id: string, act_external_id: int|numeric-string, act_time: string}>
     */
    private static function lookupActivitiesBulk(array $actIds): array
    {
        $out = [];
        foreach ($actIds as $actId) {
            if ($actId <= 0) {
                continue;
            }
            $row = PeepSoActivityRepository::getById($actId);
            if ($row !== null) {
                $out[$actId] = $row;
            }
        }
        return $out;
    }

    /**
     * @param object{
     *   target_kind: string,
     *   target_id: int|numeric-string
     * } $report
     * @return array<string, mixed>
     */
    private static function shapeTarget(
        object $report,
        ?object $activity
    ): array {
        $base = [
            'target_kind' => (string) $report->target_kind,
            'target_id'   => (int) $report->target_id,
            'preview'     => '',
            'post_kind'   => null,
            'author_id'   => null,
            'posted_at'   => null,
        ];
        if ($activity === null) {
            return $base;
        }

        $module = isset($activity->act_module_id) ? (string) $activity->act_module_id : '';
        $base['post_kind'] = $module;
        $base['author_id'] = isset($activity->act_user_id) ? (int) $activity->act_user_id : null;
        $base['posted_at'] = isset($activity->act_time)
            ? self::toIso8601((string) $activity->act_time)
            : null;

        // Preview text: only available cheaply for status / blog kinds
        // where the wp_post is the body source. Other kinds get an
        // empty preview — admin can click through to see the full row.
        if ($module === 'status' || $module === 'blog') {
            $postId = isset($activity->act_external_id) ? (int) $activity->act_external_id : 0;
            if ($postId > 0) {
                $post = get_post($postId);
                if ($post instanceof \WP_Post) {
                    $excerpt = (string) $post->post_excerpt;
                    $content = (string) $post->post_content;
                    $base['preview'] = $excerpt !== '' ? $excerpt : self::truncate($content, 240);
                }
            }
        }

        return $base;
    }

    private static function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }

    /**
     * @return array{user_id: int, handle: string, display_name: string, profile_url: string}
     */
    private static function fallbackUser(int $userId): array
    {
        return [
            'user_id'      => $userId,
            'handle'       => '',
            'display_name' => '(deleted user)',
            'profile_url'  => '',
        ];
    }

    private static function statusLabel(int $status): string
    {
        return match ($status) {
            self::STATUS_PENDING   => 'PENDING',
            self::STATUS_RESOLVED  => 'RESOLVED',
            self::STATUS_DISMISSED => 'DISMISSED',
            default                => 'UNKNOWN',
        };
    }

    private static function toIso8601(string $mysqlDatetime): string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
