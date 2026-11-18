<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\GroupMembersService;
use BCC\Trust\Core\Support\PrivacySettings;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * An OPEN group's roster (§4.7.7) is readable by anyone, so it omits the
 * members the viewer may not see — private profile, suspension, a block —
 * through the ONE list rule, ProfileAccess::filterVisible(). These tests
 * run the REAL filterVisible() over PeepSo stubs that model its privacy SQL
 * (filter-visible-stubs.php), so a gutted rule fails here too.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupRosterVisibilityTest extends TestCase
{
    private const VIEWER = 7;
    private const GROUP  = 4300;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/filter-visible-stubs.php';
        // isGroupAdmin()'s inputs: membership rows and site admins.
        require_once dirname(__DIR__) . '/Stubs/group-join-request-stubs.php';
        $GLOBALS['__bcc_fv']        = ['visible' => [], 'admin' => false, 'constructed' => []];
        $GLOBALS['__bcc_fv_viewer'] = self::VIEWER;
        $GLOBALS['__bcc_gjr']       = ['memberships' => [], 'site_admins' => [], 'suspended' => []];
    }

    /** @param list<int> $ids @return list<object> */
    private static function rows(array $ids): array
    {
        return array_map(static fn (int $id): object => (object) ['user_id' => (string) $id, 'role' => 'member'], $ids);
    }

    /** @param list<object> $rows @return list<int> */
    private static function kept(PeepSoPrivacy $privacy, array $rows): array
    {
        $m = new ReflectionMethod(GroupMembersService::class, 'rowsTheViewerMaySee');
        /** @var list<object> $out */
        $out = $m->invoke(null, $privacy, $rows, (int) $GLOBALS['__bcc_fv_viewer'], self::GROUP);
        return array_map(static fn (object $r): int => (int) $r->user_id, $out);
    }

    public function testAnOpenRosterKeepsOnlyMembersTheViewerMaySeeInOrder(): void
    {
        $GLOBALS['__bcc_fv']['visible'] = [11 => true, 13 => true];

        self::assertSame([13, 11], self::kept(PeepSoPrivacy::Open, self::rows([13, 12, 11, 14])));
    }

    public function testThePageIsJudgedInOneQueryOverItsIds(): void
    {
        $GLOBALS['__bcc_fv']['visible'] = [11 => true];

        self::kept(PeepSoPrivacy::Open, self::rows([11, 12]));

        $built = $GLOBALS['__bcc_fv']['constructed'];
        self::assertCount(1, $built, 'one PeepSoUserSearch for the whole page');
        self::assertSame([11, 12], $built[0]['args']['include']);
    }

    public function testTheViewerAlwaysSeesThemselves(): void
    {
        self::assertSame([self::VIEWER], self::kept(PeepSoPrivacy::Open, self::rows([self::VIEWER, 12])));
    }

    public function testAnAdminSeesEveryone(): void
    {
        $GLOBALS['__bcc_fv']['admin'] = true;

        self::assertSame([12, 13], self::kept(PeepSoPrivacy::Open, self::rows([12, 13])));
    }

    public function testAnAnonymousVisitorSeesOnlyWhatThePrivacySqlAdmits(): void
    {
        $GLOBALS['__bcc_fv_viewer']     = 0;
        $GLOBALS['__bcc_fv']['visible'] = [12 => true];

        self::assertSame([12], self::kept(PeepSoPrivacy::Open, self::rows([12, 13])));
    }

    public function testAPageOfOnlyHiddenMembersIsEmpty(): void
    {
        self::assertSame([], self::kept(PeepSoPrivacy::Open, self::rows([12, 13])));
    }

    /**
     * hide_from_search scopes to member search and the directory (user
     * decision 2026-11-12); a roster follows profile privacy. So the
     * roster's one query skips ONLY that leg, and the args it sends make
     * the search filter add no opt-out clause: a member hidden from search
     * still appears on the open roster.
     */
    public function testAMemberHiddenFromSearchStillAppears(): void
    {
        $GLOBALS['__bcc_fv']['visible'] = [11 => true];

        self::kept(PeepSoPrivacy::Open, self::rows([11]));

        $args = $GLOBALS['__bcc_fv']['constructed'][0]['args'];
        self::assertTrue($args[PrivacySettings::ARG_IGNORE_SEARCH_OPTOUT] ?? false, 'the roster leaves the search opt-out out');
        self::assertStringNotContainsString(
            'peepso_is_hide_profile_from_user_listing',
            (string) json_encode(PrivacySettings::filterPeepSoSearchArgs($args)),
            'so the opt-out clause is never added to the roster query'
        );
    }

    /** @return array<string, array{string|null, bool, bool}> status, site admin, sees the full roster */
    public static function groupRoles(): array
    {
        return [
            'owner'      => ['member_owner', false, true],
            'manager'    => ['member_manager', false, true],
            'site admin' => [null, true, true],
            'moderator'  => ['member_moderator', false, false],
            'member'     => ['member', false, false],
            'stranger'   => [null, false, false],
        ];
    }

    /**
     * This group's owner and managers (and site admins) already decide its
     * membership; they see every member, for managing and for the
     * transfer-ownership picker. Everyone else gets the filtered roster.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('groupRoles')]
    public function testThisGroupsAdminsSeeTheWholeOpenRoster(?string $status, bool $siteAdmin, bool $seesAll): void
    {
        if ($status !== null) {
            $GLOBALS['__bcc_gjr']['memberships'][self::VIEWER][self::GROUP] = $status;
        }
        if ($siteAdmin) {
            $GLOBALS['__bcc_gjr']['site_admins'] = [self::VIEWER];
        }
        $GLOBALS['__bcc_fv']['visible'] = [12 => true];

        $kept = self::kept(PeepSoPrivacy::Open, self::rows([12, 13]));

        self::assertSame($seesAll ? [12, 13] : [12], $kept);
        self::assertCount($seesAll ? 0 : 1, $GLOBALS['__bcc_fv']['constructed'], 'admins skip the visibility query');
    }

    public function testAnotherGroupsOwnerIsNotExempt(): void
    {
        $GLOBALS['__bcc_gjr']['memberships'][self::VIEWER][self::GROUP + 1] = 'member_owner';

        self::assertSame([], self::kept(PeepSoPrivacy::Open, self::rows([12, 13])));
    }

    public function testClosedAndSecretRostersAreForTheirMembersAndUntouched(): void
    {
        foreach ([PeepSoPrivacy::Closed, PeepSoPrivacy::Secret] as $privacy) {
            self::assertSame([12, 13], self::kept($privacy, self::rows([12, 13])));
        }
        self::assertSame([], $GLOBALS['__bcc_fv']['constructed'], 'no visibility query for a members-only roster');
    }

    public function testAnEmptyPageAsksNothing(): void
    {
        self::assertSame([], self::kept(PeepSoPrivacy::Open, []));
        self::assertSame([], $GLOBALS['__bcc_fv']['constructed']);
    }

    /**
     * has_more must follow the RAW page (clients advance by offset + limit),
     * and the filter must run before hydration so a hidden member is never
     * summarised. A structural pin on listMembers() — the behaviour of the
     * rule itself is proven above against the real filterVisible().
     */
    public function testListMembersCountsTheRawPageThenFiltersBeforeHydrating(): void
    {
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/GroupMembersService.php');
        $body = substr($src, (int) strpos($src, 'public function listMembers('));
        $body = substr($body, 0, (int) strpos($body, 'private static function rowsTheViewerMaySee('));

        $hasMore = strpos($body, '$hasMore = ($offset + count($rows)) < $total;');
        $filter  = strpos($body, '$rows = self::rowsTheViewerMaySee($ctx->privacy, $rows, $viewerId, $groupId);');
        $prime   = strpos($body, 'MemberSummaryPrefetcher::primeFor(');

        self::assertNotFalse($hasMore);
        self::assertNotFalse($filter);
        self::assertNotFalse($prime);
        self::assertLessThan($filter, $hasMore, 'has_more from the raw page');
        self::assertLessThan($prime, $filter, 'filtered before any member is hydrated');
        self::assertSame(1, substr_count($body, '$hasMore ='), 'has_more computed once');
    }
}
