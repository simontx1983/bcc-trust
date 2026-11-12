<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\FeedEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `/feed/hot`'s cache posture, now that the response varies by viewer.
 *
 * ⚠⚠ THIS IS A SECURITY HEADER, NOT BOOKKEEPING, which is why it has its
 * own file. The route used to send
 * `public, max-age=60, stale-while-revalidate=120` with NO `Vary`, and that
 * was correct while every reader received the identical payload.
 *
 * §K1 mutual invisibility now filters a signed-in reader's hot feed. Served
 * under the old header, a shared cache — a CDN or reverse proxy — would
 * store ONE viewer's filtered feed and hand it to everyone else, including
 * logged-out visitors, for up to three minutes (60s fresh plus 120s
 * stale-while-revalidate). That leaks who that viewer blocks, by absence,
 * and is strictly worse than the unfiltered feed it replaced.
 *
 * ⭐ So the posture is asserted rather than reviewed. The whole point of
 * filtering the body is lost — and inverted — if the headers do not follow.
 */
#[CoversClass(FeedEndpoint::class)]
final class HotFeedCachePostureTest extends TestCase
{
    private const ANON_POSTURE = 'public, max-age=60, stale-while-revalidate=120';

    // ── the two that matter ─────────────────────────────────────────

    /**
     * ⚠⚠ A SIGNED-IN RESPONSE IS NEVER SHAREABLE. Asserted as "not public"
     * rather than "equals private, …" so that any future rewording of the
     * directive still cannot reintroduce a shared-cacheable authed response.
     */
    public function testASignedInResponseIsNeverPublic(): void
    {
        $cc = self::headers(42)['Cache-Control'];

        self::assertStringNotContainsString('public', $cc, $cc);
        self::assertStringContainsString('private', $cc, $cc);
    }

    /**
     * ⚠ AND THE ANONYMOUS RESPONSE CARRIES `Vary` TOO. Without it a shared
     * cache does not know the response depends on credentials, so it may
     * hand the cached anonymous copy to a signed-in request. That leaks
     * nothing, but it silently defeats the filter — the feature would look
     * implemented and not work.
     */
    public function testTheAnonymousResponseCarriesVary(): void
    {
        self::assertSame('Authorization, Cookie', self::headers(0)['Vary']);
    }

    // ── both channels, both postures ────────────────────────────────

    /**
     * ⚠ `Vary` must name BOTH credential channels. The route is reachable
     * with a bearer token or a WordPress session — `EdgeCache` treats
     * either as credentialed — so keying on one would leave the other
     * sharing a cache entry across viewers.
     */
    public function testVaryNamesBothCredentialChannels(): void
    {
        foreach ([0, 42] as $viewerId) {
            $vary = self::headers($viewerId)['Vary'];

            self::assertStringContainsString('Authorization', $vary, "viewer {$viewerId}");
            self::assertStringContainsString('Cookie', $vary, "viewer {$viewerId}");
        }
    }

    /** The signed-in TTL matches `tagFeed()`, which solved this already. */
    public function testTheSignedInPostureIsPrivateAndShortLived(): void
    {
        self::assertSame('private, max-age=15', self::headers(42)['Cache-Control']);
    }

    /**
     * ⚠ ANTI-VACUITY, AND A REGRESSION GUARD IN ONE: the anonymous posture
     * is UNCHANGED, byte for byte. The public, SEO-relevant caching is the
     * reason this route exists, and the change must not quietly cost it —
     * a fix that made everything `private` would pass every other case
     * here.
     */
    public function testTheAnonymousPostureIsUnchanged(): void
    {
        self::assertSame(self::ANON_POSTURE, self::headers(0)['Cache-Control']);
    }

    /** And the two postures genuinely differ, rather than one constant. */
    public function testTheTwoPosturesDiffer(): void
    {
        self::assertNotSame(
            self::headers(0)['Cache-Control'],
            self::headers(42)['Cache-Control']
        );
    }

    /**
     * ⚠ Only a real viewer id switches posture. A negative or absurd value
     * must not be read as "signed in" — `get_current_user_id()` answers 0
     * for anonymous, and anything else here would be a surprise worth
     * failing on.
     */
    public function testOnlyAPositiveViewerIdIsTreatedAsSignedIn(): void
    {
        self::assertSame(self::ANON_POSTURE, self::headers(0)['Cache-Control']);
        self::assertSame(self::ANON_POSTURE, self::headers(-1)['Cache-Control']);
        self::assertStringContainsString('private', self::headers(1)['Cache-Control']);
    }

    // ── helper ──────────────────────────────────────────────────────

    /** @return array<string, string> */
    private static function headers(int $viewerId): array
    {
        $m = (new ReflectionClass(FeedEndpoint::class))->getMethod('hotCacheHeaders');
        $m->setAccessible(true);

        /** @var array<string, string> $out */
        $out = $m->invoke(null, $viewerId);

        self::assertArrayHasKey('Cache-Control', $out);
        self::assertArrayHasKey('Vary', $out);

        return $out;
    }
}
