<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\Mentions\MentionSearchService;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The @-mention picker (/users/mention-search) used to pass the raw query
 * to PeepSoUserSearch, whose own search matches first/last name plus
 * user_login: a login-existence probe (same class as F-175) that also
 * found members by a real name they had hidden (F-28).
 *
 * It now matches through MemberSearchCandidates (display name or @handle;
 * a hidden real name by handle only) and hands PeepSoUserSearch the
 * candidates as `include` with an EMPTY search term, so PeepSo still
 * applies its privacy filters in its query.
 *
 * The get_users() double models the two WP_User_Query lookups the matcher
 * makes (display_name search excluding hidden names; bcc_handle LIKE).
 */
#[CoversMethod(MentionSearchService::class, 'search')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MentionSearchNoLoginOracleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // id => [login, display_name, handle, hidden]
        $GLOBALS['__ms_users'] = [
            7 => ['u_alice', 'Alice Smith', 'alice', false],
            8 => ['secretlogin', 'Bob Jones', 'bobj', false],
            9 => ['u_quietfox', 'Carol Real', 'quietfox', true],
        ];
        $GLOBALS['__ms_ctor'] = [];
        eval(<<<'PHP'
            function get_users(array $args = []): array
            {
                $out = [];
                foreach ($GLOBALS['__ms_users'] as $id => [$login, $display, $handle, $hidden]) {
                    if (isset($args['search'])) {
                        $needle = strtolower(trim($args['search'], '*'));
                        if (!$hidden && str_contains(strtolower($display), $needle)) {
                            $out[] = (string) $id;
                        }
                    } elseif (($args['meta_key'] ?? '') === 'bcc_handle') {
                        if (str_contains($handle, strtolower((string) $args['meta_value']))) {
                            $out[] = (string) $id;
                        }
                    }
                }
                return $out;
            }
            function update_meta_cache(string $type, array $ids): void {}
            final class PeepSoUserSearch
            {
                public array $results = [];
                public function __construct(array $args, ?int $viewerId, string $search)
                {
                    $GLOBALS['__ms_ctor'][] = ['args' => $args, 'search' => $search];
                    // Empty result, so search() stops before projecting rows;
                    // the test reads WHO was handed to PeepSo instead.
                    $this->results = [];
                }
            }
            PHP);
    }

    /** @return list<int> */
    private static function ids(string $q): array
    {
        $GLOBALS['__ms_ctor'] = [];
        /** @var MentionSearchService $svc */
        $svc = (new \ReflectionClass(MentionSearchService::class))->newInstanceWithoutConstructor();
        $svc->search(0, $q, 8);
        $last = end($GLOBALS['__ms_ctor']);
        $ids  = $last === false ? [] : array_map('intval', $last['args']['include'] ?? []);
        sort($ids);
        return $ids;
    }

    public function testTheRawQueryNeverReachesPeepSoSearch(): void
    {
        self::ids('ali');
        self::assertNotSame([], $GLOBALS['__ms_ctor']);
        foreach ($GLOBALS['__ms_ctor'] as $call) {
            self::assertSame('', $call['search'], 'PeepSo must not run its own (login-matching) search');
            self::assertArrayHasKey('include', $call['args']);
        }
    }

    public function testDisplayNameAndHandleMatch(): void
    {
        self::assertSame([7], self::ids('Alice'));
        self::assertSame([8], self::ids('@bobj'));
    }

    public function testALoginDoesNotMatch(): void
    {
        self::assertSame([], self::ids('secretlogin'));
        self::assertSame([], $GLOBALS['__ms_ctor'], 'no candidates: PeepSo is not even asked');
    }

    public function testAHiddenRealNameMatchesOnlyByHandle(): void
    {
        self::assertSame([], self::ids('Carol'));
        self::assertSame([9], self::ids('quiet'));
    }
}
