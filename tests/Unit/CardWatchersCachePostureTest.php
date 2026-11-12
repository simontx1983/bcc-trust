<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\CardWatchersEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `GET /entities/:kind/:id/watchers` cache posture (#576 review).
 *
 * The anonymous response is `public, max-age=30` and used to carry NO
 * `Vary`, while the signed-in one did. The body depends on the viewer, so a
 * shared cache must key on both credential channels for EVERY response,
 * or it may serve the anonymous copy to a signed-in request.
 * Same shape as HotFeedCachePostureTest.
 */
#[CoversClass(CardWatchersEndpoint::class)]
final class CardWatchersCachePostureTest extends TestCase
{
    public function testTheAnonymousResponseCarriesVary(): void
    {
        self::assertSame('Authorization, Cookie', self::headers(0)['Vary'] ?? null);
    }

    public function testTheSignedInResponseCarriesVaryAndIsPrivate(): void
    {
        $h = self::headers(42);

        self::assertSame('Authorization, Cookie', $h['Vary'] ?? null);
        self::assertSame('private, max-age=30', $h['Cache-Control']);
    }

    /** Anti-vacuity: the anonymous posture itself is unchanged. */
    public function testTheAnonymousPostureIsUnchanged(): void
    {
        self::assertSame('public, max-age=30', self::headers(0)['Cache-Control']);
        self::assertSame('public, max-age=30', self::headers(-1)['Cache-Control']);
    }

    /** @return array<string, string> */
    private static function headers(int $viewerId): array
    {
        $m = (new ReflectionClass(CardWatchersEndpoint::class))->getMethod('cacheHeaders');
        $m->setAccessible(true);

        /** @var array<string, string> $out */
        $out = $m->invoke(null, $viewerId);

        return $out;
    }
}
