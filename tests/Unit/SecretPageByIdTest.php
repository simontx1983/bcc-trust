<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\DTO\VoteEligibilityCacheEntry;
use BCC\Trust\Core\Exceptions\VoteEligibilityException;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\PageFollowRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Repositories\WatchingRepository;
use BCC\Trust\Core\Repositories\WatchMetaRepository;
use BCC\Trust\Core\Services\Vote\VoteEligibilityChecker;
use BCC\Trust\Core\Services\WatchingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Writes that name a SECRET page by id: reviewing it, and watching it.
 *
 * Both used to reach a secret page for anyone who knew its id. A review
 * belled the owner, wrote a public feed item and counted as rank evidence; a
 * watch wrote the follow and belled the owner, and its "watched" answer
 * differed from a missing page's 404. A viewer the page does not admit now
 * gets exactly the missing-page answer, before anything is written.
 *
 * The REAL bcc-core rule (PeepSoPagePrivacy), the real PageAccess,
 * VoteEligibilityChecker and WatchingService, over a `$wpdb` that answers the
 * privacy reads (tests/Stubs/secret-page-list-stubs.php). Stubbed: bcc-core's
 * follow writer and block read (block-follow-stubs.php), and the Plugin
 * services check() calls (secret-page-by-id-stubs.php).
 *
 * How a test sees a viewer get PAST the page gate:
 *  - a review: check() goes on to the voter's cached eligibility verdict,
 *    seeded here as a refusal with its own message (PAST_THE_PAGE_GATE);
 *  - a watch: the follow write is attempted (BlockFollowWorld::$followCalls).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SecretPageByIdTest extends TestCase
{
    private const VIEWER      = 5;
    private const OWNER       = 70;
    private const OPEN        = 10;
    private const SECRET      = 20;
    private const PLACEHOLDER = 40; // secret, and unclaimed (post_author 0)
    private const MISSING     = 999;

    private const PAST_THE_PAGE_GATE = 'cached refusal: the voter got past the page gate';

    private \BccSecretPageListWpdb $db;

    protected function setUp(): void
    {
        // Doubles first: they must exist before the real classes autoload.
        require_once dirname(__DIR__) . '/Stubs/secret-page-by-id-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/block-follow-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/profile-access-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/secret-page-list-stubs.php';
        \bcc_spl_load_core();

        BlockFollowWorld::reset();
        Plugin::$ownerCalls = [];

        $GLOBALS['__bcc_profile_access'] = ['viewer_id' => self::VIEWER, 'is_admin' => false];
        $GLOBALS['__bcc_spl'] = [
            'secret' => [self::SECRET => true, self::PLACEHOLDER => true],
            'posts'  => [
                self::OPEN        => \bcc_spl_post(self::OPEN, self::OWNER),
                self::SECRET      => \bcc_spl_post(self::SECRET, self::OWNER),
                self::PLACEHOLDER => \bcc_spl_post(self::PLACEHOLDER, 0),
            ],
        ];
        $GLOBALS['__bcc_cache'] = [
            'bcc_trust' => [
                'eligibility_' . self::VIEWER . '_1' => new VoteEligibilityCacheEntry(false, self::PAST_THE_PAGE_GATE),
                'eligibility_' . self::OWNER . '_1'  => new VoteEligibilityCacheEntry(false, self::PAST_THE_PAGE_GATE),
            ],
        ];

        // The peepso_pages table holds every fixture page.
        $this->db        = new \BccSecretPageListWpdb();
        $this->db->other = static function (string $sql): array {
            if (str_starts_with($sql, 'SHOW TABLES LIKE')) {
                return [(object) ['t' => 'wp_peepso_pages']];
            }
            if (preg_match('/FROM wp_peepso_pages WHERE ID = (\d+)/', $sql, $m) === 1
                && isset($GLOBALS['__bcc_spl']['posts'][(int) $m[1]])
            ) {
                return [(object) ['ID' => $m[1]]];
            }
            return [];
        };
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['__bcc_spl'], $GLOBALS['__bcc_profile_access'], $GLOBALS['__bcc_cache']);
    }

    // ── a review (VoteEligibilityChecker::check) ────────────────────────

    public function testAReviewOfASecretPageTheVoterIsNotInIsRefusedAsAMissingPageIs(): void
    {
        $missing = $this->refusal(self::VIEWER, self::MISSING);
        $secret  = $this->refusal(self::VIEWER, self::SECRET);

        self::assertSame('Invalid page', $missing->getMessage());
        self::assertSame([get_class($missing), $missing->getMessage(), $missing->getCode()], [get_class($secret), $secret->getMessage(), $secret->getCode()]);
    }

    public function testTheReviewRefusalComesBeforeAnythingElseAboutThePage(): void
    {
        $this->refusal(self::VIEWER, self::SECRET);

        self::assertSame([], Plugin::$ownerCalls, 'the owner is never looked up');
    }

    /** @return iterable<string, array{0: list<string>}> */
    public static function admitted(): iterable
    {
        yield 'member'    => [['member']];
        yield 'owner row' => [['member_owner']];
        yield 'invited'   => [['pending_user']];
    }

    /** @param list<string> $statuses */
    #[DataProvider('admitted')]
    public function testAReviewerThePageAdmitsGetsPastThePageGate(array $statuses): void
    {
        $GLOBALS['__bcc_spl']['statuses'][self::SECRET][self::VIEWER] = $statuses;

        self::assertSame(self::PAST_THE_PAGE_GATE, $this->refusal(self::VIEWER, self::SECRET)->getMessage());
    }

    public function testABannedReviewerIsRefusedAsAMissingPageIs(): void
    {
        $GLOBALS['__bcc_spl']['statuses'][self::SECRET][self::VIEWER] = ['member', 'banned'];

        self::assertSame('Invalid page', $this->refusal(self::VIEWER, self::SECRET)->getMessage());
    }

    public function testThePagesAuthorGetsPastThePageGate(): void
    {
        self::assertSame(
            'Page owner cannot vote on their own page.',
            $this->refusal(self::OWNER, self::SECRET)->getMessage(),
            'the next check that runs, and the reason the author cannot review their own page'
        );
    }

    public function testAnAdminGetsPastThePageGate(): void
    {
        $GLOBALS['__bcc_profile_access']['is_admin'] = true;

        self::assertSame(self::PAST_THE_PAGE_GATE, $this->refusal(self::VIEWER, self::SECRET)->getMessage());
    }

    public function testAnOpenPageIsReviewableByAnyoneForOnePrivacyRead(): void
    {
        self::assertSame(self::PAST_THE_PAGE_GATE, $this->refusal(self::VIEWER, self::OPEN)->getMessage());
        self::assertCount(1, $this->db->ofKind('single'));
        self::assertSame([], $this->db->ofKind('member'), 'an open page asks about nobody');
    }

    public function testAFailedPrivacyReadRefusesTheReviewAsAMissingPage(): void
    {
        $GLOBALS['__bcc_spl']['fail_single'] = true;

        self::assertSame('Invalid page', $this->refusal(self::VIEWER, self::OPEN)->getMessage());
    }

    // ── a watch (WatchingService::watch) ────────────────────────────────

    /** @return iterable<string, array{0: string}> */
    public static function pageKinds(): iterable
    {
        yield 'validator' => ['validator'];
        yield 'project'   => ['project'];
        yield 'creator'   => ['creator'];
    }

    #[DataProvider('pageKinds')]
    public function testWatchingASecretPageTheViewerIsNotInAnswersAsAMissingPage(string $kind): void
    {
        $missing = $this->service()->watch(self::VIEWER, $kind, self::MISSING);

        self::assertSame(['error' => 'bcc_not_found', 'message' => 'Target not found.'], $missing);
        self::assertSame($missing, $this->service()->watch(self::VIEWER, $kind, self::SECRET));
        self::assertSame($missing, $this->service()->watch(self::VIEWER, $kind, self::PLACEHOLDER), 'an unclaimed secret page too');
        self::assertSame([], BlockFollowWorld::$followCalls, 'no follow is written, so nobody is belled');
    }

    public function testAMemberOfTheSecretPageGetsPastTheWatchGate(): void
    {
        $GLOBALS['__bcc_spl']['statuses'][self::SECRET][self::VIEWER] = ['member'];

        $this->service()->watch(self::VIEWER, 'validator', self::SECRET);

        self::assertSame([[self::VIEWER, self::OWNER]], BlockFollowWorld::$followCalls, "the follow of the page's owner is attempted");
    }

    public function testThePagesAuthorGetsPastTheWatchGate(): void
    {
        $result = $this->service()->watch(self::OWNER, 'validator', self::SECRET);

        self::assertSame(
            ['error' => 'bcc_invalid_request', 'message' => 'You cannot watch yourself.'],
            $result,
            'the next check that runs'
        );
    }

    public function testAnOpenPageIsWatchableByAnyone(): void
    {
        $this->service()->watch(self::VIEWER, 'validator', self::OPEN);

        self::assertSame([[self::VIEWER, self::OWNER]], BlockFollowWorld::$followCalls);
    }

    public function testAFailedPrivacyReadAnswersTheWatchAsAMissingPage(): void
    {
        $GLOBALS['__bcc_spl']['fail_single'] = true;

        self::assertSame(
            ['error' => 'bcc_not_found', 'message' => 'Target not found.'],
            $this->service()->watch(self::VIEWER, 'validator', self::OPEN)
        );
        self::assertSame([], BlockFollowWorld::$followCalls);
    }

    public function testWatchingAMemberNeverAsksAboutPages(): void
    {
        // The same number as the secret page: a person, not a page.
        $this->service()->watch(self::VIEWER, 'member', self::SECRET);

        self::assertSame([[self::VIEWER, self::SECRET]], BlockFollowWorld::$followCalls);
        self::assertSame([], $this->db->ofKind('single'), 'no page privacy read for a member');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** check() for an upvote, which every case here expects to refuse. */
    private function refusal(int $voterId, int $pageId): VoteEligibilityException
    {
        $checker = new VoteEligibilityChecker(
            (new \ReflectionClass(VoteRepository::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(ReputationRepository::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(UserInfoRepository::class))->newInstanceWithoutConstructor(),
        );

        try {
            $checker->check($voterId, $pageId, 1);
        } catch (VoteEligibilityException $e) {
            return $e;
        }

        self::fail('check() let the vote through');
    }

    private function service(): WatchingService
    {
        return new WatchingService(
            new WatchingRepository(),
            (new \ReflectionClass(WatchMetaRepository::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(ReputationRepository::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(PageFollowRepository::class))->newInstanceWithoutConstructor(),
        );
    }
}
