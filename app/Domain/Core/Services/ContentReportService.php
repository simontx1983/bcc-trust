<?php
/**
 * Content Report Service — §K1 Phase B write side.
 *
 * Owns the validate → throttle → insert → §A3 emit pipeline for
 * `POST /me/reports`. Phase C subscribers (auto-hide threshold,
 * admin-queue notifier) attach to the same `bcc_content_reported`
 * event.
 *
 * V1 reason codes (locked here — single source of truth for both
 * server validation and the frontend reason picker):
 *   spam · harassment · hate · violence · misinformation · other
 *
 * The "other" code requires a non-empty comment; the rest accept
 * an optional comment up to COMMENT_MAX_LENGTH.
 *
 * V1 target kinds:
 *   feed_item — peepso_activities.act_id (the canonical post identifier
 *               the FeedItem.external_id surfaces is module-specific;
 *               the act_id is what the moderator queue + threshold
 *               counter need)
 *
 * Self-report rejection: the endpoint resolves the target post's
 * author and rejects when reporter == author. No "report your own
 * post" path; that's a metric, not a moderation signal.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §K1 Phase B)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Core\Security\Throttle;
use BCC\Trust\Core\Repositories\ContentReportRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentReportService
{
    /** Optional free-text. Capped server-side; UI mirrors. */
    public const COMMENT_MAX_LENGTH = 500;

    /** §K1 reason taxonomy — must stay in lockstep with the frontend picker. */
    public const REASON_CODES = [
        'spam',
        'harassment',
        'hate',
        'violence',
        'misinformation',
        'other',
    ];

    /** Reasons that REQUIRE a comment (otherwise 'other' is meaningless). */
    private const REASONS_REQUIRING_COMMENT = ['other'];

    /** V1 target kinds — currently only feed items. */
    public const TARGET_KINDS = ['feed_item'];

    public function __construct(
        private readonly ContentReportRepository $repo
    ) {
    }

    /**
     * Validate + persist a single report. Idempotent: a duplicate report
     * from the same user against the same target returns ok=true with
     * status='existing' (UNIQUE constraint catches it; no row inserted).
     *
     * @return array{
     *   ok: true,
     *   report_id: int,
     *   status: 'created'|'existing'
     * }|array{error: string, message: string}
     */
    public function fileReport(
        int $reporterUserId,
        string $targetKind,
        int $targetId,
        string $reasonCode,
        string $comment
    ): array {
        if ($reporterUserId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        // §M suspension-gate parity — participation writes opt out of
        // the suspension admin-bypass (Permissions::is_not_suspended
        // ($id, false)): a suspended member, admin included, cannot
        // seed the moderation queue. Mirrors PostsService /
        // CommentService's inline gates.
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($reporterUserId, false)) {
            return ['error' => 'bcc_forbidden', 'message' => 'Your account is suspended.'];
        }

        if (!in_array($targetKind, self::TARGET_KINDS, true)) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Unsupported target kind.'];
        }
        if ($targetId <= 0) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Target id is required.'];
        }
        if (!in_array($reasonCode, self::REASON_CODES, true)) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Unknown reason code.'];
        }

        $comment = trim($comment);
        if (mb_strlen($comment) > self::COMMENT_MAX_LENGTH) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf('Comment caps at %d characters.', self::COMMENT_MAX_LENGTH),
            ];
        }
        if (in_array($reasonCode, self::REASONS_REQUIRING_COMMENT, true) && $comment === '') {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Tell us what happened — a short note is required for "other".',
            ];
        }

        // §K1 self-report rejection. V1 only knows how to resolve the
        // author of a feed_item; when more target kinds land (comment,
        // profile, page) extend this dispatch with their resolvers.
        // PHPStan narrows $targetKind to 'feed_item' here because that's
        // the only entry in TARGET_KINDS — the in_array guard above is
        // exhaustive against the literal-string union.
        $authorId = self::resolveFeedItemAuthor($targetId);
        if ($authorId > 0 && $authorId === $reporterUserId) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => "You can't report your own post.",
            ];
        }

        // Throttle — reuse the FLAG limits (same posture: 5 per 5 min).
        // Per-reporter, not per (reporter, target), so spinning up many
        // small reports against different targets still hits the gate.
        $burstKey = "content_report:{$reporterUserId}:burst";
        if (!Throttle::allow($burstKey, BCC_TRUST_RATE_LIMIT_FLAG, BCC_TRUST_RATE_WINDOW_FLAG)) {
            Logger::info('[ContentReportService] report burst seatbelt fired', [
                'user_id' => $reporterUserId,
            ]);
            return [
                'error'   => 'bcc_rate_limited',
                'message' => 'Too many reports. Wait a moment before filing another.',
            ];
        }

        // Idempotent insert. The UNIQUE on (reporter, target_kind, target_id)
        // catches duplicates; create() returns 0 in that case.
        $reportId = $this->repo->create(
            $reporterUserId,
            $targetKind,
            $targetId,
            $reasonCode,
            $comment
        );

        if ($reportId === 0) {
            // Two paths land here:
            //   - duplicate (UNIQUE blocked the insert)
            //   - genuine wpdb error (insert returned false for other reasons)
            // Distinguish via the exists check — if the row is present, the
            // user already reported this target; treat as ok=true / existing.
            if ($this->repo->exists($reporterUserId, $targetKind, $targetId)) {
                return [
                    'ok'        => true,
                    'report_id' => 0,
                    'status'    => 'existing',
                ];
            }

            return [
                'error'   => 'bcc_unavailable',
                'message' => 'Could not file the report. Try again.',
            ];
        }

        // §A3 event bus — Phase C's auto-hide threshold + admin-queue
        // notifier subscribe here. Subscribers run async via WP-cron
        // queue per the §A3 contract; the originating request returns
        // immediately.
        do_action('bcc_content_reported', $reporterUserId, $targetKind, $targetId, $reportId, $reasonCode);

        Logger::info('[ContentReportService] report filed', [
            'reporter_id' => $reporterUserId,
            'target_kind' => $targetKind,
            'target_id'   => $targetId,
            'report_id'   => $reportId,
            'reason'      => $reasonCode,
        ]);

        return [
            'ok'        => true,
            'report_id' => $reportId,
            'status'    => 'created',
        ];
    }

    /**
     * Resolve the act_user_id for a peepso_activities row. Returns 0
     * when the act_id is gone or invalid — caller treats as "no
     * self-report check possible" and continues.
     */
    private static function resolveFeedItemAuthor(int $actId): int
    {
        if ($actId <= 0) {
            return 0;
        }
        $row = PeepSoActivityRepository::getById($actId);
        if ($row === null) {
            return 0;
        }
        $authorRaw = $row->act_user_id ?? 0;
        return (int) $authorRaw;
    }
}
