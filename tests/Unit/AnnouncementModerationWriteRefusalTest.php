<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Announcements\AnnouncementVisibility as Vis;
use BCC\Trust\Core\Repositories\AnnouncementRepository;
use BCC\Trust\Core\Services\AnnouncementWriteService;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as Authority;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-307 B2 — the server, not the capability, is the authority: edit() and
 * pin(true) refuse a notice an administrator took down, with
 * `bcc_announcement_moderation_hidden` / 409, BEFORE any write. Unpin stays
 * allowed (as on an archived row, F-151); archival outranks the takedown.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AnnouncementModerationWriteRefusalTest extends TestCase
{
    private const PAGE = 500;
    private const ANN  = 9001;
    private const NOW  = '2026-11-12 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/announcement-moderation-write-stubs.php';
        $GLOBALS['wpdb']           = new \AnnouncementArchiveFakeWpdb();
        $GLOBALS['__bcc_arch_own'] = self::row('publish');
    }

    /** @return array{decision: string, reason: string|null, operator_user_id: int|null} */
    private static function operator(): array
    {
        return ['decision' => Authority::DECISION_OPERATOR, 'reason' => null, 'operator_user_id' => 42];
    }

    private static function row(string $postStatus): \stdClass
    {
        $r              = new \stdClass();
        $r->ID          = self::ANN;
        $r->post_status = $postStatus;
        return $r;
    }

    private static function takeDown(): void
    {
        $GLOBALS['__bcc_arch_meta'][self::ANN][Vis::META_MODERATION_HIDDEN_AT] = '2026-11-12 08:00:00';
    }

    /** @return array<string, string> */
    private static function meta(): array
    {
        return $GLOBALS['__bcc_arch_meta'][self::ANN] ?? [];
    }

    /** @param array<string, mixed> $res */
    private static function assertModerationRefusal(array $res): void
    {
        self::assertFalse($res['ok'] ?? true, json_encode($res) ?: '');
        self::assertSame(AnnouncementWriteService::ERR_MODERATION_HIDDEN, $res['code'] ?? null);
        self::assertSame('bcc_announcement_moderation_hidden', $res['code'] ?? null);
        self::assertSame(409, $res['status'] ?? null);
        self::assertArrayNotHasKey('reason', $res, 'mirrors the archived refusal: no reason_code block');
    }

    public function testEditRefusesATakenDownNoticeAndWritesNothing(): void
    {
        self::takeDown();
        $before = self::meta();

        $res = (new AnnouncementWriteService())->edit(
            self::PAGE,
            self::ANN,
            self::operator(),
            ['title' => 'New title', 'body' => 'New body', 'comments_enabled' => false],
            self::NOW
        );

        self::assertModerationRefusal($res);
        self::assertSame([], $GLOBALS['__bcc_arch_post_writes'], 'no post write');
        self::assertSame($before, self::meta(), 'no meta write');
        self::assertSame([], $GLOBALS['__bcc_arch_actions'], 'no action fired');
    }

    public function testPinRefusesATakenDownNoticeAndWritesNothing(): void
    {
        self::takeDown();
        $before = self::meta();

        $res = (new AnnouncementWriteService())->pin(self::PAGE, self::ANN, self::operator(), true, self::NOW);

        self::assertModerationRefusal($res);
        self::assertSame($before, self::meta(), 'no pin meta written');
        self::assertArrayNotHasKey(AnnouncementRepository::META_PINNED_AT, self::meta());
        self::assertSame([], $GLOBALS['__bcc_arch_actions']);
    }

    /** Unpin only reduces what the notice could promote — allowed, as on an archived row (F-151). */
    public function testUnpinStaysAllowedOnATakenDownNotice(): void
    {
        self::takeDown();
        $GLOBALS['__bcc_arch_meta'][self::ANN][AnnouncementRepository::META_PINNED_AT] = '2026-11-01 00:00:00';

        $res = (new AnnouncementWriteService())->pin(self::PAGE, self::ANN, self::operator(), false, self::NOW);

        self::assertTrue($res['ok'] ?? false, json_encode($res) ?: '');
        self::assertArrayNotHasKey(AnnouncementRepository::META_PINNED_AT, self::meta());
        self::assertSame(
            '2026-11-12 08:00:00',
            self::meta()[Vis::META_MODERATION_HIDDEN_AT] ?? null,
            'the takedown is untouched'
        );
    }

    /** Archival is permanent and outranks the takedown, as in the capability ladder. */
    public function testAnArchivedTakenDownNoticeAnswersTheArchivedRefusal(): void
    {
        self::takeDown();
        $GLOBALS['__bcc_arch_meta'][self::ANN][Vis::META_ARCHIVED_AT] = '2026-11-02 00:00:00';

        $svc = new AnnouncementWriteService();
        foreach ([
            $svc->edit(self::PAGE, self::ANN, self::operator(), ['title' => 'x'], self::NOW),
            $svc->pin(self::PAGE, self::ANN, self::operator(), true, self::NOW),
        ] as $res) {
            self::assertSame(AnnouncementWriteService::ERR_CONFLICT, $res['code'] ?? null);
            self::assertSame(409, $res['status'] ?? null);
        }
    }

    /**
     * Controls: without the marker (or with an EMPTY one, which is not a
     * takedown) both writes get past the refusal — edit reaches validation,
     * pin reaches the publication rung.
     */
    public function testWithoutATakedownBothWritesPassTheRefusal(): void
    {
        foreach ([null, ''] as $marker) {
            if ($marker !== null) {
                $GLOBALS['__bcc_arch_meta'][self::ANN][Vis::META_MODERATION_HIDDEN_AT] = $marker;
            }
            $svc = new AnnouncementWriteService();

            $edit = $svc->edit(self::PAGE, self::ANN, self::operator(), ['title' => ''], self::NOW);
            self::assertSame(AnnouncementWriteService::ERR_INVALID, $edit['code'] ?? null, 'edit reached validation');

            $GLOBALS['__bcc_arch_own'] = self::row('draft');
            $pin = $svc->pin(self::PAGE, self::ANN, self::operator(), true, self::NOW);
            self::assertSame(AnnouncementWriteService::ERR_CONFLICT, $pin['code'] ?? null);
            self::assertSame('Only a published announcement can be pinned.', $pin['message'] ?? null);
            $GLOBALS['__bcc_arch_own'] = self::row('publish');
        }
    }
}
