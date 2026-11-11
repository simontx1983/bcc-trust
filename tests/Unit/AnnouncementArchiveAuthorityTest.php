<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\AnnouncementWriteService;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as Authority;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-147 — who may archive (user decision 2026-11-11): the page's CURRENT
 * operator may archive any published notice on the page, including a
 * predecessor's; a site administrator may archive any published notice.
 * Everyone else is refused, and the author-only rules of edit/publish/pin
 * are untouched (they still go through the author-bound lookup alone).
 *
 * The fake $wpdb answers the two lookups separately: the author-bound one
 * (`p.post_author = %d`, findForOwner) and the public one (findPublic).
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AnnouncementArchiveAuthorityTest extends TestCase
{
    private const PAGE  = 500;
    private const ANN   = 9001;
    private const NOW   = '2026-11-11 12:00:00';
    private const ARCH  = '_bcc_announcement_archived_at';

    private \AnnouncementArchiveFakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/announcement-archive-stubs.php';
        $this->wpdb      = new \AnnouncementArchiveFakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        // Audit table present without a SHOW TABLES round-trip.
        $p = new \ReflectionProperty(AuditLogger::class, 'tableExists');
        $p->setValue(null, true);
    }

    /** @return array{decision: string, reason: string|null, operator_user_id: int|null} */
    private static function operator(int $id = 42): array
    {
        return ['decision' => Authority::DECISION_OPERATOR, 'reason' => null, 'operator_user_id' => $id];
    }

    /** @return array{decision: string, reason: string|null, operator_user_id: int|null} */
    private static function denied(): array
    {
        return ['decision' => Authority::DECISION_DENIED, 'reason' => Authority::REASON_NOT_CLAIMER, 'operator_user_id' => null];
    }

    private static function published(): \stdClass
    {
        $r              = new \stdClass();
        $r->ID          = self::ANN;
        $r->post_status = 'publish';
        return $r;
    }

    /** @return array<string, mixed> */
    private function archive(array $decision, bool $admin = false, int $actor = 42): array
    {
        return (new AnnouncementWriteService())->archive(self::PAGE, self::ANN, $decision, self::NOW, $admin, $actor);
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(): array
    {
        return array_values(array_map(static fn ($i) => $i['data'], $this->wpdb->inserts));
    }

    public function testTheCurrentOperatorMayArchiveAPredecessorsPublishedNotice(): void
    {
        $GLOBALS['__bcc_arch_own']    = null;              // not their row
        $GLOBALS['__bcc_arch_public'] = self::published(); // but it is on their page

        $res = $this->archive(self::operator());

        self::assertTrue($res['ok'] ?? false, json_encode($res) ?: '');
        self::assertSame(self::NOW, $GLOBALS['__bcc_arch_meta'][self::ANN][self::ARCH] ?? null);
        self::assertContains(['bcc_announcement_archived', self::ANN], $GLOBALS['__bcc_arch_actions']);

        $audit = $this->auditRows();
        self::assertCount(1, $audit);
        self::assertSame(AnnouncementWriteService::AUDIT_ARCHIVED_NOT_AUTHOR, $audit[0]['action']);
        self::assertSame(42, $audit[0]['user_id']);
        self::assertStringContainsString('"as":"operator"', (string) $audit[0]['meta']);
    }

    public function testAnAdministratorWhoIsNotTheOperatorMayArchive(): void
    {
        $GLOBALS['__bcc_arch_public'] = self::published();

        $res = $this->archive(self::denied(), true, 1);

        self::assertTrue($res['ok'] ?? false, json_encode($res) ?: '');
        self::assertArrayHasKey(self::ARCH, $GLOBALS['__bcc_arch_meta'][self::ANN] ?? []);
        $audit = $this->auditRows();
        self::assertCount(1, $audit);
        self::assertSame(1, $audit[0]['user_id']);
        self::assertStringContainsString('"as":"administrator"', (string) $audit[0]['meta']);
        // A non-operator never takes the author-bound path.
        foreach ($GLOBALS['__bcc_arch_queries'] as $q) {
            self::assertStringNotContainsString('p.post_author = %d', $q);
        }
    }

    public function testANeitherOperatorNorAdministratorIsRefusedBeforeAnyRead(): void
    {
        $GLOBALS['__bcc_arch_public'] = self::published();

        $res = $this->archive(self::denied(), false);

        self::assertFalse($res['ok'] ?? true);
        self::assertSame(403, $res['status'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_arch_queries'], 'no row read for a refused actor');
        self::assertSame([], $GLOBALS['__bcc_arch_meta']);
    }

    public function testTheOperatorsOwnRowIsStillResolvedFirstAndNotAudited(): void
    {
        $GLOBALS['__bcc_arch_own']    = self::published();
        $GLOBALS['__bcc_arch_public'] = self::published();

        $res = $this->archive(self::operator());

        self::assertTrue($res['ok'] ?? false);
        self::assertCount(1, $GLOBALS['__bcc_arch_queries'], 'own lookup answered; public lookup not needed');
        self::assertSame([], $this->auditRows(), 'archiving your own notice is not an override');
    }

    public function testTheOperatorsOwnDraftStillAnswersNotPublished(): void
    {
        $draft              = self::published();
        $draft->post_status = 'draft';
        $GLOBALS['__bcc_arch_own'] = $draft;

        $res = $this->archive(self::operator());

        self::assertSame(409, $res['status'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_arch_meta']);
    }

    public function testARowOnNeitherLookupIs404(): void
    {
        // e.g. another page's notice, or a predecessor's draft: findPublic is
        // bound to this page and to published rows.
        $res = $this->archive(self::operator());
        self::assertSame(404, $res['status'] ?? null);

        $res = $this->archive(self::denied(), true, 1);
        self::assertSame(404, $res['status'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_arch_meta']);
    }

    public function testAnAlreadyArchivedPredecessorRowIsAConflictAndNotReStamped(): void
    {
        $GLOBALS['__bcc_arch_public']                         = self::published();
        $GLOBALS['__bcc_arch_meta'][self::ANN][self::ARCH]    = '2026-01-01 00:00:00';

        $res = $this->archive(self::operator());

        self::assertSame(409, $res['status'] ?? null);
        self::assertSame('2026-01-01 00:00:00', $GLOBALS['__bcc_arch_meta'][self::ANN][self::ARCH]);
        self::assertSame([], $this->auditRows());
    }
}
