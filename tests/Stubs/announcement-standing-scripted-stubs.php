<?php

declare(strict_types=1);

/**
 * F-469: a scripted AnnouncementViewerStanding for
 * AnnouncementCommentStandingUnreadableTest. Load only in a separate process,
 * and BEFORE secret-page-announcement-stubs.php, so its guarded stand-in is
 * skipped. The standing is whatever $GLOBALS['__bcc_viewer_standing'] holds.
 */

namespace BCC\Trust\Core\Announcements {
    if (!class_exists(__NAMESPACE__ . '\\AnnouncementViewerStanding', false)) {
        final class AnnouncementViewerStanding
        {
            /** @return array<string, mixed> */
            public static function forViewer(int $viewerId): array
            {
                /** @var array<string, mixed> $standing */
                $standing = $GLOBALS['__bcc_viewer_standing'] ?? [];

                return $standing;
            }
        }
    }
}
