<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\UserAlbumsEndpoint;
use BCC\Trust\Core\REST\UserGroupsEndpoint;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The profile list routes are filtered for the viewer, so they must carry
 * `Vary: Authorization, Cookie` next to their `private, max-age=30`.
 *
 * `private` keeps shared caches out, but the browser's own cache still
 * stores the response, keyed on the URL alone. Without `Vary`, a member who
 * signs in after another on the same browser can be served the previous
 * member's followers / following / groups / albums / album photos for up
 * to 30 s. The other viewer-scoped routes (CardsEndpoint, the
 * UsersEndpoint profile) already send this pair.
 *
 * Two kinds of check:
 *   - UserGroupsEndpoint and UserAlbumsEndpoint build their success
 *     response in one private `respond()`, which is called here, so their
 *     headers are read from a real response.
 *   - UserFollowsEndpoint and UserAlbumPhotosEndpoint build theirs inline
 *     at the end of a handler that needs the profile gate, the slug
 *     resolver and their services. For those (and all four, as a backstop)
 *     the SOURCE is pinned: every `private, max-age=30` header call is
 *     followed by the `Vary` call on the same response. That proves the
 *     code says it, not that it runs; the two real responses above are the
 *     behavioural half.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ViewerScopedListCacheVaryTest extends TestCase
{
    private const FILES = [
        'UserFollowsEndpoint.php',
        'UserGroupsEndpoint.php',
        'UserAlbumsEndpoint.php',
        'UserAlbumPhotosEndpoint.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/push-delete-and-list-cache-stubs.php';
    }

    public function testTheGroupsListVariesOnTheCredential(): void
    {
        $h = $this->respondHeaders(UserGroupsEndpoint::class);

        self::assertSame('private, max-age=30', $h['Cache-Control'] ?? null);
        self::assertSame('Authorization, Cookie', $h['Vary'] ?? null);
    }

    public function testTheAlbumsListVariesOnTheCredential(): void
    {
        $h = $this->respondHeaders(UserAlbumsEndpoint::class);

        self::assertSame('private, max-age=30', $h['Cache-Control'] ?? null);
        self::assertSame('Authorization, Cookie', $h['Vary'] ?? null);
    }

    public function testEveryShortPrivateCacheInTheListRoutesIsPairedWithVary(): void
    {
        foreach (self::FILES as $file) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/REST/' . $file);

            [$found, $unpaired] = self::cacheHeaderPairing($src);

            self::assertGreaterThan(0, $found, "{$file}: the success response's cache header was not found");
            self::assertSame([], $unpaired, "{$file}: `private, max-age=30` without the Vary header that must follow it");
        }
    }

    /** Anti-vacuity for the pin: a missing, a wrong and a misplaced Vary are each reported. */
    public function testThePinReportsACacheHeaderWithoutItsVary(): void
    {
        $cache = "\$r->header('Cache-Control', 'private, max-age=30');";
        $vary  = "\$r->header('Vary', 'Authorization, Cookie');";

        self::assertSame([1, []], self::cacheHeaderPairing("<?php {$cache}\n// why\n{$vary}"), 'control: comments between are fine');
        self::assertSame([1, ['$r']], self::cacheHeaderPairing("<?php {$cache} return \$r;"));
        self::assertSame([1, ['$r']], self::cacheHeaderPairing("<?php {$cache} \$r->header('Vary', 'Authorization');"));
        self::assertSame([1, ['$r']], self::cacheHeaderPairing("<?php {$cache} \$x = 1; {$vary}"));
    }

    /**
     * Every `$var->header('Cache-Control', 'private, max-age=30');` in $src,
     * and the ones whose next statement (comments ignored) is not
     * `$var->header('Vary', 'Authorization, Cookie');`.
     *
     * @return array{int, list<string>} [how many found, unpaired variables]
     */
    private static function cacheHeaderPairing(string $src): array
    {
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }
        $code = (string) preg_replace('/\s+/', ' ', $code);

        $found = (int) preg_match_all(
            "/\\$(\\w+)->header\\('Cache-Control', 'private, max-age=30'\\);/",
            $code,
            $m,
            PREG_OFFSET_CAPTURE
        );

        $unpaired = [];
        foreach ($m[0] as $i => [$call, $offset]) {
            $var  = $m[1][$i][0];
            $next = ltrim(substr($code, $offset + strlen($call)));
            if (!str_starts_with($next, "\${$var}->header('Vary', 'Authorization, Cookie');")) {
                $unpaired[] = '$' . $var;
            }
        }

        return [$found, $unpaired];
    }

    /**
     * @param class-string $class
     * @return array<string, string>
     */
    private function respondHeaders(string $class): array
    {
        $m = new \ReflectionMethod($class, 'respond');

        /** @var \WP_REST_Response $res */
        $res = $m->invoke(new $class(), []);

        return $res->get_headers();
    }
}
