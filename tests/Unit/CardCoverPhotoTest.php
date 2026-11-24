<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * §3.2 `cover_photo_url` on the Card view-model (Structure A row 17).
 *
 * ## What each kind's cover comes from, and why not the obvious accessor
 *
 * - **member** — `PeepSoMediaCache::coverPhotoUrl()`, the same cached seam
 *   the profile reads, so a member's card and their profile cannot show
 *   different covers;
 * - **page** — the `page_cover_photo` meta, NOT
 *   `PeepSoPage::get_cover_url()`. That accessor starts from
 *   `get_cover_default()` and overwrites it only when this same meta is
 *   non-empty, so it can never report "no cover": every page would receive
 *   PeepSo's default image and nothing downstream could tell a real cover
 *   from a placeholder;
 * - **community** — `null` for now. The field is shape-stable on every kind
 *   from this change on, and a group's cover arrives producer-supplied in
 *   the follow-up, because `getCommunityCardFromGroupData()`'s contract is
 *   pure composition with zero extra queries.
 *
 * ## Levels, stated plainly
 *
 * The community case is driven FOR REAL: that builder is public and
 * self-contained, so the test composes an actual card and reads the emitted
 * field. The member and page resolvers go through reflection — the house
 * pattern for this class, for the reason
 * `MemberOpenDisputePermissionTest` documents (an eleven-dependency
 * constructor). So for those two the RULE is covered here and the wiring by
 * review of the diff.
 */
#[Group('unit')]
#[CoversClass(CardViewService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CardCoverPhotoTest extends TestCase
{
    private const PAGE_ID   = 7100;
    private const MEMBER_ID = 7200;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/card-cover-photo-stubs.php';

        $GLOBALS['__bcc_ccp'] = ['meta' => [], 'covers' => []];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_ccp']);
        parent::tearDown();
    }

    // ── page ────────────────────────────────────────────────────────────

    public function testAPageWithACoverEmitsIt(): void
    {
        $GLOBALS['__bcc_ccp']['meta'][self::PAGE_ID]['page_cover_photo']
            = 'https://example.test/wp-content/uploads/cover.jpg';

        self::assertSame(
            'https://example.test/wp-content/uploads/cover.jpg',
            $this->pageCover()
        );
    }

    /**
     * ⭐ ANTI-VACUITY CONTROL, and the case the accessor could not express:
     * a page with no cover reports `null`, not PeepSo's default image.
     */
    public function testAPageWithoutACoverEmitsNull(): void
    {
        self::assertNull($this->pageCover());
    }

    /** An empty or non-string meta value reads as "no cover", never an error. */
    public function testAnEmptyOrMalformedPageMetaEmitsNull(): void
    {
        $GLOBALS['__bcc_ccp']['meta'][self::PAGE_ID]['page_cover_photo'] = '';
        self::assertNull($this->pageCover());

        $GLOBALS['__bcc_ccp']['meta'][self::PAGE_ID]['page_cover_photo'] = ['not', 'a', 'string'];
        self::assertNull($this->pageCover());
    }

    // ── member ──────────────────────────────────────────────────────────

    public function testAMemberWithACoverEmitsIt(): void
    {
        $GLOBALS['__bcc_ccp']['covers'][self::MEMBER_ID]
            = 'https://example.test/wp-content/peepso/users/7200/cover.jpg';

        self::assertSame(
            'https://example.test/wp-content/peepso/users/7200/cover.jpg',
            $this->memberCover()
        );
    }

    /** ⭐ ANTI-VACUITY CONTROL for the member side. */
    public function testAMemberWithoutACoverEmitsNull(): void
    {
        self::assertNull($this->memberCover());
    }

    /**
     * ⚠ And the card reads the CACHED seam, not PeepSo directly.
     *
     * Resolving a cover uncached constructs a `PeepSoUser`, which is a
     * per-user `peepso_users` SELECT — once per card in a list. This
     * assertion is what stops someone "simplifying" the call away.
     */
    public function testTheMemberCoverGoesThroughTheCachedSeam(): void
    {
        $this->memberCover();

        self::assertSame(
            [self::MEMBER_ID],
            \BCC\Core\PeepSo\PeepSoMediaCache::$coverCalls,
            'the member cover did not go through PeepSoMediaCache'
        );
    }

    // ── community ───────────────────────────────────────────────────────

    /**
     * The field is PRESENT on a community card and `null`, which is the
     * designed empty state. Driven for real, so a kind cannot quietly lose
     * the key and leave clients with an inconsistent envelope.
     */
    public function testACommunityCardCarriesTheFieldAsNull(): void
    {
        $service = (new ReflectionClass(CardViewService::class))->newInstanceWithoutConstructor();
        $card    = $service->getCommunityCardFromGroupData(self::groupData(), 0);

        self::assertArrayHasKey('cover_photo_url', $card);
        self::assertNull($card['cover_photo_url']);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function pageCover(): ?string
    {
        $post = new \WP_Post();
        $post->ID = self::PAGE_ID;

        $resolve = new ReflectionMethod(CardViewService::class, 'resolvePageCoverUrl');

        /** @var string|null $out */
        $out = $resolve->invoke(null, $post);

        return $out;
    }

    private function memberCover(): ?string
    {
        $resolve = new ReflectionMethod(CardViewService::class, 'resolveMemberCoverUrl');

        /** @var string|null $out */
        $out = $resolve->invoke(null, self::MEMBER_ID);

        return $out;
    }

    /** @return array<string, mixed> */
    private static function groupData(): array
    {
        return [
            'group_id'          => 12,
            'name'              => 'A Group',
            'slug'              => 'a-group',
            'type'              => 'user',
            'privacy'           => 'public',
            'chain_tag'         => null,
            'description'       => null,
            'image_url'         => null,
            'verification'      => null,
            'collection_stats'  => null,
            'trust_min'         => 0,
            'member_count'      => 3,
            'posts_last_7d'     => 1,
            'active_members_7d' => 2,
            'viewer_is_member'  => false,
            'group_url'         => '/communities/a-group',
        ];
    }
}
