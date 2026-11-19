<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\MemberSearchCandidates;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-432: member matching can say "I could not look", not only "nobody matched".
 *
 * MemberSearchCandidates::ids() matches through two WP_User_Query lookups
 * (display name, then @handle) plus a user-row read between them. WordPress
 * answers a failed lookup with [] and a `last_error`, so ids() used to answer
 * a database failure as "nobody matched", and bcc-search's users vertical
 * then answered 200 with no members.
 *
 * With the appended $throwOnReadFailure (bcc-search's caller passes it):
 *   - each of the three steps that did not run throws RepositoryReadFailure,
 *     which bcc-search's UserSearchController answers as 503;
 *   - both lookups run with `cache_results => false`. WP_User_Query caches
 *     even a FAILED lookup's [] in `user-queries`, and the persistent object
 *     cache then serves it until any user changes (measured on the Local
 *     site, WP 7.0.2);
 *   - an error left by an earlier, unrelated query is not blamed on it.
 * Without the flag, the /members directory and the @-mention picker
 * behave exactly as before: same arguments, same answer, no throw.
 *
 * The get_users() double models the two lookups as the matcher makes them
 * (as in MentionSearchNoLoginOracleTest) and fails the way WordPress does:
 * [] plus `last_error`.
 */
#[CoversClass(MemberSearchCandidates::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MemberSearchCandidatesReadFailureTest extends TestCase
{
    private const DB_ERROR = 'Lost connection to MySQL server during query';

    protected function setUp(): void
    {
        parent::setUp();
        // id => [login, display_name, handle]
        $GLOBALS['__msc'] = [
            'users' => [
                7 => ['u_alice', 'Alice Smith', 'alice'],
                8 => ['u_bob', 'Bob Alison', 'bobby'],
            ],
            'fail'  => null,  // 'name' | 'prime' | 'handle'
            'calls' => [],
        ];
        if (!class_exists('BCC\\Core\\Log\\Logger', false)) {
            eval('namespace BCC\\Core\\Log; final class Logger { public static array $lines = []; public static function error(string $m, array $c = []): void { self::$lines[] = $m . " " . json_encode($c); } public static function warning(string $m, array $c = []): void {} public static function info(string $m, array $c = []): void {} }');
        }
        $GLOBALS['wpdb'] = new class () {
            public string $last_error = '';
        };
        eval(<<<'PHP'
            function get_users(array $args = []): array
            {
                $GLOBALS['__msc']['calls'][] = $args;
                $lookup = isset($args['search']) ? 'name' : (($args['meta_key'] ?? '') === 'bcc_handle' ? 'handle' : 'other');
                if ($GLOBALS['__msc']['fail'] === $lookup) {
                    // What wpdb leaves after a failed query: no rows, and the error.
                    $GLOBALS['wpdb']->last_error = 'Lost connection to MySQL server during query';
                    return [];
                }
                $out = [];
                foreach ($GLOBALS['__msc']['users'] as $id => [$login, $display, $handle]) {
                    $hit = $lookup === 'name'
                        ? str_contains(strtolower($display), strtolower(trim((string) $args['search'], '*')))
                        : str_contains($handle, strtolower((string) ($args['meta_value'] ?? '')));
                    if ($hit) {
                        $out[] = (string) $id;
                    }
                }
                return $out;
            }
            function cache_users(array $ids): void
            {
                if ($GLOBALS['__msc']['fail'] === 'prime') {
                    $GLOBALS['wpdb']->last_error = 'Lost connection to MySQL server during query';
                }
            }
            function get_userdata($id)
            {
                if ($GLOBALS['__msc']['fail'] === 'prime') {
                    return false; // the row could not be read
                }
                $u = $GLOBALS['__msc']['users'][(int) $id] ?? null;
                return $u === null ? false : (object) ['ID' => (int) $id, 'user_login' => $u[0], 'display_name' => $u[1]];
            }
            PHP);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__msc'], $GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function testWithNothingFailingBothModesFindTheSameMembers(): void
    {
        self::assertSame([7, 8], self::sorted(MemberSearchCandidates::ids('ali')));
        self::assertSame([7, 8], self::sorted(MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true)));
    }

    public function testFailClosedTurnsTheUserQueryCacheOffForBothLookups(): void
    {
        MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true);

        self::assertCount(2, $GLOBALS['__msc']['calls'], 'the display-name lookup and the handle lookup');
        foreach ($GLOBALS['__msc']['calls'] as $args) {
            self::assertFalse($args['cache_results'] ?? null, 'a failed lookup must not be cached as "nobody matched"');
        }
    }

    /** The /members directory and the @-mention picker: arguments and answer exactly as before. */
    public function testByDefaultAFailedLookupStillAnswersAsBefore(): void
    {
        $GLOBALS['__msc']['fail'] = 'name';

        self::assertSame([7], MemberSearchCandidates::ids('ali'), 'the handle lookup still answers; no throw');
        foreach ($GLOBALS['__msc']['calls'] as $args) {
            self::assertArrayNotHasKey('cache_results', $args);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function failingSteps(): array
    {
        return [
            'the display-name lookup'            => ['name', 'display name lookup'],
            'the user rows read between lookups' => ['prime', 'display name check'],
            'the handle lookup'                  => ['handle', 'handle lookup'],
        ];
    }

    #[DataProvider('failingSteps')]
    public function testFailClosedThrowsWhenAStepDidNotRun(string $fail, string $step): void
    {
        $GLOBALS['__msc']['fail'] = $fail;

        try {
            MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true);
            self::fail('a lookup that did not run answered as "nobody matched"');
        } catch (RepositoryReadFailure $e) {
            self::assertStringContainsString('ids: ' . $step, $e->getMessage());
            self::assertStringContainsString(self::DB_ERROR, $e->getMessage());
        }
        self::assertNotSame([], \BCC\Core\Log\Logger::$lines, 'the failure is logged, not only thrown');
    }

    public function testAnErrorLeftByAnEarlierQueryIsNotBlamedOnTheLookup(): void
    {
        $GLOBALS['wpdb']->last_error = 'an unrelated query failed earlier';

        self::assertSame([7, 8], self::sorted(MemberSearchCandidates::ids('ali', MemberSearchCandidates::MAX_CANDIDATES, true)));
    }

    public function testNobodyMatchingIsAnAnswerNotAFailure(): void
    {
        self::assertSame([], MemberSearchCandidates::ids('zzz', MemberSearchCandidates::MAX_CANDIDATES, true));
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);
        return $ids;
    }
}
