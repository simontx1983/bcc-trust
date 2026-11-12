<?php
/**
 * §F-27 — `GET /halls` is per-viewer, so it must not be publicly
 * cacheable for a signed-in reader.
 *
 * The payload carries `viewer_membership` (`is_member`, `is_primary`,
 * `joined_at`) on every row. Sent as `public, max-age=300`, any shared
 * cache may store one signed-in member's state and serve it to a
 * different viewer, or to anonymous visitors, for up to five minutes.
 *
 * ⚠ Nothing looks broken while that happens. The response is
 * well-formed, plausible, and someone else's — which is why a header
 * assertion is the only thing that catches it. No payload assertion
 * would.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\HallsEndpoint;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HallsListCacheHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/halls-cache-stubs.php';
        $GLOBALS['__bcc_halls_cache'] = ['viewer_id' => 0, 'calls' => []];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_halls_cache']);
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function headersFor(int $viewerId): array
    {
        $GLOBALS['__bcc_halls_cache']['viewer_id'] = $viewerId;

        return (new HallsEndpoint())
            ->handle(new \WP_REST_Request(['page' => 1, 'page_size' => 10]))
            ->get_headers();
    }

    // ── the bug ─────────────────────────────────────────────────────

    public function testASignedInReadIsPrivateAndNotStored(): void
    {
        $headers = $this->headersFor(77);

        self::assertSame('private, no-store', $headers['Cache-Control'] ?? null);
        self::assertStringNotContainsString(
            'public',
            $headers['Cache-Control'] ?? '',
            'a per-viewer payload must never be publicly cacheable'
        );
    }

    /**
     * Control: anonymous has no viewer bits to leak, so it stays
     * cacheable. Without this, "fix the leak" could just mean "never
     * cache anything", which would quietly cost the public Halls list its
     * caching — a regression nobody would see in a test suite.
     */
    public function testAnAnonymousReadStaysPubliclyCacheable(): void
    {
        $headers = $this->headersFor(0);

        self::assertSame('public, max-age=300', $headers['Cache-Control'] ?? null);
    }

    /**
     * ⚠ `Vary` is sent for BOTH, and that is not belt-and-braces.
     * `private` stops a shared cache storing the signed-in variant, but
     * says nothing about an ANONYMOUS response already in the cache being
     * replayed to a signed-in viewer on the same URL. Vary is what keys
     * those apart, so its absence on the anonymous response is the half
     * of the bug `private` alone does not close.
     */
    public function testVaryIsSentForBothAnonymousAndSignedInReads(): void
    {
        self::assertSame('Authorization, Cookie', $this->headersFor(0)['Vary'] ?? null);
        self::assertSame('Authorization, Cookie', $this->headersFor(77)['Vary'] ?? null);
    }

    /**
     * The premise, asserted rather than assumed: the route really does
     * hand the viewer id to the service, so the payload really is
     * per-viewer. If it stopped doing that, the cache headers above would
     * be cargo-culting a risk that no longer existed — and the right fix
     * would be different.
     */
    public function testTheRouteAsksTheServiceAboutTheViewer(): void
    {
        $this->headersFor(77);

        self::assertSame([77], $GLOBALS['__bcc_halls_cache']['calls']);
    }
}
