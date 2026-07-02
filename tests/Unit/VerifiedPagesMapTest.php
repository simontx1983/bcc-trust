<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ClaimRepository;
use PHPUnit\Framework\TestCase;

/**
 * Pins ClaimRepository::getVerifiedPagesMap() — the presence-map wrapper
 * over getPrimaryClaimsByPageIds() that CardsSearchEndpoint /
 * PageReadModelRepository use for the boolean is_claim_verified flag.
 *
 * The verified-operator/creator SQL filter itself lives in
 * getPrimaryClaimsByPageIds() (exercised end-to-end by the integration
 * suite). Here we pin ONLY the wrapper's contract: exactly the page_ids
 * the source method returns (a claimer name means a verified primary
 * claim) become `true`; a page absent from the source (its only claim is
 * pending or a non-primary holder role, so the filter dropped it) stays
 * absent — never present-but-false.
 *
 * Isolation: a subclass overrides the DB-touching source method; the
 * wrapper reaches it via late static binding, so no MySQL is needed.
 */
final class VerifiedPagesMapTest extends TestCase
{
    public function testOnlyVerifiedPrimaryClaimPagesAreMappedTrue(): void
    {
        // Source method returns page 10 (verified operator) and page 20
        // (verified creator); pages 30 (pending) and 40 (holder-only) are
        // ALREADY excluded by getPrimaryClaimsByPageIds' WHERE clause, so
        // they never appear in its output — the fixture models that.
        $stub = new class extends ClaimRepository {
            /**
             * @param int[] $pageIds
             * @return array<int, string>
             */
            public static function getPrimaryClaimsByPageIds(array $pageIds): array
            {
                return [10 => 'Alice', 20 => 'Bob'];
            }
        };

        $map = $stub::getVerifiedPagesMap([10, 20, 30, 40]);

        self::assertTrue($map[10] ?? false);
        self::assertTrue($map[20] ?? false);
        self::assertArrayNotHasKey(30, $map, 'pending claim → dropped by source filter → absent');
        self::assertArrayNotHasKey(40, $map, 'holder-only claim → not primary → absent');
        // Values are strictly `true`, never `false` — presence IS the flag.
        self::assertSame([10 => true, 20 => true], $map);
    }

    public function testNoVerifiedClaimsYieldsEmptyMap(): void
    {
        $stub = new class extends ClaimRepository {
            /**
             * @param int[] $pageIds
             * @return array<int, string>
             */
            public static function getPrimaryClaimsByPageIds(array $pageIds): array
            {
                return [];
            }
        };

        self::assertSame([], $stub::getVerifiedPagesMap([1, 2, 3]));
    }
}
