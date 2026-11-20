<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Announcements\AnnouncementPageAccess;
use BCC\Trust\Tests\Support\CoreSibling;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * AnnouncementPageAccess::mayViewerSeeRow(): the by-id half of the
 * secret-page rule for an announcement's feed activity, the one
 * FeedItemGate::check(), FeedItemReportVisibility::canSee() and the comment
 * routes ask. The feed half (keepVisible) is SecretPageFeedProjectionTest's.
 *
 * REAL: AnnouncementPageAccess, AnnouncementRepository::pageIdsFor(),
 * PageAccess, PageDiscoveryRepository, ProfileAccess, bcc-core's
 * PeepSoPagePrivacy and FeedItemNormalizer::MODULE_TO_KIND. The `$wpdb` is
 * tests/Stubs/secret-page-list-stubs.php's; the post_parent read is answered
 * by tests/Stubs/secret-page-feed-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AnnouncementPageAccessTest extends TestCase
{
    private const STRANGER = 5;
    private const MEMBER   = 6;
    private const OPERATOR = 70;

    private const SECRET     = 900;
    private const ANN_SECRET = 5001;

    /** @var mixed */
    private $priorWpdb;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/secret-page-list-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/secret-page-feed-stubs.php';
        \bcc_spl_load_core();
        if (!class_exists('BCC\\Core\\Feed\\FeedItemNormalizer', false)) {
            require_once CoreSibling::require('src/Feed/FeedItemNormalizer.php');
        }

        $GLOBALS['__bcc_spl'] = [
            'secret'   => [self::SECRET => true],
            'statuses' => [self::SECRET => [self::MEMBER => ['member']]],
            'posts'    => [self::SECRET => \bcc_spl_post(self::SECRET, self::OPERATOR)],
        ];
        $GLOBALS['__bcc_spf'] = ['parents' => [self::ANN_SECRET => self::SECRET]];

        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $wpdb            = new \BccSecretPageListWpdb();
        $wpdb->other     = \Closure::fromCallable('bcc_spf_parents_sql');
        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_spl'], $GLOBALS['__bcc_spf']);
        $GLOBALS['wpdb'] = $this->priorWpdb;
    }

    public function testAStrangerAndAnonymousMayNotSeeASecretPagesNotice(): void
    {
        self::assertFalse(AnnouncementPageAccess::mayViewerSeeRow(self::notice(), self::STRANGER));
        self::assertFalse(AnnouncementPageAccess::mayViewerSeeRow(self::notice(), 0));
    }

    public function testAMemberThePagesAuthorAndAnAdminMay(): void
    {
        self::assertTrue(AnnouncementPageAccess::mayViewerSeeRow(self::notice(), self::MEMBER), 'a member');
        self::assertTrue(AnnouncementPageAccess::mayViewerSeeRow(self::notice(), self::OPERATOR), "the page's author");

        $GLOBALS['__bcc_spf']['is_admin'] = true;
        $GLOBALS['__bcc_spf']['current']  = self::STRANGER;
        self::assertTrue(AnnouncementPageAccess::mayViewerSeeRow(self::notice(), self::STRANGER), 'an admin, signed in');
    }

    /** Asked about another user, the exemption is THAT user's, not the request's. */
    public function testAnotherUsersExemptionIsTheirOwn(): void
    {
        $GLOBALS['__bcc_spf']['is_admin'] = true;  // the request's user is an admin
        $GLOBALS['__bcc_spf']['current']  = 99;
        self::assertFalse(AnnouncementPageAccess::mayViewerSeeRow(self::notice(), self::STRANGER), 'not exempted by the asker');

        $GLOBALS['__bcc_spf']['admins'] = [self::STRANGER];
        self::assertTrue(AnnouncementPageAccess::mayViewerSeeRow(self::notice(), self::STRANGER), 'an admin by their own right');
    }

    public function testAnOpenPagesNoticeIsSeenByAnyone(): void
    {
        $GLOBALS['__bcc_spl']['secret'] = [];

        self::assertTrue(AnnouncementPageAccess::mayViewerSeeRow(self::notice(), 0));
    }

    /** A DB row's module is a string; the classification must not care. */
    public function testTheModuleIsReadAsAStringOrAnInt(): void
    {
        self::assertFalse(AnnouncementPageAccess::mayViewerSeeRow(self::notice(205), self::STRANGER));
        self::assertFalse(AnnouncementPageAccess::mayViewerSeeRow(self::notice('205'), self::STRANGER));
    }

    public function testAnyOtherModuleIsSeenWithoutAPageRead(): void
    {
        $status = (object) ['act_id' => 1, 'act_module_id' => '1', 'act_external_id' => self::ANN_SECRET];

        self::assertTrue(AnnouncementPageAccess::mayViewerSeeRow($status, self::STRANGER));
        self::assertSame([], $GLOBALS['__bcc_spf']['parent_reads'] ?? [], 'no post_parent read');
    }

    public function testAFailedPageLookupRefusesEvenAMember(): void
    {
        $GLOBALS['__bcc_spf']['fail_parents'] = true;

        self::assertFalse(AnnouncementPageAccess::mayViewerSeeRow(self::notice(), self::MEMBER));
    }

    public function testANoticeWithNoPageIsRefused(): void
    {
        $GLOBALS['__bcc_spf']['parents'] = [];
        $GLOBALS['__bcc_spl']['secret']  = [];

        self::assertFalse(AnnouncementPageAccess::mayViewerSeeRow(self::notice(), self::MEMBER));
    }

    /** @param int|string $module */
    private static function notice($module = '205'): object
    {
        return (object) ['act_id' => 2, 'act_module_id' => $module, 'act_external_id' => (string) self::ANN_SECRET];
    }
}
