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

use BCC\Core\Feed\FeedItemNormalizer;
use BCC\Core\Log\Logger;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Core\Repositories\GifRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\PostShortcodeRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Support\CardUrlMap;

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

    /**
     * Bounded recovery affordance for the §K1 admin queue.
     *
     * IMPORTANT — pattern-registry.md "Moderation recovery affordances":
     *   Undo is a moderation recovery affordance, NOT a historical
     *   correctness mechanism.
     *
     * The transient stores everything the inverse action needs and
     * NOTHING ELSE. It is single-use; it self-consumes (immediately
     * deleted on read regardless of whether the inverse succeeds —
     * see `consumeUndoToken()`). It is short-lived (UNDO_TTL_SECONDS).
     * It validates current-state-matches-expected-post-action-state
     * before executing; on any divergence it FAILS CLOSED with
     * `bcc_undo_stale_state`. That stale-state guard is what prevents
     * recovery from becoming race-condition stomping.
     *
     * Failure modes — ALL fail closed:
     *   - missing transient        → bcc_undo_expired
     *   - expired transient TTL    → bcc_undo_expired (same path; WP auto-deletes)
     *   - token bound to a different admin → bcc_undo_forbidden
     *   - current state diverges from expected → bcc_undo_stale_state
     *   - second consumption attempt → bcc_undo_already_used
     *
     * Resist later pressure to grow this into a multi-step undo stack,
     * a persistence-across-reloads system, a generalised /admin/undo
     * endpoint, or any retry worker.
     */
    private const UNDO_TRANSIENT_PREFIX = 'bcc_mod_undo_';
    private const UNDO_TTL_SECONDS      = 30;

    public function __construct(
        private readonly ContentReportRepository $reportRepo,
        private readonly HiddenActivityRepository $hiddenRepo,
        private readonly PostShortcodeRepository $shortcodeRepo,
        private readonly GifRepository $gifRepo
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
     *   currently_hidden: bool,
     *   undo: array{token: string, expires_at: string, ttl_seconds: int}|null
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
            // we don't understand rather than 500. NO undo token is
            // issued for this fallback path — the original action was
            // not the one the admin selected, so an inverse would be
            // misleading. Audit-log the defensive call so the trail
            // is unambiguous.
            $this->reportRepo->updateStatus($reportId, self::STATUS_DISMISSED, $resolverUserId);
            AuditLogger::log(
                'admin_dismiss_report_defensive',
                $reportId,
                ['target_kind' => $targetKind, 'requested_action' => $action],
                'content_report',
                $resolverUserId
            );
            return [
                'ok'               => true,
                'report_id'        => $reportId,
                'action'           => self::ACTION_DISMISS,
                'currently_hidden' => false,
                'undo'             => null,
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
            AuditLogger::log(
                'admin_hide_report',
                $reportId,
                ['target_id' => $targetId],
                'content_report',
                $resolverUserId
            );
            do_action('bcc_content_hidden_by_admin', $targetId, $resolverUserId, $reportId);

            $undo = $this->issueUndoToken(
                $reportId,
                $resolverUserId,
                self::ACTION_HIDE,
                self::STATUS_RESOLVED,
                true,
                $targetId,
                null, // hide-undo doesn't need prior hidden-row metadata
                null
            );

            return [
                'ok'               => true,
                'report_id'        => $reportId,
                'action'           => self::ACTION_HIDE,
                'currently_hidden' => true,
                'undo'             => $undo,
            ];
        }

        if ($action === self::ACTION_RESTORE) {
            // Capture the prior hidden row BEFORE remove() deletes it.
            // The undo path uses these to re-hide with the original
            // hider's identity + reason, not the undoing admin's.
            $prior = $this->hiddenRepo->findByActId($targetId);
            $priorHiderUserId = $prior !== null && isset($prior->hidden_by_user_id) ? (int) $prior->hidden_by_user_id : null;
            $priorReasonCode  = $prior !== null && isset($prior->reason_code)       ? (string) $prior->reason_code : null;

            $this->hiddenRepo->remove($targetId);
            $this->reportRepo->updateStatus($reportId, self::STATUS_RESOLVED, $resolverUserId);
            AuditLogger::log(
                'admin_restore_report',
                $reportId,
                ['target_id' => $targetId],
                'content_report',
                $resolverUserId
            );
            do_action('bcc_content_restored_by_admin', $targetId, $resolverUserId, $reportId);

            $undo = $this->issueUndoToken(
                $reportId,
                $resolverUserId,
                self::ACTION_RESTORE,
                self::STATUS_RESOLVED,
                false,
                $targetId,
                $priorHiderUserId,
                $priorReasonCode
            );

            return [
                'ok'               => true,
                'report_id'        => $reportId,
                'action'           => self::ACTION_RESTORE,
                'currently_hidden' => false,
                'undo'             => $undo,
            ];
        }

        // Dismiss — leave the activity alone, just close the report.
        $this->reportRepo->updateStatus($reportId, self::STATUS_DISMISSED, $resolverUserId);
        AuditLogger::log(
            'admin_dismiss_report',
            $reportId,
            ['target_id' => $targetId],
            'content_report',
            $resolverUserId
        );
        do_action('bcc_content_report_dismissed', $reportId, $resolverUserId);

        $undo = $this->issueUndoToken(
            $reportId,
            $resolverUserId,
            self::ACTION_DISMISS,
            self::STATUS_DISMISSED,
            $this->hiddenRepo->exists($targetId),
            $targetId,
            null,
            null
        );

        return [
            'ok'               => true,
            'report_id'        => $reportId,
            'action'           => self::ACTION_DISMISS,
            'currently_hidden' => $this->hiddenRepo->exists($targetId),
            'undo'             => $undo,
        ];
    }

    /**
     * Reverse a moderation action issued in the last UNDO_TTL_SECONDS.
     *
     * MUST FAIL CLOSED on any uncertainty (see class-level constants
     * doc above). The four explicit failure codes (`bcc_undo_expired`,
     * `bcc_undo_forbidden`, `bcc_undo_stale_state`, `bcc_undo_already_used`)
     * collapse to: this token is no longer the right key for the
     * report's current state — the admin must re-evaluate visually.
     *
     * The transient is consumed (deleted) immediately on read, BEFORE
     * the inverse runs. That single-use guarantee survives even a
     * failed inverse — the alternative (delete only on success) would
     * let a misbehaving client retry forever.
     *
     * @return array{
     *   ok: true,
     *   report_id: int,
     *   undone_action: string,
     *   currently_hidden: bool
     * }|array{error: string, message: string}
     */
    public function undo(string $token, int $adminUserId): array
    {
        if ($token === '') {
            return ['error' => 'bcc_invalid_request', 'message' => 'Undo token required.'];
        }

        $undo = $this->consumeUndoToken($token);
        if ($undo === null) {
            // Either never existed or already consumed / expired. Treat
            // the two cases identically — leaking the difference would
            // let a caller distinguish "you already undid this" from
            // "you waited too long" and that distinction has zero
            // operational value while being a small information leak.
            return ['error' => 'bcc_undo_expired', 'message' => 'Undo window expired or token already used.'];
        }

        if ((int) ($undo['admin_user_id'] ?? 0) !== $adminUserId) {
            return ['error' => 'bcc_undo_forbidden', 'message' => 'This undo token belongs to a different admin.'];
        }

        $reportId = (int) ($undo['report_id'] ?? 0);
        if ($reportId <= 0) {
            return ['error' => 'bcc_undo_expired', 'message' => 'Undo token is malformed.'];
        }

        $report = $this->reportRepo->findById($reportId);
        if ($report === null) {
            // The report disappeared between forward and undo. Rare;
            // would require concurrent deletion. Treat as stale.
            return ['error' => 'bcc_undo_stale_state', 'message' => 'Report no longer exists.'];
        }

        // Stale-state guard — load-bearing. If anyone else acted on
        // this report between the forward action and now, refuse and
        // tell the admin to re-evaluate.
        $expectedStatus     = (int) ($undo['expected_status'] ?? -1);
        $expectedHidden     = (bool) ($undo['expected_currently_hidden'] ?? false);
        $currentStatus      = (int) $report->status;
        $targetId           = (int) $report->target_id;
        $currentlyHiddenNow = $targetId > 0 && $this->hiddenRepo->exists($targetId);

        if ($currentStatus !== $expectedStatus || $currentlyHiddenNow !== $expectedHidden) {
            AuditLogger::log(
                'admin_undo_stale_state',
                $reportId,
                [
                    'action'          => (string) ($undo['action'] ?? ''),
                    'expected_status' => $expectedStatus,
                    'current_status'  => $currentStatus,
                    'expected_hidden' => $expectedHidden,
                    'current_hidden'  => $currentlyHiddenNow,
                ],
                'content_report',
                $adminUserId
            );
            return ['error' => 'bcc_undo_stale_state', 'message' => 'Another moderator has acted on this report.'];
        }

        $action = (string) ($undo['action'] ?? '');

        if ($action === self::ACTION_HIDE) {
            // Inverse: un-hide the activity AND return the report to
            // the queue (PENDING, resolved_at NULL).
            $this->hiddenRepo->remove($targetId);
            $this->reportRepo->setPending($reportId);
            AuditLogger::log(
                'admin_undo_hide',
                $reportId,
                ['target_id' => $targetId],
                'content_report',
                $adminUserId
            );
            do_action('bcc_content_moderation_undone', $reportId, $adminUserId, self::ACTION_HIDE);

            return [
                'ok'               => true,
                'report_id'        => $reportId,
                'undone_action'    => self::ACTION_HIDE,
                'currently_hidden' => false,
            ];
        }

        if ($action === self::ACTION_DISMISS) {
            // Inverse: just flip the report back to PENDING. No
            // activity state to restore — dismiss never touched the
            // activity in the first place.
            $this->reportRepo->setPending($reportId);
            AuditLogger::log(
                'admin_undo_dismiss',
                $reportId,
                ['target_id' => $targetId],
                'content_report',
                $adminUserId
            );
            do_action('bcc_content_moderation_undone', $reportId, $adminUserId, self::ACTION_DISMISS);

            return [
                'ok'               => true,
                'report_id'        => $reportId,
                'undone_action'    => self::ACTION_DISMISS,
                'currently_hidden' => $currentlyHiddenNow,
            ];
        }

        if ($action === self::ACTION_RESTORE) {
            // Inverse: re-hide the activity AND return the report to
            // PENDING. Re-attribute the hidden row to the undoing
            // admin with reason_code='admin_undo_restore'; the prior
            // hider's identity is not preserved (this is recovery,
            // not historical reconstruction — see pattern-registry
            // "Moderation recovery affordances"). The transient
            // captured the prior values only as forensic metadata;
            // they are logged but not re-stamped onto the row.
            $priorHider  = isset($undo['prior_hider_user_id']) ? (int) $undo['prior_hider_user_id'] : 0;
            $priorReason = isset($undo['prior_reason_code'])   ? (string) $undo['prior_reason_code'] : '';

            $this->hiddenRepo->add(
                $targetId,
                $adminUserId,
                'admin_undo_restore',
                $reportId
            );
            $this->reportRepo->setPending($reportId);
            AuditLogger::log(
                'admin_undo_restore',
                $reportId,
                [
                    'target_id'           => $targetId,
                    'prior_hider_user_id' => $priorHider,
                    'prior_reason_code'   => $priorReason,
                ],
                'content_report',
                $adminUserId
            );
            do_action('bcc_content_moderation_undone', $reportId, $adminUserId, self::ACTION_RESTORE);

            return [
                'ok'               => true,
                'report_id'        => $reportId,
                'undone_action'    => self::ACTION_RESTORE,
                'currently_hidden' => true,
            ];
        }

        // Unknown action stored in the transient — should be impossible
        // (issueUndoToken validates), so fail loud rather than guess.
        Logger::warning('[ModerationQueueService] undo token with unknown action', [
            'report_id' => $reportId,
            'action'    => $action,
        ]);
        return ['error' => 'bcc_undo_expired', 'message' => 'Undo token is malformed.'];
    }

    /**
     * Build + store a 30s single-use undo descriptor. Returns the
     * client-visible {token, expires_at, ttl_seconds} block or null
     * when the transient write fails (forward action still committed —
     * the affordance just doesn't render).
     *
     * @return array{token: string, expires_at: string, ttl_seconds: int}|null
     */
    private function issueUndoToken(
        int $reportId,
        int $adminUserId,
        string $action,
        int $expectedStatus,
        bool $expectedCurrentlyHidden,
        int $targetActId,
        ?int $priorHiderUserId,
        ?string $priorReasonCode
    ): ?array {
        // 32 hex chars = 128 bits of randomness. wp_generate_password
        // with special_chars=false yields [A-Za-z0-9]; bin2hex(random_bytes(16))
        // is equally strong and shorter to validate. We use the latter
        // so the token character set is hex-only (easier to log + grep).
        try {
            $token = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            Logger::warning('[ModerationQueueService] random_bytes failed; skipping undo token', [
                'report_id' => $reportId,
            ]);
            return null;
        }

        $now = time();
        $payload = [
            'token'                     => $token,
            'report_id'                 => $reportId,
            'admin_user_id'             => $adminUserId,
            'action'                    => $action,
            'expected_status'           => $expectedStatus,
            'expected_currently_hidden' => $expectedCurrentlyHidden,
            'target_act_id'             => $targetActId,
            'prior_hider_user_id'       => $priorHiderUserId,
            'prior_reason_code'         => $priorReasonCode,
            'issued_at'                 => $now,
        ];

        $stored = set_transient(self::UNDO_TRANSIENT_PREFIX . $token, $payload, self::UNDO_TTL_SECONDS);
        if (!$stored) {
            // Forward action already committed; just no undo affordance.
            // NEVER attempt to roll back the forward action on token-write
            // failure — that turns a recovery affordance into a foot-cannon.
            Logger::warning('[ModerationQueueService] failed to persist undo token; affordance suppressed', [
                'report_id' => $reportId,
            ]);
            return null;
        }

        return [
            'token'       => $token,
            'expires_at'  => gmdate('Y-m-d\TH:i:s\Z', $now + self::UNDO_TTL_SECONDS),
            'ttl_seconds' => self::UNDO_TTL_SECONDS,
        ];
    }

    /**
     * Read + delete the undo transient atomically.
     *
     * The delete is unconditional and happens BEFORE the inverse
     * action runs — single-use, even on inverse-action failure. The
     * alternative (delete-on-success) would allow infinite retries of
     * a failing inverse, which the codebase has no operational reason
     * to support.
     *
     * @return array<string, mixed>|null
     */
    private function consumeUndoToken(string $token): ?array
    {
        // Cheap structural validation so a garbage string doesn't
        // become a transient-key probe vector.
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        $key = self::UNDO_TRANSIENT_PREFIX . $token;
        $payload = get_transient($key);
        delete_transient($key);

        if (!is_array($payload)) {
            return null;
        }
        return $payload;
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

        $activities = self::lookupActivitiesBulk(array_keys($actIds));

        // Target-author handles ride the same bulk user lookup as the
        // reporters — needed to compose the post permalink below.
        $userIds = $reporterIds;
        foreach ($activities as $activity) {
            $authorId = isset($activity->act_user_id) ? (int) $activity->act_user_id : 0;
            if ($authorId > 0) {
                $userIds[$authorId] = true;
            }
        }
        $users = self::lookupUsersBulk(array_keys($userIds));

        // Post permalinks: one batched ensureForActIds call (codes mint
        // lazily, same as FeedHydrationPipeline::hydrateLinks) + the
        // author handle → CardUrlMap::postUrl, the single LOCKED
        // permalink composer. Rows whose code or handle can't resolve
        // keep an empty post_url — degraded beats broken.
        $codesByAct = $activities === []
            ? []
            : $this->shortcodeRepo->ensureForActIds(array_keys($activities));

        // GIF discrimination: status and GIF posts share act_module_id=1;
        // a `peepso_giphy` post-meta marks the GIF ones (same layer-2
        // override as FeedHydrationPipeline). One batched read.
        $statusPostIds = [];
        foreach ($activities as $activity) {
            $module = isset($activity->act_module_id) ? (string) $activity->act_module_id : '';
            $postId = isset($activity->act_external_id) ? (int) $activity->act_external_id : 0;
            if ($postId > 0 && (FeedItemNormalizer::MODULE_TO_KIND[$module] ?? '') === 'status') {
                $statusPostIds[] = $postId;
            }
        }
        $gifByPostId = $statusPostIds === [] ? [] : $this->gifRepo->findByPostIds($statusPostIds);

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

            $activity     = $activities[$actId] ?? null;
            $authorId     = $activity !== null && isset($activity->act_user_id) ? (int) $activity->act_user_id : 0;
            $authorHandle = $users[$authorId]['handle'] ?? '';
            $shortCode    = $codesByAct[$actId] ?? null;

            $items[] = [
                'id'              => (int) $row->id,
                'status'          => $statusInt,
                'status_label'    => self::statusLabel($statusInt),
                'reason_code'     => (string) $row->reason_code,
                'comment'         => is_string($row->comment) ? $row->comment : '',
                'created_at'      => self::toIso8601((string) $row->created_at),
                'reporter'        => $users[$reporterId] ?? self::fallbackUser($reporterId),
                'target'          => self::shapeTarget($row, $activity, $authorHandle, $shortCode, $gifByPostId),
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
     * Queue post-kind vocabulary: the semantic feed kinds, with the
     * feed's 'blog_excerpt' collapsed to the queue/endpoint enum value
     * 'blog'. Kept as a tiny rename map (not a parallel module map) so
     * FeedItemNormalizer::MODULE_TO_KIND stays the single module→kind
     * authority.
     *
     * @var array<string, string>
     */
    private const FEED_KIND_TO_QUEUE_KIND = [
        'blog_excerpt' => 'blog',
    ];

    /**
     * @param object{
     *   target_kind: string,
     *   target_id: int|numeric-string
     * } $report
     * @param array<int, string> $gifByPostId peepso_giphy map (post_id → url)
     * @return array<string, mixed>
     */
    private static function shapeTarget(
        object $report,
        ?object $activity,
        string $authorHandle = '',
        ?string $shortCode = null,
        array $gifByPostId = []
    ): array {
        $base = [
            'target_kind' => (string) $report->target_kind,
            'target_id'   => (int) $report->target_id,
            'preview'     => '',
            'post_kind'   => null,
            'post_url'    => '',
            'author_id'   => null,
            'posted_at'   => null,
        ];
        if ($activity === null) {
            return $base;
        }

        // Semantic kind via the canonical module→kind map (bcc-core's
        // FeedItemNormalizer) — NOT the raw numeric act_module_id. The
        // raw id ("1") leaked into the response pre-2026-07-21, which
        // broke the queue's kind display, preview branch, and the
        // POST KIND filter chips simultaneously.
        $module   = isset($activity->act_module_id) ? (string) $activity->act_module_id : '';
        $feedKind = FeedItemNormalizer::MODULE_TO_KIND[$module] ?? 'status';
        $postId   = isset($activity->act_external_id) ? (int) $activity->act_external_id : 0;

        // Layer-2 GIF promotion — status and GIF share module 1; the
        // peepso_giphy post-meta discriminates (same override as
        // FeedHydrationPipeline).
        if ($feedKind === 'status' && $postId > 0 && isset($gifByPostId[$postId])) {
            $feedKind = 'gif';
        }

        $base['post_kind'] = self::FEED_KIND_TO_QUEUE_KIND[$feedKind] ?? $feedKind;
        $base['author_id'] = isset($activity->act_user_id) ? (int) $activity->act_user_id : null;
        $base['posted_at'] = isset($activity->act_time)
            ? self::toIso8601((string) $activity->act_time)
            : null;

        if ($authorHandle !== '' && $shortCode !== null && $shortCode !== '') {
            $base['post_url'] = CardUrlMap::postUrl($authorHandle, $shortCode);
        }

        // Preview text: available cheaply where the wp_post is the body
        // source — status, blog, and gif (a status post whose text may
        // legitimately be empty). Other kinds get an empty preview —
        // admin can click through via post_url to view in full.
        if (in_array($feedKind, ['status', 'blog_excerpt', 'gif'], true) && $postId > 0) {
            $post = get_post($postId);
            if ($post instanceof \WP_Post) {
                $excerpt = (string) $post->post_excerpt;
                $content = (string) $post->post_content;
                $base['preview'] = $excerpt !== '' ? $excerpt : self::truncate($content, 240);
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
