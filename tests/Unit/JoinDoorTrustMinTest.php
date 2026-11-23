<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Core\Repositories\PostMetaRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\MyGroupsEndpoint;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-463: the plain join door's trust minimum (`_bcc_trust_gate_min`) is read
 * through bcc-core's PostMetaRepository (ProbesReads, never get_post_meta());
 * a minimum that cannot be read refuses the join before any write.
 *
 *   - no row (or only empty rows) is 0: a real answer, the join proceeds;
 *   - duplicate rows: the HIGHEST counts;
 *   - a non-empty value that is not a number is unknown → 503, nothing written;
 *   - a read that cannot be trusted → 503, nothing written.
 *
 * The meta stub in my-groups-oracle-stubs.php answers '' (no minimum) for
 * every group, as a failed meta load would; core's answer is set per test,
 * so a door that still read get_post_meta() would visibly let a member in.
 *
 * Separate processes: the stubs declare final classes at their FQNs.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class JoinDoorTrustMinTest extends TestCase
{
    private const USER  = 140;
    private const GROUP = 4301;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/my-groups-oracle-stubs.php';
        PostMetaRepository::reset();

        $GLOBALS['__bcc_susp_fixture'] = ['user_id' => self::USER, 'suspended' => false, 'throttled' => false];
        $GLOBALS['__bcc_mg'] = [
            'contexts'    => [
                self::GROUP => new GroupContext(self::GROUP, GroupType::User, PeepSoPrivacy::Open, false, null, null, null),
            ],
            'memberships' => [],
            'writes'      => [],
        ];

        $class  = new ReflectionClass(GroupsService::class);
        $groups = $class->newInstanceWithoutConstructor();
        $class->getProperty('groupContextResolver')->setValue($groups, new GroupContextResolver());
        (new ReflectionClass(Plugin::class))->getProperty('groupsService')->setValue(Plugin::instance(), $groups);
        self::viewerScore(40);
    }

    private static function viewerScore(?int $score): void
    {
        $rep = new class ($score) extends ReputationRepository {
            public function __construct(private ?int $score)
            {
            }

            public function getScore(int $userId): float
            {
                return (float) ($this->score ?? 50);
            }

            // F-469: the join gate reads the tri-state; null = unreadable.
            public function getScoreOrUnknown(int $userId): ?float
            {
                return $this->score === null ? null : (float) $this->score;
            }
        };
        (new ReflectionClass(Plugin::class))->getProperty('reputationRepository')->setValue(Plugin::instance(), $rep);
    }

    /** @param list<string>|null $rows null = the read failed */
    private static function core(?array $rows): void
    {
        PostMetaRepository::$answers[self::GROUP] = $rows === null ? null : ($rows === [] ? [] : ['_bcc_trust_gate_min' => $rows]);
    }

    /** @return array{0: int, 1: mixed} */
    private static function join(): array
    {
        $response = (new MyGroupsEndpoint())->postJoin(new \WP_REST_Request(['id' => self::GROUP]));

        return [$response->get_status(), $response->get_data()];
    }

    /** @return iterable<string, array{0: list<string>, 1: int, 2: bool}> */
    public static function minimums(): iterable
    {
        // rows, viewer score, joins?
        yield 'no row is no minimum'               => [[], 0, true];
        yield 'an empty row is no minimum'         => [[''], 0, true];
        yield 'below the minimum is refused'       => [['50'], 40, false];
        yield 'at the minimum joins'               => [['50'], 50, true];
        yield 'the HIGHEST duplicate counts'       => [['25', '75'], 50, false];
        yield 'the highest, in either order'       => [['75', '25'], 50, false];
        yield 'above the highest duplicate joins'  => [['25', '75'], 80, true];
    }

    /** @param list<string> $rows */
    #[DataProvider('minimums')]
    public function testTheMinimumComesFromCoresRowsNotTheMetaStub(array $rows, int $score, bool $joins): void
    {
        self::core($rows);
        self::viewerScore($score);

        [$status] = self::join();

        if ($joins) {
            self::assertSame(200, $status);
            self::assertSame([['join', self::USER, self::GROUP]], $GLOBALS['__bcc_mg']['writes']);
        } else {
            self::assertSame(403, $status);
            self::assertSame([], $GLOBALS['__bcc_mg']['writes'], 'refused before the write');
        }
    }

    /** @return iterable<string, array{0: list<string>|null}> */
    public static function unknowns(): iterable
    {
        yield 'the read failed'                 => [null];
        yield 'a non-numeric value'             => [['abc']];
        yield 'a non-numeric beside a number'   => [['25', 'fifty']];
    }

    /**
     * ⚠ Unknown refuses (retryable 503) BEFORE the write, never reads as 0.
     *
     * @param list<string>|null $rows
     */
    #[DataProvider('unknowns')]
    public function testAMinimumThatCannotBeReadRefusesBeforeAnyWrite(?array $rows): void
    {
        self::core($rows);
        self::viewerScore(100);

        [$status, $data] = self::join();

        self::assertSame(503, $status);
        self::assertSame('bcc_unavailable', $data['error']['code'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_mg']['writes'], 'nothing was joined');
    }

    /**
     * F-469: the viewer's score that could not be read refuses the join,
     * retryably, before any write. Never the neutral 50, which would let a
     * low-trust member through a 50 minimum.
     */
    public function testAScoreThatCannotBeReadRefusesBeforeAnyWrite(): void
    {
        self::core(['50']);
        self::viewerScore(null);

        [$status, $data] = self::join();

        self::assertSame(503, $status);
        self::assertSame('bcc_unavailable', $data['error']['code'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_mg']['writes'], 'nothing was joined');
    }

    public function testTheDoorReadsOnlyTheMinimumInOneCall(): void
    {
        self::core([]);

        self::join();

        self::assertSame([['ids' => [self::GROUP], 'keys' => ['_bcc_trust_gate_min']]], PostMetaRepository::$calls);
    }
}
