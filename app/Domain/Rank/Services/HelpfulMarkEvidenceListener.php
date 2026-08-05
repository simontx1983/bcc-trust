<?php
/**
 * HelpfulMarkEvidenceListener — turns a deliberate §9.2 "Mark helpful"
 * event into Rank `helping` evidence (Rank redesign, helping emitters).
 *
 * Wired in Plugin.php's $rankIngest block off `bcc_helpful_mark_added` /
 * `bcc_helpful_mark_removed`. The decision logic lives HERE (not inline
 * closures) so it is unit-testable without WordPress:
 *
 *   onMarkAdded(marker, act, markId):
 *     - resolve the SUBJECT = the content author of the marked act
 *       (content→owner, the same act→author resolution
 *       NotificationDispatcher uses);
 *     - credit ONLY when subject != marker (no self-directed credit,
 *       §9.2) AND the MARKER is a credible member (§9.2 via
 *       RankCredibilityGate::isCredibleRecognizer). Non-credible / self
 *       marks are already recorded cosmetically at the endpoint — they
 *       simply mint no evidence here.
 *     - the marker is passed as relationshipUserId so the §9.3 helping
 *       share cap (0.10 per recipient) keys on the marker for free.
 *     - the ledger's UNIQUE(event_uid, category) makes a re-fire a no-op
 *       (idempotent). sourceId = the stable mark row id.
 *
 *   onMarkRemoved(markId): reverse the mark's evidence (§14.1 — reversed
 *     evidence stops contributing immediately). Reversing an id with no
 *     ledger rows matches zero rows (harmless).
 *
 * Collaborators are protected hooks (CapabilityResolver subclass pattern)
 * so HelpfulMarkEvidenceListenerTest scripts them without a DB.
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign (helping emitters)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

if (!defined('ABSPATH')) {
    exit;
}

class HelpfulMarkEvidenceListener
{
    /** The evidence source type routed to the helping category (rank-scoring.php). */
    private const SOURCE_TYPE = 'helpful_mark';

    public function onMarkAdded(int $markerUserId, int $actId, int $markId): void
    {
        if ($markerUserId <= 0 || $actId <= 0 || $markId <= 0) {
            return;
        }

        $subjectUserId = $this->authorOf($actId);
        if ($subjectUserId <= 0 || $subjectUserId === $markerUserId) {
            // No resolvable author, or a self-mark — no self-directed
            // credit (§9.2). The cosmetic mark row still stands.
            return;
        }

        if (!$this->isCredibleRecognizer($markerUserId)) {
            // §9.2: a Helpful mark counts as evidence only from a credible
            // member. Non-credible marks record the mark, mint no evidence.
            return;
        }

        $this->ingest($subjectUserId, $markId, $markerUserId);
    }

    public function onMarkRemoved(int $markId): void
    {
        if ($markId <= 0) {
            return;
        }
        $this->reverse($markId);
    }

    // ── collaborator hooks (overridden by the listener test) ────────────

    /** act → content author (0 when the act is gone / stale). */
    protected function authorOf(int $actId): int
    {
        return \BCC\Core\Repositories\PeepSoActivityRepository::getAuthorId($actId);
    }

    protected function isCredibleRecognizer(int $markerUserId): bool
    {
        return \BCC\Trust\Core\Plugin::instance()
            ->rankCredibilityGate()
            ->isCredibleRecognizer($markerUserId);
    }

    protected function ingest(int $subjectUserId, int $markId, int $markerUserId): void
    {
        \BCC\Trust\Core\Plugin::instance()
            ->rankEvidenceIngestor()
            ->ingest($subjectUserId, self::SOURCE_TYPE, $markId, $markerUserId);
    }

    protected function reverse(int $markId): void
    {
        \BCC\Trust\Core\Plugin::instance()
            ->rankEvidenceIngestor()
            ->reverse(self::SOURCE_TYPE, $markId, 'unmarked');
    }
}
