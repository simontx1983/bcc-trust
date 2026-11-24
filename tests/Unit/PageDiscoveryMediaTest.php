<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\PageDiscoveryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A page's avatar and cover actually reach the discovery card.
 *
 * ## The defect this exists to have caught
 *
 * Both card builders read these through `PeepSoPagePhoto`, guarded by
 * `class_exists('PeepSoPagePhoto')` — and no such class exists anywhere.
 * `peepso-pages` is installed and defines `PeepSoPage`; that name appeared
 * in exactly two places in the whole plugin tree, both of them those
 * calls, and `get_page_avatar_url()` / `get_page_cover_url()` existed on
 * nothing at all.
 *
 * So the guard was permanently false, `$avatar` and `$cover` stayed `''`,
 * and page discovery served no cover and no avatar — silently, for as long
 * as the code has been there.
 *
 * ⚠ Nothing could see it. The unit suite had no assertion on either field;
 * PHPStan cannot check methods on an unknown class, and there was no stub
 * for it; and `class_exists()` turns the mistake into dead code rather than
 * an error. ⇒ These tests assert the EMITTED FIELDS, which is the level at
 * which the old code was wrong: every case below produced `''` before.
 *
 * ## Why a behavioural test rather than a PHPStan stub
 *
 * Stubbing `PeepSoPagePhoto` so PHPStan could check its methods would
 * assert the wrong thing — that the class is expected to exist. It is not;
 * it never existed. What needed proving is that a page WITH a cover gets
 * its cover, which only driving the builder can show.
 */
#[Group('unit')]
#[CoversClass(PageDiscoveryService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PageDiscoveryMediaTest extends TestCase
{
    private const PAGE_ID = 4100;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        require_once dirname(__DIR__) . '/Stubs/page-discovery-chain-applied-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/page-media-stubs.php';

        $GLOBALS['__bcc_pm']    = [];
        $GLOBALS['__bcc_thumb'] = [];
        \PeepSoPage::reset();
    }

    public function testAPageWithACoverGetsItsCover(): void
    {
        $GLOBALS['__bcc_pm'][self::PAGE_ID]['page_cover_photo'] = 'https://img.example/cover.jpg';

        self::assertSame('https://img.example/cover.jpg', $this->card()['cover_url']);
    }

    /**
     * ⚠ And a page with NO cover reports none — not PeepSo's default.
     *
     * `PeepSoPage::get_cover_url()` starts from `get_cover_default()` and
     * overwrites it only when the meta is set, so it can never say "no
     * cover". Using it would hand every page a placeholder URL and leave
     * no way to tell a real cover from a default, which is why the meta is
     * read directly.
     */
    public function testAPageWithoutACoverReportsNoCover(): void
    {
        self::assertSame('', $this->card()['cover_url']);
    }

    public function testAPageWithAnUploadedAvatarGetsIt(): void
    {
        $GLOBALS['__bcc_pm'][self::PAGE_ID]['peepso_page_avatar_hash'] = 'abc123';
        \PeepSoPage::$avatarUrls[self::PAGE_ID] = 'https://img.example/avatar.jpg';

        self::assertSame('https://img.example/avatar.jpg', $this->card()['avatar_url']);
    }

    /**
     * The fallback that existed before and must survive: a page with no
     * uploaded avatar shows its WP featured image.
     *
     * ⚠ This is what calling the accessor unconditionally would have
     * broken. It never returns empty, so the fallback below would have
     * become unreachable and these pages would have switched to PeepSo's
     * generic default — a visible change, inside a bug fix.
     */
    public function testAPageWithoutAnUploadedAvatarKeepsTheFeaturedImage(): void
    {
        $GLOBALS['__bcc_thumb'][self::PAGE_ID] = 'https://img.example/featured.jpg';

        self::assertSame('https://img.example/featured.jpg', $this->card()['avatar_url']);
    }

    /**
     * ⭐ And such a page never builds a PeepSoPage at all.
     *
     * The real accessor costs a `file_exists()` per call and calls
     * `set_avatar_custom_flag()`, whose short-circuit compares
     * `0 == $current_val` against an absent meta (`''`) — false under
     * PHP 8, so it does NOT fire for exactly these pages. That is a write
     * attempt on a read path, once per page, in a bounded discovery loop.
     *
     * Gating on the upload hash is what avoids it, and this assertion is
     * what stops someone removing the gate "to simplify".
     */
    public function testAnAvatarLessPageNeverTouchesThePeepSoAccessor(): void
    {
        $GLOBALS['__bcc_thumb'][self::PAGE_ID] = 'https://img.example/featured.jpg';

        $this->card();

        self::assertSame(
            0,
            \PeepSoPage::$constructed,
            'the accessor was consulted for a page with no uploaded avatar:'
            . ' that is a file_exists() and an attempted meta write per page'
        );
    }

    /** With a hash present it IS consulted — the control for the test above. */
    public function testAPageWithAHashDoesTouchTheAccessor(): void
    {
        $GLOBALS['__bcc_pm'][self::PAGE_ID]['peepso_page_avatar_hash'] = 'abc123';

        $this->card();

        self::assertSame(1, \PeepSoPage::$constructed);
    }

    /**
     * One discovery card, built the way the service builds it.
     *
     * ⚠ `buildCardFromReadModel()`, not `buildCard()`. Both carried the
     * same broken block and both now call the same two resolvers, but
     * `buildCard()` reaches into the plugin container (owner resolution via
     * `PeepSoPageResolver` → `Plugin` → a bcc-core interface), so driving
     * it would stand up the container and fail for reasons unrelated to
     * media. The read-model builder is self-contained: the two resolvers,
     * the sanitizers, `get_permalink()` and the row.
     *
     * ⇒ So this asserts the EMITTED FIELDS — the level at which the old
     * code was wrong — without the container.
     *
     * The service is created without its constructor, the house style in
     * `PageDiscoveryChainAppliedTest`; no injected property is touched on
     * this path.
     *
     * @return array<string, mixed>
     */
    private function card(): array
    {
        $service = (new ReflectionClass(PageDiscoveryService::class))->newInstanceWithoutConstructor();
        $build   = new \ReflectionMethod(PageDiscoveryService::class, 'buildCardFromReadModel');

        /** @var array<string, mixed> $card */
        $card = $build->invoke($service, self::row(), []);

        return $card;
    }

    private static function row(): object
    {
        return (object) [
            'ID'                => self::PAGE_ID,
            'owner_id'          => 77,
            'post_title'        => 'Acme Validator',
            'page_type'         => 'validator',
            'trust_score'       => 42,
            'reputation_tier'   => 'trusted',
            'endorsement_count' => 3,
            'is_verified'       => 0,
            'follower_count'    => 0,
        ];
    }
}
