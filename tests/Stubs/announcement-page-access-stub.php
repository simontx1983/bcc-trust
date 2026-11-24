<?php
/**
 * A double of AnnouncementPageAccess for the BY-ID gates' wiring tests
 * (FeedItemGate::check(), FeedItemReportVisibility::canSee(), the comment
 * thread and a new comment): each must refuse a SECRET page's announcement
 * activity exactly as a missing post. The rule itself — the page lookup and
 * PageAccess's verdict — is AnnouncementPageAccessTest's, over the real class.
 *
 * ⚠ Required from a test's setUp(), inside its separate process, BEFORE the
 * real class autoloads.
 *
 * Fixture: $GLOBALS['__bcc_apa'] = ['hidden' => list<int>]  the act ids whose
 * notice the viewer may not see; 'calls' records "act_id:viewer".
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Announcements {
    if (!class_exists(__NAMESPACE__ . '\\AnnouncementPageAccess', false)) {
        final class AnnouncementPageAccess
        {
            public const POST_KIND = 'announcement';

            /**
             * @param list<array<string, mixed>> $items
             * @return list<array<string, mixed>>
             */
            public static function keepVisible(array $items, int $viewerId): array
            {
                return $items;
            }

            public static function isAnnouncementModule(mixed $module): bool
            {
                return (string) $module === '205';
            }

            public static function mayViewerSeeRow(object $activity, int $viewerId): bool
            {
                $actId = (int) ($activity->act_id ?? 0);
                $GLOBALS['__bcc_apa']['calls'][] = $actId . ':' . $viewerId;

                return !in_array($actId, $GLOBALS['__bcc_apa']['hidden'] ?? [], true);
            }
        }
    }
}
