<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\MemberDirectoryTypeCounts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The /members chip counts are counted per viewer, through the list's own
 * privacy filter, so a chip equals the list's total for that type.
 * Rationale: F-428 (private tracker).
 *
 * The fake search models what the code relies on from PeepSoUserSearch
 * (a WP_User_Query underneath):
 *   - `total` is the number of members this viewer may list, out of
 *     `include` — PeepSo's privacy clauses run inside the query;
 *   - `include => []` is NO constraint: WP_User_Query then counts every
 *     member the viewer may list, which is why an empty owner set must
 *     never reach it.
 * Injected rather than declared as a global `\PeepSoUserSearch`: several
 * unit tests already declare one, and the first loaded would win.
 */
#[CoversClass(MemberDirectoryTypeCounts::class)]
final class MemberDirectoryTypeCountsTest extends TestCase
{
    /** Every member account in the fixture. */
    private const MEMBERS = [1, 2, 3, 4, 5, 6, 7, 8];

    /** Members a signed-in viewer may not list (hidden, suspended, blocked …). */
    private const HIDDEN_FROM_SIGNED_IN = [3, 4];

    /** A signed-out viewer additionally may not list members-only profiles. */
    private const HIDDEN_FROM_ANONYMOUS = [3, 4, 5];

    private const OWNERS = [
        'validator' => [1, 3, 5],
        'project'   => [2],
        'nft'       => [],
        'dao'       => [3, 4],
    ];

    private const VIEWER = 6;

    /** @var list<array{args: array<string, mixed>, viewer: int|null, term: string}> */
    private array $searches = [];

    /** @var list<string> */
    private array $ownerReads = [];

    private function counter(bool $peepso = true): MemberDirectoryTypeCounts
    {
        $ownersOf = function (string $type): array {
            $this->ownerReads[] = $type;
            return self::OWNERS[$type];
        };

        $newSearch = function (array $args, ?int $viewer, string $term): object {
            $this->searches[] = ['args' => $args, 'viewer' => $viewer, 'term' => $term];

            /** @var list<int> $include */
            $include = $args['include'] ?? [];
            $pool    = $include === [] ? self::MEMBERS : $include;   // WP: empty include = no constraint
            $hidden  = $viewer === null ? self::HIDDEN_FROM_ANONYMOUS : self::HIDDEN_FROM_SIGNED_IN;
            $listed  = array_values(array_diff($pool, $hidden));

            return new class ($listed) {
                /** @var list<int> */
                public array $results;
                public int $total;

                /** @param list<int> $listed */
                public function __construct(array $listed)
                {
                    $this->results = array_slice($listed, 0, 1);
                    $this->total   = count($listed);
                }
            };
        };

        return new MemberDirectoryTypeCounts(\Closure::fromCallable($ownersOf), $peepso ? \Closure::fromCallable($newSearch) : null);
    }

    public function testEachChipCountsOnlyTheOwnersThisViewerMayList(): void
    {
        self::assertSame(
            ['validator' => 2, 'project' => 1, 'nft' => 0, 'dao' => 0],
            $this->counter()->forViewer(self::VIEWER)
        );
    }

    public function testASignedOutViewerGetsTheSignedOutFilter(): void
    {
        $counts = $this->counter()->forViewer(0);

        self::assertSame(['validator' => 1, 'project' => 1, 'nft' => 0, 'dao' => 0], $counts);
        self::assertSame([null, null, null], array_column($this->searches, 'viewer'), 'anonymous is passed as null, as members() does');
    }

    public function testAnEmptyOwnerSetIsZeroAndNeverSearched(): void
    {
        $counts = $this->counter()->forViewer(self::VIEWER);

        self::assertSame(0, $counts['nft']);
        self::assertCount(3, $this->searches, 'no search for the type nobody owns');
        foreach ($this->searches as $search) {
            self::assertNotSame([], $search['args']['include']);
        }
    }

    public function testTheSearchIsTheListsSearchForOneTypeWithAnEmptyTerm(): void
    {
        $this->counter()->forViewer(self::VIEWER);

        self::assertSame(
            [
                'args'   => ['number' => 1, 'offset' => 0, 'fields' => 'ID', 'count_total' => true, 'include' => [1, 3, 5]],
                'viewer' => self::VIEWER,
                'term'   => '',
            ],
            $this->searches[0]
        );
        self::assertSame(['validator', 'project', 'nft', 'dao'], $this->ownerReads);
    }

    public function testWithoutPeepSoEveryChipIsZero(): void
    {
        self::assertSame(
            ['validator' => 0, 'project' => 0, 'nft' => 0, 'dao' => 0],
            $this->counter(peepso: false)->forViewer(self::VIEWER)
        );
        self::assertSame([], $this->ownerReads, 'nothing is read when nothing can be counted');
    }
}
