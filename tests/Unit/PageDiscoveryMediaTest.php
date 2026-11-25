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
    private const HASH    = 'abc123';

    private string $peepsoDir = '';

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

        // A real directory, because the resolver asks the filesystem
        // whether the avatar file is there. The page subdirectory is NOT
        // created here: a test that wants the file creates it.
        $this->peepsoDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'bcc-page-media-' . bin2hex(random_bytes(6))
            . DIRECTORY_SEPARATOR;
        mkdir($this->peepsoDir, 0777, true);

        \PeepSo::$dir = $this->peepsoDir;
        \PeepSo::$uri = 'https://peepso.example/peepso/';
    }

    protected function tearDown(): void
    {
        if ($this->peepsoDir !== '' && is_dir($this->peepsoDir)) {
            // ⚠ CHILD_FIRST already yields children before their parent;
            // reversing it put parents first and every rmdir warned
            // "Directory not empty" — and a PHP warning fails this suite.
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->peepsoDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($walk as $entry) {
                /** @var \SplFileInfo $entry */
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->peepsoDir);
        }
        parent::tearDown();
    }

    public function testAPageWithACoverGetsItsCover(): void
    {
        $GLOBALS['__bcc_pm'][self::PAGE_ID]['page_cover_photo']
            = 'https://stage.example.test/wp-content/peepso/pages/4100/cover.jpg';

        // ⚠ REBASED onto the content origin, not the stored host. The
        // stored value is built from content_url() at upload time, so a
        // pre-cutover row carries a host that may serve no /wp-content.
        self::assertSame(
            'https://cms.example.test/wp-content/peepso/pages/4100/cover.jpg',
            $this->card()['cover_url'],
            'the discovery cover must be rebased onto the content origin too'
        );
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
        $this->givenAvatarHash();
        $this->givenAvatarFileExists();

        self::assertSame(
            'https://peepso.example/peepso/pages/' . self::PAGE_ID . '/' . self::HASH . '-avatar-full.jpg',
            $this->card()['avatar_url']
        );
    }

    /**
     * ⭐ THE CASE REVIEW ASKED FOR: the hash is there and the FILE IS NOT.
     *
     * A database copied without the uploads directory, which happens on
     * staging. `PeepSoPage::get_avatar_url()` answers PeepSo's generic
     * default for this, so a page that should show its WP featured image
     * would show the default instead — and it would also call
     * `set_avatar_custom_flag(FALSE)` on the way, writing meta on a read.
     *
     * Building the path and checking the file is what makes the fallback
     * correct here.
     */
    public function testAHashWhoseFileIsMissingFallsBackToTheFeaturedImage(): void
    {
        $this->givenAvatarHash();
        $GLOBALS['__bcc_thumb'][self::PAGE_ID] = 'https://img.example/featured.jpg';
        // No file created.

        self::assertSame('https://img.example/featured.jpg', $this->card()['avatar_url']);
    }

    /** And with neither file nor featured image, it reports no avatar. */
    public function testAHashWithNoFileAndNoFeaturedImageReportsNoAvatar(): void
    {
        $this->givenAvatarHash();

        self::assertSame('', $this->card()['avatar_url']);
    }

    /**
     * The fallback that existed before and must survive: a page with no
     * uploaded avatar shows its WP featured image.
     */
    public function testAPageWithoutAnUploadedAvatarKeepsTheFeaturedImage(): void
    {
        $GLOBALS['__bcc_thumb'][self::PAGE_ID] = 'https://img.example/featured.jpg';

        self::assertSame('https://img.example/featured.jpg', $this->card()['avatar_url']);
    }

    /**
     * ⭐⭐ NO PAGE OBJECT IS EVER CONSTRUCTED, on any of the three paths.
     *
     * This is the assertion that keeps the read path read-only, and it is
     * stronger than "the accessor is not called for avatar-less pages":
     * `PeepSoPage::get_avatar_url()` writes TWICE — `get_image_dir()` ends
     * in `_make_user_dir()`, which `@mkdir()`s the directory just for being
     * asked, and `set_avatar_custom_flag(FALSE)` stores `FALSE` as `''`, so
     * its own guard `0 == $current_val` is `0 == ''` — false under PHP 8 —
     * and the meta is rewritten on EVERY read, forever.
     *
     * ⇒ Two writes per page, in a bounded discovery loop. Nothing here may
     * construct that object, so this test drives every path and asserts
     * zero constructions.
     */
    public function testNoPathEverConstructsAPeepSoPage(): void
    {
        // 1. no hash at all
        $this->card();
        // 2. hash, file missing
        $this->givenAvatarHash();
        $this->card();
        // 3. hash, file present
        $this->givenAvatarFileExists();
        $this->card();

        self::assertSame(
            0,
            \PeepSoPage::$constructed,
            'a PeepSoPage was constructed on a read path: get_avatar_url()'
            . ' mkdirs the page directory and rewrites page_avatar_custom'
            . ' on every read'
        );
    }

    private function givenAvatarHash(): void
    {
        $GLOBALS['__bcc_pm'][self::PAGE_ID]['peepso_page_avatar_hash'] = self::HASH;
    }

    /** The file exactly where `get_avatar_url()` would look for it. */
    private function givenAvatarFileExists(): void
    {
        $dir = $this->peepsoDir . 'pages' . DIRECTORY_SEPARATOR . self::PAGE_ID . DIRECTORY_SEPARATOR;
        mkdir($dir, 0777, true);
        file_put_contents($dir . self::HASH . '-avatar-full.jpg', 'jpeg');
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
