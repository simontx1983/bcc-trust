<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\ViewerWatchResolver;
use PHPUnit\Framework\TestCase;

/**
 * F-255 follow-up: a card's watch state has THREE values, and the third is
 * the reason for this file.
 *
 *   - watching       → viewer_is_watching true,  viewer_watch = the handle
 *   - not watching   → viewer_is_watching false, viewer_watch null
 *   - unknown        → viewer_is_watching NULL,  viewer_watch null
 *
 * "Unknown" is a failed read. Before, a failed read was indistinguishable
 * from "not watching", so the client drew a "Watch" button on something
 * the member already watches — the very bug F-255 fixed for the >50 case.
 * Now the client gets null and shows a neutral state (user decision).
 *
 * Plus a wiring pin: suggestions run the EXACT already-following check on
 * both the scored pool and the cold-start top-up (the follow list they had
 * was capped at 200). The real-row proof is
 * SuggestionsExactFollowIntegrationTest.
 */
final class ViewerWatchStateTest extends TestCase
{
    private const HANDLE = ['follow_id' => 4812, 'source' => 'peepso'];

    public function testAWatchedIdReadsAsWatchingWithItsHandle(): void
    {
        self::assertSame(
            ['viewer_is_watching' => true, 'viewer_watch' => self::HANDLE],
            ViewerWatchResolver::fieldsFor([7 => self::HANDLE], 7)
        );
    }

    public function testAnAbsentIdReadsAsNotWatching(): void
    {
        self::assertSame(
            ['viewer_is_watching' => false, 'viewer_watch' => null],
            ViewerWatchResolver::fieldsFor([7 => self::HANDLE], 8)
        );
    }

    public function testAnUnknownIdReadsAsNullNeverFalse(): void
    {
        $fields = ViewerWatchResolver::fieldsFor([7 => ViewerWatchResolver::UNKNOWN], 7);

        self::assertNull($fields['viewer_is_watching'], 'unknown must not read as "not watching"');
        self::assertNull($fields['viewer_watch']);
    }

    public function testThePageHandleShapeIsKept(): void
    {
        $page = ['follow_id' => 31, 'source' => 'page'];

        self::assertSame($page, ViewerWatchResolver::fieldsFor([9 => $page], 9)['viewer_watch']);
    }

    public function testSuggestionsRunTheExactCheckOnPoolAndTopUp(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/SuggestionService.php');

        self::assertSame(
            3,
            substr_count($src, 'dropAlreadyFollowing('),
            'the method plus its two call sites: the scored pool and the cold-start top-up'
        );
        self::assertStringContainsString('$this->dropAlreadyFollowing($viewerId, array_keys($pool))', $src);
        self::assertStringContainsString('findActiveFollowIdsForFollowees', $src);
    }
}
