<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Rank\Services\HelpfulMarkEvidenceListener;
use PHPUnit\Framework\TestCase;

/**
 * Rank helping emitters (§9.2) — the helpful_mark → helping evidence
 * decision logic.
 *
 * Pins: subject = content author (content→owner), marker = relationship
 * identity; self-marks earn no credit; ONLY a credible marker's mark
 * mints evidence (non-credible marks are recorded cosmetically but yield
 * nothing here); un-mark reverses the exact source; and re-firing an ADD
 * with the same mark id produces the SAME ingest args — so the ledger's
 * event_uid (helpful_mark:{markId}:{author}) dedupes it idempotently.
 *
 * The credibility verdict itself is pinned in RankCredibilityGateTest;
 * the ledger UNIQUE-based idempotency in the ingestor/DB layer.
 */
final class HelpfulMarkEvidenceListenerTest extends TestCase
{
    private const MARKER = 7;
    private const AUTHOR = 42;
    private const ACT    = 500;
    private const MARK   = 99;

    /**
     * @param array<int, int> $authors actId => author user id
     * @param array<int, bool> $credible markerId => is credible
     */
    private function listener(array $authors, array $credible): RecordingHelpfulMarkListener
    {
        return new RecordingHelpfulMarkListener($authors, $credible);
    }

    public function testCredibleMarkOnAnothersContentMintsEvidenceKeyedOnAuthorAndMarker(): void
    {
        $l = $this->listener([self::ACT => self::AUTHOR], [self::MARKER => true]);
        $l->onMarkAdded(self::MARKER, self::ACT, self::MARK);

        // ingest(subject=author, markId, relationship=marker)
        self::assertSame([[self::AUTHOR, self::MARK, self::MARKER]], $l->ingestCalls);
        self::assertSame([], $l->reverseCalls);
    }

    public function testSelfMarkEarnsNoCredit(): void
    {
        // Author IS the marker — no self-directed credit (§9.2).
        $l = $this->listener([self::ACT => self::MARKER], [self::MARKER => true]);
        $l->onMarkAdded(self::MARKER, self::ACT, self::MARK);

        self::assertSame([], $l->ingestCalls);
    }

    public function testNonCredibleMarkerMintsNoEvidence(): void
    {
        // Non-credible marker — the cosmetic mark stands (recorded at the
        // endpoint) but produces NO Rank evidence here.
        $l = $this->listener([self::ACT => self::AUTHOR], [self::MARKER => false]);
        $l->onMarkAdded(self::MARKER, self::ACT, self::MARK);

        self::assertSame([], $l->ingestCalls);
    }

    public function testUnresolvableAuthorMintsNoEvidence(): void
    {
        // Deleted / stale act → author 0 → nothing.
        $l = $this->listener([], [self::MARKER => true]);
        $l->onMarkAdded(self::MARKER, self::ACT, self::MARK);

        self::assertSame([], $l->ingestCalls);
    }

    public function testInvalidInputsAreNoOps(): void
    {
        $l = $this->listener([self::ACT => self::AUTHOR], [self::MARKER => true]);
        $l->onMarkAdded(0, self::ACT, self::MARK);
        $l->onMarkAdded(self::MARKER, 0, self::MARK);
        $l->onMarkAdded(self::MARKER, self::ACT, 0);
        $l->onMarkRemoved(0);

        self::assertSame([], $l->ingestCalls);
        self::assertSame([], $l->reverseCalls);
    }

    public function testUnmarkReversesTheExactSource(): void
    {
        $l = $this->listener([], []);
        $l->onMarkRemoved(self::MARK);

        self::assertSame([self::MARK], $l->reverseCalls);
    }

    public function testReMarkProducesStableIngestArgsForEventUidIdempotency(): void
    {
        // Two ADDs with the same mark id yield identical ingest args, so
        // the ledger's UNIQUE(event_uid, category) collapses them to one
        // row — hook re-fires are idempotent.
        $l = $this->listener([self::ACT => self::AUTHOR], [self::MARKER => true]);
        $l->onMarkAdded(self::MARKER, self::ACT, self::MARK);
        $l->onMarkAdded(self::MARKER, self::ACT, self::MARK);

        self::assertSame(
            [[self::AUTHOR, self::MARK, self::MARKER], [self::AUTHOR, self::MARK, self::MARKER]],
            $l->ingestCalls
        );
    }
}

/**
 * Recording double — scripts author + credibility resolution, records
 * ingest / reverse so the suite pins the decision without WordPress.
 */
final class RecordingHelpfulMarkListener extends HelpfulMarkEvidenceListener
{
    /** @var list<array{int, int, int}> [subject, markId, marker] */
    public array $ingestCalls = [];
    /** @var list<int> reversed mark ids */
    public array $reverseCalls = [];

    /**
     * @param array<int, int> $authors
     * @param array<int, bool> $credible
     */
    public function __construct(
        private readonly array $authors,
        private readonly array $credible
    ) {
    }

    protected function authorOf(int $actId): int
    {
        return $this->authors[$actId] ?? 0;
    }

    protected function isCredibleRecognizer(int $markerUserId): bool
    {
        return $this->credible[$markerUserId] ?? false;
    }

    protected function ingest(int $subjectUserId, int $markId, int $markerUserId): void
    {
        $this->ingestCalls[] = [$subjectUserId, $markId, $markerUserId];
    }

    protected function reverse(int $markId): void
    {
        $this->reverseCalls[] = $markId;
    }
}
