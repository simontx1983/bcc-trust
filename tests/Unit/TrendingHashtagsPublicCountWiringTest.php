<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * F-168: GET /hashtags/trending must rank on posts ANYONE can see, i.e. read
 * bcc-core's getTrendingPubliclyVisible(), never the raw-`ht_count`
 * getTrending() (which counts secret-group, members-only and private posts).
 *
 * The recount itself is proven on real MySQL by
 * tests/Integration/PublicTrendingHashtagsIntegrationTest.php; this pins the
 * endpoint to it so a revert to getTrending() turns CI red.
 */
final class TrendingHashtagsPublicCountWiringTest extends TestCase
{
    public function testTheEndpointReadsThePublicRecount(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/REST/HashtagsEndpoint.php');

        self::assertStringContainsString('PeepSoHashtagRepository::getTrendingPubliclyVisible($limit)', $source);
        self::assertDoesNotMatchRegularExpression('/PeepSoHashtagRepository::getTrending\s*\(/', $source, 'the raw ht_count ranking counts hidden posts');
    }
}
