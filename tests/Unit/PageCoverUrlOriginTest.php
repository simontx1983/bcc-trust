<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\PageCoverUrl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A page cover is emitted on an origin WordPress actually serves.
 *
 * ## The problem this rule exists for
 *
 * `page_cover_photo` holds a FULL URL, built from `content_url()` AT UPLOAD
 * TIME. A row written before the headless cutover carries the old host, and
 * that host may not serve `/wp-content` at all — the public front door is a
 * Next.js app and answers 403 for it.
 *
 * Refusing such a row would be the wrong fix: the FILE is where the
 * content directory expects it, so the same path on the WordPress origin
 * serves fine. Only the stored HOST is stale. So the rule re-emits the
 * path on the current content origin rather than discarding the value.
 *
 * ⇒ The rule is HOST-BLIND BY DESIGN, and that is what makes it safe: the
 * emitted origin is always ours, so a foreign host in the stored value can
 * never be the origin a viewer's browser is sent to.
 */
#[Group('unit')]
#[CoversClass(PageCoverUrl::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PageCoverUrlOriginTest extends TestCase
{
    /** What the fixture's `content_url()` answers. */
    private const CONTENT = 'https://cms.example.test/wp-content';

    private const PATH = '/wp-content/peepso/pages/4100/9f2a1c-cover.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/page-cover-origin-stubs.php';

        $GLOBALS['__bcc_pco'] = ['content_url' => self::CONTENT, 'meta' => []];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_pco']);
        parent::tearDown();
    }

    /** ⭐ The cms host — already right — comes back unchanged. */
    public function testTheContentOriginIsLeftAlone(): void
    {
        self::assertSame(
            self::CONTENT . '/peepso/pages/4100/9f2a1c-cover.jpg',
            PageCoverUrl::onContentOrigin('https://cms.example.test' . self::PATH)
        );
    }

    /**
     * ⭐ A staging host, rebased onto the content origin. This is the
     * shape a row written on another environment carries, and before this
     * rule it was emitted pointing at that host.
     */
    public function testTheStagingHostIsRebased(): void
    {
        self::assertSame(
            self::CONTENT . '/peepso/pages/4100/9f2a1c-cover.jpg',
            PageCoverUrl::onContentOrigin('https://stage.example.test' . self::PATH)
        );
    }

    /**
     * ⭐ And the FRONT DOOR, which serves no `/wp-content` at all — the
     * case that would render as a broken image.
     */
    public function testTheFrontDoorHostIsRebased(): void
    {
        self::assertSame(
            self::CONTENT . '/peepso/pages/4100/9f2a1c-cover.jpg',
            PageCoverUrl::onContentOrigin('https://example.test' . self::PATH)
        );
    }

    /**
     * ⚠ A foreign host whose path is NOT content-shaped is refused.
     */
    public function testAForeignHostOutsideTheContentPathIsRefused(): void
    {
        self::assertNull(PageCoverUrl::onContentOrigin('https://elsewhere.example/images/cover.jpg'));
    }

    /**
     * ⚠⚠ A foreign host WITH a content-shaped path is rebased onto OUR
     * origin, not refused — and that is the safe outcome, not a gap.
     *
     * The emitted origin is always ours, so the stored host cannot become
     * the origin a browser is sent to. Worst case is a 404 on our own
     * domain. Refusing instead would mean a host allow-list, which is what
     * would have hidden a cover whose file the content directory serves.
     */
    public function testAForeignHostWithAContentPathIsRebasedNotRefused(): void
    {
        $out = PageCoverUrl::onContentOrigin('https://elsewhere.example' . self::PATH);

        self::assertSame(self::CONTENT . '/peepso/pages/4100/9f2a1c-cover.jpg', $out);
        self::assertStringStartsWith(self::CONTENT, (string) $out, 'the emitted origin must always be ours');
        self::assertStringNotContainsString('elsewhere.example', (string) $out);
    }

    /** A relative value is refused — PeepSo always stores an absolute URL. */
    public function testARelativeValueIsRefused(): void
    {
        self::assertNull(PageCoverUrl::onContentOrigin(self::PATH));
        self::assertNull(PageCoverUrl::onContentOrigin('cover.jpg'));
    }

    public function testAnEmptyValueIsRefused(): void
    {
        self::assertNull(PageCoverUrl::onContentOrigin(''));
    }

    /** A URL with no path at all is refused. */
    public function testAHostWithNoPathIsRefused(): void
    {
        self::assertNull(PageCoverUrl::onContentOrigin('https://cms.example.test'));
    }

    /**
     * ⚠ The query string and fragment are DROPPED, not carried. Nothing
     * writes them here, and carrying them would let a stored value append
     * arbitrary parameters to a URL on our own origin.
     */
    public function testAQueryStringAndFragmentAreDropped(): void
    {
        self::assertSame(
            self::CONTENT . '/peepso/pages/4100/9f2a1c-cover.jpg',
            PageCoverUrl::onContentOrigin('https://cms.example.test' . self::PATH . '?v=2#frag')
        );
    }

    /**
     * ⚠ A path that merely LOOKS like a prefix is not under the content
     * directory: `/wp-contentious/...` must not match `/wp-content`.
     */
    public function testAPrefixLookalikePathIsRefused(): void
    {
        self::assertNull(PageCoverUrl::onContentOrigin('https://cms.example.test/wp-contentious/x.jpg'));
    }

    /**
     * ⚠ Content served from the domain ROOT refuses everything, by design:
     * with no content path to match, every path would qualify. Fail-closed
     * beats guessing, and WordPress puts content under a directory in every
     * normal install.
     */
    public function testContentAtTheDomainRootRefuses(): void
    {
        $GLOBALS['__bcc_pco']['content_url'] = 'https://cms.example.test';

        self::assertNull(PageCoverUrl::onContentOrigin('https://cms.example.test' . self::PATH));
    }

    // ── through the meta, as the callers use it ─────────────────────────

    public function testForPageReadsTheMetaAndRebases(): void
    {
        $GLOBALS['__bcc_pco']['meta'][4100]['page_cover_photo'] = 'https://stage.example.test' . self::PATH;

        self::assertSame(
            self::CONTENT . '/peepso/pages/4100/9f2a1c-cover.jpg',
            PageCoverUrl::forPage(4100)
        );
    }

    /** ⭐ ANTI-VACUITY CONTROL: no meta, no cover — and no error. */
    public function testForPageWithoutMetaIsNull(): void
    {
        self::assertNull(PageCoverUrl::forPage(4100));
    }

    public function testForPageRefusesANonStringOrEmptyMeta(): void
    {
        $GLOBALS['__bcc_pco']['meta'][4100]['page_cover_photo'] = '';
        self::assertNull(PageCoverUrl::forPage(4100));

        $GLOBALS['__bcc_pco']['meta'][4100]['page_cover_photo'] = ['not', 'a', 'string'];
        self::assertNull(PageCoverUrl::forPage(4100));
    }

    public function testForPageRefusesANonPositiveId(): void
    {
        self::assertNull(PageCoverUrl::forPage(0));
        self::assertNull(PageCoverUrl::forPage(-1));
    }
}
