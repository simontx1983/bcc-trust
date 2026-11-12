<?php
/**
 * F-307 — administrator takedown of an announcement.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * The admin moderation queue could act only on feed activities. An
 * announcement is read through its own paths (list, detail, comments, the
 * page panel), none of which consulted the hidden-activity set, so a
 * reported notice could not be taken down at all: the queue force-dismissed
 * every announcement report whatever the admin chose. Product decision
 * 2026-11-12: a real takedown, human-only, honoured by every read.
 *
 * ── ONE SWITCH, TWO HALVES ──────────────────────────────────────────────
 *   1. The marker {@see AnnouncementVisibility::META_MODERATION_HIDDEN_AT}.
 *      {@see AnnouncementVisibility::publicPredicateSql()} — the ONE
 *      producer every public read composes — refuses a marked row, so list,
 *      detail/permalink, comments, the rotator and new feed projections all
 *      drop the notice in one place.
 *   2. The notice's ONE projected feed activity (§4.32.8) goes into the
 *      existing hidden-activity set, which every feed read already filters.
 *      No feed read code changes. The row's reason code is
 *      {@see self::REASON_TAKEDOWN}, so a restore removes only a hide this
 *      service wrote — never an unrelated one.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ────────────────────────────────────
 * It is never called by auto-hide: {@see \BCC\Trust\Core\Announcements\AnnouncementModerationPolicy}
 * still keeps reports from hiding a notice on their own. It does not
 * archive, unpin or edit the notice: a restore returns it exactly as it was,
 * pin included. It decides nothing about reports — the moderation queue
 * owns report state and calls this for the visibility half only.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Announcements\AnnouncementPostType;
use BCC\Trust\Core\Announcements\AnnouncementVisibility;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\PeepSoActivityWriter;

if (!defined('ABSPATH')) {
    exit;
}

final class AnnouncementModerationService
{
    /** Reason code on the projected activity's hidden row. */
    public const REASON_TAKEDOWN = 'announcement_takedown';

    public function __construct(
        private readonly HiddenActivityRepository $hiddenRepo,
        private readonly PeepSoActivityWriter $activityWriter
    ) {
    }

    /** Is this an announcement post at all? */
    public static function isAnnouncement(int $announcementId): bool
    {
        if ($announcementId <= 0) {
            return false;
        }
        $post = get_post($announcementId);
        return $post instanceof \WP_Post && $post->post_type === AnnouncementPostType::POST_TYPE;
    }

    /** Is the notice currently taken down? */
    public function isHidden(int $announcementId): bool
    {
        if ($announcementId <= 0) {
            return false;
        }
        return trim((string) get_post_meta($announcementId, AnnouncementVisibility::META_MODERATION_HIDDEN_AT, true)) !== '';
    }

    /** The notice's projected feed activity, or 0 when it has none. */
    public function projectedActId(int $announcementId): int
    {
        return $this->activityWriter->findActIdForExternal('announcement', $announcementId);
    }

    /**
     * Take the notice down. Idempotent: an already-hidden notice keeps its
     * original marker (who, when, which report).
     *
     * @return bool true when the notice is hidden afterwards
     */
    public function hide(int $announcementId, int $adminUserId, int $reportId): bool
    {
        if (!self::isAnnouncement($announcementId)) {
            return false;
        }
        if ($this->isHidden($announcementId)) {
            return true;
        }

        // The marker first: it is what every announcement read checks, so
        // the takedown is in force before anything else is touched.
        $at = gmdate('Y-m-d H:i:s');
        if (!update_post_meta($announcementId, AnnouncementVisibility::META_MODERATION_HIDDEN_AT, $at)) {
            Logger::error('[AnnouncementModerationService] takedown marker write failed', [
                'announcement_id' => $announcementId,
            ]);
            return false;
        }
        update_post_meta($announcementId, AnnouncementVisibility::META_MODERATION_HIDDEN_BY, (string) max(0, $adminUserId));
        update_post_meta($announcementId, AnnouncementVisibility::META_MODERATION_HIDDEN_REPORT, (string) max(0, $reportId));

        $actId = $this->projectedActId($announcementId);
        if ($actId > 0 && !$this->hiddenRepo->add($actId, $adminUserId, self::REASON_TAKEDOWN, $reportId > 0 ? $reportId : null)) {
            // The notice is down everywhere but the feed. Undo the marker
            // rather than leave a half-takedown the admin was told succeeded.
            $this->clearMarker($announcementId);
            Logger::error('[AnnouncementModerationService] takedown feed hide failed; marker rolled back', [
                'announcement_id' => $announcementId,
                'act_id'          => $actId,
            ]);
            return false;
        }

        do_action('bcc_announcement_moderation_hidden', $announcementId, $adminUserId, $reportId);
        return true;
    }

    /**
     * Lift the takedown. Removes only a feed hide this service wrote, and
     * re-projects the notice when it never got a feed activity (it was taken
     * down before it came due). Idempotent on a notice that is not hidden.
     *
     * @return bool true when the notice is not hidden afterwards
     */
    public function restore(int $announcementId, int $adminUserId): bool
    {
        if (!self::isAnnouncement($announcementId)) {
            return false;
        }
        if (!$this->isHidden($announcementId)) {
            return true;
        }

        $actId = $this->projectedActId($announcementId);
        if ($actId > 0) {
            $row = $this->hiddenRepo->findByActId($actId);
            if ($row !== null && (string) ($row->reason_code ?? '') === self::REASON_TAKEDOWN) {
                $this->hiddenRepo->remove($actId);
            }
        }

        if (!$this->clearMarker($announcementId)) {
            Logger::error('[AnnouncementModerationService] takedown marker delete failed', [
                'announcement_id' => $announcementId,
            ]);
            return false;
        }

        if ($actId <= 0) {
            // Idempotent and gated by the public predicate + archival, so a
            // notice that is not due, not authorized or archived stays off
            // the feed.
            $post = get_post($announcementId);
            $authorId = $post instanceof \WP_Post ? (int) $post->post_author : 0;
            try {
                \BCC\Trust\Core\Plugin::instance()->activityStreamWriter()->handleAnnouncementPublished($authorId, $announcementId);
            } catch (\Throwable $e) {
                Logger::warning('[AnnouncementModerationService] re-projection after restore failed', [
                    'announcement_id' => $announcementId,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        do_action('bcc_announcement_moderation_restored', $announcementId, $adminUserId);
        return true;
    }

    private function clearMarker(int $announcementId): bool
    {
        delete_post_meta($announcementId, AnnouncementVisibility::META_MODERATION_HIDDEN_BY);
        delete_post_meta($announcementId, AnnouncementVisibility::META_MODERATION_HIDDEN_REPORT);
        delete_post_meta($announcementId, AnnouncementVisibility::META_MODERATION_HIDDEN_AT);
        clean_post_cache($announcementId);
        return !$this->isHidden($announcementId);
    }
}
