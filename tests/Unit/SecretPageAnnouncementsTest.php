<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\AnnouncementsEndpoint;
use BCC\Trust\Core\Services\AnnouncementCommentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A SECRET page's announcements, for a viewer the page does not admit.
 *
 * The public list, the rotator and the detail read took a page id, and the
 * comment routes an announcement id, from anyone: a secret page's notices
 * and their threads were readable, and commentable, by a stranger who knew
 * an id. Now each answers exactly what a page that does not exist answers:
 *   - list and rotator: the EMPTY page (a missing page has no
 *     announcements; it is not a 404);
 *   - detail: the same 404;
 *   - the thread, a new comment, a removal: the same 404.
 *
 * The repository reads still RUN for a hidden page, so a failing database
 * answers 503 for it exactly as for a missing page.
 *
 * The real AnnouncementsEndpoint, AnnouncementCommentService,
 * AnnouncementViewService, PageAccess and ProfileAccess; the doubles are in
 * tests/Stubs/secret-page-announcement-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SecretPageAnnouncementsTest extends TestCase
{
    private const VIEWER  = 5;
    private const OWNER   = 70;
    private const SECRET  = 900;
    private const OPEN    = 901;
    private const MISSING = 999;

    private const ANN_SECRET  = 5001;
    private const ANN_OPEN    = 5002;
    private const ANN_MISSING = 5999;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/announcement-endpoint-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/profile-access-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/secret-page-announcement-stubs.php';

        $GLOBALS['__bcc_options']        = ['bcc_announcements_enabled' => '1'];
        $GLOBALS['__bcc_current_user']   = self::VIEWER;
        $GLOBALS['__bcc_profile_access'] = ['viewer_id' => self::VIEWER, 'is_admin' => false];
        $GLOBALS['__bcc_posts'] = [
            self::SECRET     => \bcc_ann_post(self::SECRET, 'peepso-page', self::OWNER, 0, 'secret-page'),
            self::OPEN       => \bcc_ann_post(self::OPEN, 'peepso-page', self::OWNER, 0, 'open-page'),
            self::ANN_SECRET => \bcc_ann_post(self::ANN_SECRET, 'bcc_announcement', self::OWNER, self::SECRET),
            self::ANN_OPEN   => \bcc_ann_post(self::ANN_OPEN, 'bcc_announcement', self::OWNER, self::OPEN),
        ];
        $GLOBALS['__bcc_ann'] = [
            'rows' => [
                self::SECRET => [\bcc_ann_row(self::ANN_SECRET, 'secret notice')],
                self::OPEN   => [\bcc_ann_row(self::ANN_OPEN, 'open notice')],
            ],
            'public' => [
                self::SECRET . ':' . self::ANN_SECRET => \bcc_ann_row(self::ANN_SECRET, 'secret notice'),
                self::OPEN . ':' . self::ANN_OPEN     => \bcc_ann_row(self::ANN_OPEN, 'open notice'),
            ],
            'secret' => [self::SECRET => []],
            'calls'  => [],
        ];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__bcc_options'],
            $GLOBALS['__bcc_current_user'],
            $GLOBALS['__bcc_profile_access'],
            $GLOBALS['__bcc_posts'],
            $GLOBALS['__bcc_ann']
        );
    }

    // ── the public list and the rotator ─────────────────────────────────

    /** @return iterable<string, array{0: string, 1: array<string, string>}> */
    public static function listReads(): iterable
    {
        yield 'active list'   => ['publicList', []];
        yield 'archived list' => ['publicList', ['state' => 'archived']];
        yield 'rotator'       => ['rotator', []];
    }

    /** @param array<string, string> $extra */
    #[DataProvider('listReads')]
    public function testAHiddenPageReadsExactlyAsAMissingPage(string $handler, array $extra): void
    {
        $missing = $this->read($handler, self::MISSING, $extra);
        $hidden  = $this->read($handler, self::SECRET, $extra);

        self::assertSame(200, $missing->get_status(), 'a missing page has no announcements; it is not a 404');
        self::assertSame([$missing->get_status(), $missing->get_data()], [$hidden->get_status(), $hidden->get_data()]);
        self::assertSame([], $hidden->get_data()['data']['items'] ?? null, 'no notice leaks');
    }

    /** @param array<string, string> $extra */
    #[DataProvider('listReads')]
    public function testAHiddenPagesReadStillRuns(string $handler, array $extra): void
    {
        $this->read($handler, self::SECRET, $extra);

        self::assertNotSame([], $GLOBALS['__bcc_ann']['calls'], 'the read runs, so a failing database answers alike');
    }

    /** @param array<string, string> $extra */
    #[DataProvider('listReads')]
    public function testAFailedReadIsTheSame503ForAHiddenPageAsForAMissingOne(string $handler, array $extra): void
    {
        $GLOBALS['__bcc_ann']['fail'] = true;

        $missing = $this->read($handler, self::MISSING, $extra);
        $hidden  = $this->read($handler, self::SECRET, $extra);

        self::assertSame(503, $missing->get_status());
        self::assertSame([$missing->get_status(), $missing->get_data()], [$hidden->get_status(), $hidden->get_data()]);
    }

    /** @param array<string, string> $extra */
    #[DataProvider('listReads')]
    public function testAMemberSeesTheNotices(string $handler, array $extra): void
    {
        $GLOBALS['__bcc_ann']['secret'][self::SECRET] = [self::VIEWER];

        self::assertSame(['secret notice'], $this->titles($this->read($handler, self::SECRET, $extra)));
    }

    public function testThePagesAuthorAndAnAdminSeeTheNotices(): void
    {
        $GLOBALS['__bcc_current_user'] = self::OWNER;
        self::assertSame(['secret notice'], $this->titles($this->read('publicList', self::SECRET)));

        $GLOBALS['__bcc_current_user']               = self::VIEWER;
        $GLOBALS['__bcc_profile_access']['is_admin'] = true;
        self::assertSame(['secret notice'], $this->titles($this->read('rotator', self::SECRET)));
    }

    public function testAnOpenPageIsUnchangedForAnyone(): void
    {
        $GLOBALS['__bcc_current_user'] = 0;

        self::assertSame(['open notice'], $this->titles($this->read('publicList', self::OPEN)));
        self::assertSame(['open notice'], $this->titles($this->read('rotator', self::OPEN)));
    }

    #[DataProvider('listReads')]
    public function testThePublicReadsVaryOnTheCredential(string $handler, array $extra): void
    {
        $headers = $this->read($handler, self::OPEN, $extra)->get_headers();

        self::assertSame('Authorization, Cookie', $headers['Vary'] ?? null, 'the answer now depends on the viewer');
        self::assertSame('no-store', $headers['Cache-Control'] ?? null);
    }

    // ── the detail read ─────────────────────────────────────────────────

    public function testAHiddenPagesAnnouncementIsTheSame404AsAMissingOne(): void
    {
        $missing = $this->detail(self::MISSING, self::ANN_MISSING);
        $hidden  = $this->detail(self::SECRET, self::ANN_SECRET);

        self::assertSame(404, $missing->get_status());
        self::assertSame([$missing->get_status(), $missing->get_data()], [$hidden->get_status(), $hidden->get_data()]);
        self::assertContains('findPublic:' . self::SECRET, $GLOBALS['__bcc_ann']['calls'], 'the read still runs');
    }

    public function testAFailedDetailReadIsTheSame503ForAHiddenPage(): void
    {
        $GLOBALS['__bcc_ann']['fail'] = true;

        $missing = $this->detail(self::MISSING, self::ANN_MISSING);
        $hidden  = $this->detail(self::SECRET, self::ANN_SECRET);

        self::assertSame(503, $missing->get_status());
        self::assertSame([$missing->get_status(), $missing->get_data()], [$hidden->get_status(), $hidden->get_data()]);
    }

    public function testAMemberReadsTheAnnouncement(): void
    {
        $GLOBALS['__bcc_ann']['secret'][self::SECRET] = [self::VIEWER];

        $response = $this->detail(self::SECRET, self::ANN_SECRET);

        self::assertSame(200, $response->get_status());
        self::assertSame('secret notice', $response->get_data()['data']['title'] ?? null);
    }

    // ── the comment routes ──────────────────────────────────────────────

    public function testAHiddenPagesThreadIsTheSame404AsAMissingAnnouncementsAndIsNeverRead(): void
    {
        $service = new AnnouncementCommentService();

        $missing = $service->listThread(self::ANN_MISSING, 20, null, self::VIEWER);
        $hidden  = $service->listThread(self::ANN_SECRET, 20, null, self::VIEWER);

        self::assertSame(404, $missing['status'] ?? null);
        self::assertSame($missing, $hidden);
        self::assertNotContains('listForAnnouncement:' . self::ANN_SECRET, $GLOBALS['__bcc_ann']['calls']);
    }

    public function testAMemberReadsTheThread(): void
    {
        $GLOBALS['__bcc_ann']['secret'][self::SECRET] = [self::VIEWER];

        $result = (new AnnouncementCommentService())->listThread(self::ANN_SECRET, 20, null, self::VIEWER);

        self::assertTrue($result['ok'] ?? false);
        self::assertContains('listForAnnouncement:' . self::ANN_SECRET, $GLOBALS['__bcc_ann']['calls']);
    }

    public function testCommentingOnAHiddenPagesAnnouncementIsTheMissing404(): void
    {
        $service = new AnnouncementCommentService();

        // `parent_id` sent: the first refusal AFTER the lookup, so a viewer
        // who gets past it is visibly past the page gate.
        $missing = $service->create(self::ANN_MISSING, self::VIEWER, 'hello', true);
        $hidden  = $service->create(self::ANN_SECRET, self::VIEWER, 'hello', true);

        self::assertSame(404, $missing['status'] ?? null);
        self::assertSame($missing, $hidden);

        $GLOBALS['__bcc_ann']['secret'][self::SECRET] = [self::VIEWER];
        self::assertSame(400, $service->create(self::ANN_SECRET, self::VIEWER, 'hello', true)['status'] ?? null, 'a member is past the gate');
    }

    public function testRemovingACommentOnAHiddenPagesAnnouncementIsTheMissing404(): void
    {
        $service = new AnnouncementCommentService();
        // Someone else's comment: the first refusal AFTER the lookup is 403.
        $GLOBALS['__bcc_ann']['comment'] = (object) ['comment_ID' => '77', 'comment_post_ID' => (string) self::ANN_SECRET, 'user_id' => '42'];

        self::assertSame(404, $service->remove(77, self::VIEWER)['status'] ?? null);

        $GLOBALS['__bcc_ann']['secret'][self::SECRET] = [self::VIEWER];
        self::assertSame(403, $service->remove(77, self::VIEWER)['status'] ?? null, 'a member is past the gate');
    }

    public function testAnOpenPagesThreadIsUnchanged(): void
    {
        $result = (new AnnouncementCommentService())->listThread(self::ANN_OPEN, 20, null, 0);

        self::assertTrue($result['ok'] ?? false);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @param array<string, string> $extra */
    private function read(string $handler, int $pageId, array $extra = []): \WP_REST_Response
    {
        /** @var \WP_REST_Response $response */
        $response = (new AnnouncementsEndpoint())->{$handler}(new \WP_REST_Request(['pageId' => $pageId] + $extra));

        return $response;
    }

    private function detail(int $pageId, int $announcementId): \WP_REST_Response
    {
        return (new AnnouncementsEndpoint())->detail(new \WP_REST_Request(['pageId' => $pageId, 'id' => $announcementId]));
    }

    /** @return list<string> */
    private function titles(\WP_REST_Response $response): array
    {
        self::assertSame(200, $response->get_status(), (string) json_encode($response->get_data()));

        return array_column($response->get_data()['data']['items'] ?? [], 'title');
    }
}
