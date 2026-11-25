<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\NotificationViewService;
use BCC\Trust\Core\Support\CardUrlMap;
use BCC\Trust\Core\Support\PushPayload;
use BCC\Trust\Core\ValueObjects\GroupType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-594: every server-emitted Hall link points at the structure-A routes.
 *
 * The frontend moved Halls into Communities (bcc-frontend #412): one page
 * per group at /communities/{slug} (a Hall's slug is its group slug), and
 * the Halls list at /communities?tab=halls. The old /halls routes only
 * forward (308). So the server now links there directly:
 *
 *   - CardUrlMap::groupUrl() sends every kind, Halls included, to
 *     /communities/{slug}. HallsService's links.self, feed attribution,
 *     cards, push and the sitemap all compose through it.
 *   - CardUrlMap::HALLS_LIST_URL is the Halls list, the fallback when one
 *     Hall can't be linked (a bell row or push whose Hall is gone).
 *   - Nothing outside HallsEndpoint (whose `/halls` strings are REST
 *     route paths, not frontend links) spells a `/halls` path.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(CardUrlMap::class)]
#[CoversClass(PushPayload::class)]
#[CoversClass(NotificationViewService::class)]
final class HallLinksToCommunitiesTest extends TestCase
{
    private const PLUGIN = __DIR__ . '/../..';

    /** @return iterable<string, array{string}> */
    public static function everyGroupKind(): iterable
    {
        foreach (GroupType::cases() as $case) {
            yield $case->value => [$case->value];
        }
    }

    #[DataProvider('everyGroupKind')]
    public function testEveryGroupKindLinksToItsCommunityPage(string $type): void
    {
        self::assertSame('/communities/cosmos-hub-hall', CardUrlMap::groupUrl($type, 'cosmos-hub-hall'));
    }

    public function testTheHallsListIsTheCommunitiesHallsTab(): void
    {
        self::assertSame('/communities?tab=halls', CardUrlMap::HALLS_LIST_URL);
    }

    public function testAHallPostPushLinksToTheHallOrTheHallsList(): void
    {
        $first = ['actor_handle' => 'ana', 'group_id' => 7, 'hall_name' => 'Cosmos Hub Hall', 'hall_slug' => 'cosmos-hub-hall'];
        self::assertSame('/communities/cosmos-hub-hall', PushPayload::forHallPost(1, $first)['url']);

        $first['hall_slug'] = '';
        self::assertSame(CardUrlMap::HALLS_LIST_URL, PushPayload::forHallPost(1, $first)['url']);
    }

    public function testAHallPostBellRowLinksToTheHallOrTheHallsList(): void
    {
        require_once __DIR__ . '/../Stubs/hall-link-group-repo-stubs.php';
        $GLOBALS['__bcc_hall_rows'] = [7 => 'cosmos-hub-hall', 9 => ''];

        $resolve = new \ReflectionMethod(NotificationViewService::class, 'resolveHallLink');

        self::assertSame('/communities/cosmos-hub-hall', $resolve->invoke(null, 7));
        self::assertSame(CardUrlMap::HALLS_LIST_URL, $resolve->invoke(null, 8), 'not a Hall (or gone)');
        self::assertSame(CardUrlMap::HALLS_LIST_URL, $resolve->invoke(null, 9), 'a Hall with no slug');
        self::assertSame(CardUrlMap::HALLS_LIST_URL, $resolve->invoke(null, 0), 'no group id');
    }

    public function testNoServerCodeSpellsTheOldHallsRoute(): void
    {
        $found  = [];
        $exempt = [];
        $files  = self::phpFiles();

        self::assertGreaterThan(300, count($files), 'the scan found almost nothing');

        foreach ($files as $file) {
            $relative = substr($file, strlen(self::PLUGIN) + 1);
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                if (preg_match('#^/halls(?:$|[/?\#])#', trim($token[1], '\'"')) !== 1) {
                    continue;
                }
                // HallsEndpoint's are REST route paths, not frontend links.
                if ($relative === 'app/Domain/Core/REST/HallsEndpoint.php') {
                    $exempt[] = $relative . ':' . $token[2];
                    continue;
                }
                $found[] = $relative . ':' . $token[2];
            }
        }

        // ANTI-VACUITY: the matcher does see a `/halls` string where one is.
        self::assertNotSame([], $exempt, 'the matcher found not even the REST route paths');
        self::assertSame([], $found, 'link through CardUrlMap::groupUrl() / HALLS_LIST_URL');
    }

    /** @return list<string> every PHP file under app/ and includes/ */
    private static function phpFiles(): array
    {
        $out = [];
        foreach (['app', 'includes'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::PLUGIN . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $out[] = str_replace('\\', '/', $f->getPathname());
                }
            }
        }
        sort($out);
        return $out;
    }
}
