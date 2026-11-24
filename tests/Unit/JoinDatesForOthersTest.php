<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\Feed\PageClaimBodyHydrator;
use BCC\Trust\Core\Services\GroupMembersService;
use BCC\Trust\Core\Support\PublicIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * R4 / R12 / R13: other users never receive when someone joined a group,
 * and see only the MONTH a member joined BCC. A claim post's body carries
 * no separate claim time; the post's own posted_at is its one time. The
 * member still receives their own exact data, and admin routes read the
 * stored values directly.
 *
 * - The rule for `joined_at` (account creation) is
 *   PublicIdentity::joinedAt(), pinned here by behaviour; MemberProfile
 *   and MemberSummary (UserViewService) route through it (pinned at source
 *   level, as the other wiring tests here do).
 * - The group roster sends `joined_at` to site admins only.
 * - Every place that emits `joined_at` is one reviewed for this rule: a
 *   new one fails the last test until it is reviewed.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class JoinDatesForOthersTest extends TestCase
{
    private const EXACT = '2025-03-17T14:22:05Z';
    private const MONTH = '2025-03-01T00:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        if (!function_exists('user_can')) {
            eval('function user_can(int $userId, string $cap): bool { return $cap === "manage_options" && in_array($userId, $GLOBALS["__r4_admins"] ?? [], true); }');
        }
        if (!function_exists('apply_filters')) {
            eval('function apply_filters(string $hook, $value, ...$args) { return $value; }');
        }
        $GLOBALS['__r4_admins'] = [];
    }

    // ── joined_at: the rule ──────────────────────────────────────────

    public function testTheMemberReceivesTheirOwnExactTime(): void
    {
        self::assertSame(self::EXACT, PublicIdentity::joinedAt(7, self::EXACT, 7));
    }

    public function testAnotherMemberReceivesTheMonthOnly(): void
    {
        self::assertSame(self::MONTH, PublicIdentity::joinedAt(7, self::EXACT, 8));
    }

    public function testAnAnonymousViewerReceivesTheMonthOnly(): void
    {
        self::assertSame(self::MONTH, PublicIdentity::joinedAt(7, self::EXACT));
        self::assertSame(self::MONTH, PublicIdentity::joinedAt(7, self::EXACT, 0));
    }

    /** No member id is never "the member themselves". */
    public function testUserZeroIsNeverSelf(): void
    {
        self::assertSame(self::MONTH, PublicIdentity::joinedAt(0, self::EXACT, 0));
    }

    /** @return iterable<string, array{string, string}> */
    public static function days(): iterable
    {
        yield 'mid-month'      => ['2025-03-17T14:22:05Z', '2025-03-01T00:00:00Z'];
        yield 'last second'    => ['2025-03-31T23:59:59Z', '2025-03-01T00:00:00Z'];
        yield 'first midnight' => ['2024-12-01T00:00:00Z', '2024-12-01T00:00:00Z'];
        yield 'leap day'       => ['2024-02-29T08:00:00Z', '2024-02-01T00:00:00Z'];
    }

    #[DataProvider('days')]
    public function testEveryDayOfAMonthReadsAsItsFirst(string $exact, string $month): void
    {
        self::assertSame($month, PublicIdentity::joinedAt(7, $exact, 8));
    }

    public function testUnknownStaysUnknown(): void
    {
        self::assertSame('', PublicIdentity::joinedAt(7, '', 8));
    }

    /** For another viewer, nothing that is not such a date passes through. */
    public function testANonDateIsNeverPassedThrough(): void
    {
        foreach (['garbage', '2025-03', '17/03/2025', ' 2025-03-17T14:22:05Z'] as $value) {
            self::assertSame('', PublicIdentity::joinedAt(7, $value, 8), $value);
        }
    }

    /**
     * MemberProfile (getUser) and MemberSummary (getSummary: review and
     * reply authors, group request and invite items, rosters, cards) both
     * route `joined_at` through the rule with the viewer.
     */
    public function testProfileAndSummaryRouteJoinedAtThroughTheRule(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/UserViewService.php');

        $routed = preg_match_all(
            '/\'joined_at\'\s*=>\s*\\\\BCC\\\\Trust\\\\Core\\\\Support\\\\PublicIdentity::joinedAt\(\s*'
            . '\$userId,\s*self::toIso8601\(\(string\) \$user->user_registered\),\s*\$viewerId\s*\)/',
            $src
        );
        self::assertSame(2, $routed, 'getUser() and getSummary()');
        self::assertSame(0, preg_match('/\'joined_at\'\s*=>\s*self::toIso8601/', $src), 'no raw joined_at left');
    }

    // ── The group roster ─────────────────────────────────────────────

    /** @return iterable<string, array{int, list<int>, bool}> */
    public static function viewers(): iterable
    {
        yield 'anonymous'                 => [0, [], false];
        yield 'a member'                  => [12, [], false];
        yield 'a site admin'              => [12, [12], true];
        yield 'someone else is the admin' => [12, [99], false];
    }

    /** @param list<int> $admins */
    #[DataProvider('viewers')]
    public function testOnlySiteAdminsReceiveRosterJoinTimes(int $viewerId, array $admins, bool $sees): void
    {
        $GLOBALS['__r4_admins'] = $admins;
        $m = new ReflectionMethod(GroupMembersService::class, 'seesJoinTimes');

        self::assertSame($sees, $m->invoke(null, $viewerId));
    }

    public function testARosterRowForOthersCarriesNoJoinTime(): void
    {
        $row = (object) [
            'user_id'       => '5',
            'role'          => 'member_owner',
            'joined_at'     => '2025-03-17 14:22:05',
            'gm_joined_utc' => '1742221325',
        ];
        $m = new ReflectionMethod(GroupMembersService::class, 'rosterFields');

        $forOthers = $m->invoke(null, $row, false);
        self::assertSame(['role' => 'owner', 'role_label' => 'OWNER'], $forOthers, 'the key is absent, not null');

        $forAdmins = $m->invoke(null, $row, true);
        self::assertIsArray($forAdmins);
        self::assertSame(self::EXACT, $forAdmins['joined_at'] ?? null, 'control: site admins keep it');
    }

    /** listMembers() builds each row from rosterFields(), decided once per page. */
    public function testListMembersMergesOnlyTheRosterFields(): void
    {
        $src  = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/GroupMembersService.php'));
        $body = substr($src, (int) strpos($src, 'public function listMembers('));
        $body = substr($body, 0, (int) strpos($body, "\n    }\n"));

        self::assertStringContainsString('$withJoinTimes = self::seesJoinTimes($viewerId);', $body);
        self::assertStringContainsString('array_merge($summary, self::rosterFields($row, $withJoinTimes))', $body);
        self::assertStringNotContainsString('joined_at', $body);
    }

    // ── The page-claim post body ─────────────────────────────────────

    public function testAClaimPostBodyCarriesNoClaimTime(): void
    {
        if (!class_exists(\BCC\Trust\Onchain\Repositories\ClaimRepository::class, false)) {
            eval('namespace BCC\Trust\Onchain\Repositories; final class ClaimRepository { '
                . '/** @param list<int> $ids */ public static function findManyByIds(array $ids): array { return $GLOBALS["__r4_claims"]; } }');
        }
        $GLOBALS['__r4_claims'] = [
            3 => (object) [
                'id'          => '3',
                'entity_type' => 'page',
                'entity_id'   => '41',
                'claim_role'  => 'owner',
                'verified_at' => '2025-03-17 14:22:05',
            ],
        ];

        $bodies = (new PageClaimBodyHydrator())->loadPageClaimBodies([3]);

        self::assertSame(['claim_id', 'entity_type', 'entity_id', 'role', 'summary'], array_keys($bodies[3]));
        self::assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}/', (string) json_encode($bodies));
    }

    // ── Every joined_at producer is a reviewed one ───────────────────

    /**
     * Files under app/ that emit or read `joined_at`, each reviewed for this
     * rule. A new one fails here until it is reviewed and listed.
     */
    public function testEveryJoinedAtProducerIsAReviewedOne(): void
    {
        $reviewed = [
            // viewer_membership: the VIEWER's own membership of a group.
            'app/Domain/Core/Services/GroupsService.php',
            // viewer_membership: the VIEWER's own Hall memberships.
            'app/Domain/Core/Services/HallsService.php',
            // The roster: site admins only.
            'app/Domain/Core/Services/GroupMembersService.php',
            // MemberProfile / MemberSummary: through PublicIdentity::joinedAt().
            'app/Domain/Core/Services/UserViewService.php',
            // Reads getUser()'s value for "Since YYYY" and "Joined Mon YYYY".
            'app/Domain/Core/Services/MemberProfileComposer.php',
        ];
        sort($reviewed);

        $root  = dirname(__DIR__, 2);
        $found = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $tok) {
                if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING && trim($tok[1], '\'"') === 'joined_at') {
                    $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    break;
                }
            }
        }
        sort($found);

        self::assertSame($reviewed, $found);
    }
}
