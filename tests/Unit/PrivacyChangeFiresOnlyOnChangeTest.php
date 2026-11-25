<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\UserMetaRepository;
use BCC\Trust\Core\Support\PrivacySettings;
use BCC\Trust\Infrastructure\EdgeCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `bcc_privacy_settings_changed` fires for the keys that MOVED, and the
 * members tag is purged only then.
 *
 * ## Why the distinction is the whole feature
 *
 * The action used to carry every key a save TOUCHED
 * (`array_intersect(array_keys($partial), ALL_KEYS)`), and a settings form
 * resends every value — so it fired on every save. Its comment said "only
 * the keys this call actually wrote", which reads as change-detection and
 * was not. One listener existed (`FeedRankingService`, on
 * `real_name_hidden`) and it dropped a cache entry for nothing each time.
 *
 * That is affordable for one cache entry. It is not affordable for the
 * edge cache's MEMBERS tag, which is what this change adds a listener for:
 * purging it on every preference save would throw away every anonymous
 * member listing whenever anybody toggled anything.
 *
 * ⚠ And the fail direction is asymmetric, which is why the unknown case
 * has its own test: over-firing costs a cache miss, under-firing serves a
 * stale listing to everyone for the rest of its TTL.
 */
#[Group('unit')]
#[CoversClass(PrivacySettings::class)]
#[CoversClass(EdgeCache::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PrivacyChangeFiresOnlyOnChangeTest extends TestCase
{
    private const USER = 9300;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/privacy-change-stubs.php';

        $GLOBALS['__bcc_pcs'] = ['meta' => [], 'fired' => [], 'purged' => []];

        UserMetaRepository::$memoize   = false;
        UserMetaRepository::$unreadable = [];
    }

    protected function tearDown(): void
    {
        UserMetaRepository::$unreadable = [];
        unset($GLOBALS['__bcc_pcs']);
        parent::tearDown();
    }

    /**
     * ⭐ A RESEND OF IDENTICAL VALUES FIRES NOTHING.
     *
     * This is the case the old payload got wrong, and the one a settings
     * form produces on every save.
     */
    public function testAResendOfIdenticalValuesFiresNothing(): void
    {
        $this->given(['bcc_privacy_real_name_hidden' => '1', 'bcc_privacy_watching_hidden' => '0']);

        PrivacySettings::writePartial(self::USER, [
            'real_name_hidden' => true,
            'watching_hidden'  => false,
        ]);

        self::assertSame([], $this->fired(), 'a resend of the same values fired the action');
        self::assertSame([], $this->purged(), 'a resend of the same values purged the members tag');
    }

    /** ⭐ A real change fires with EXACTLY the keys that moved. */
    public function testARealChangeFiresWithExactlyTheMovedKeys(): void
    {
        $this->given(['bcc_privacy_real_name_hidden' => '0', 'bcc_privacy_watching_hidden' => '0']);

        PrivacySettings::writePartial(self::USER, [
            'real_name_hidden' => true,   // moved
            'watching_hidden'  => false,  // unchanged
        ]);

        self::assertSame([['real_name_hidden']], $this->fired());
    }

    /**
     * ⭐ AN UNKNOWN BEFORE-READ FIRES EVERY TOUCHED KEY.
     *
     * Not a nicety: a read that cannot be trusted means we do not know
     * whether the listing is stale, and serving a stale one is the only
     * outcome here that is wrong rather than merely wasteful.
     */
    public function testAnUnknownBeforeReadFiresEveryTouchedKey(): void
    {
        $this->given(['bcc_privacy_real_name_hidden' => '1']);
        UserMetaRepository::$unreadable[self::USER] = true;

        PrivacySettings::writePartial(self::USER, [
            'real_name_hidden' => true,   // identical, and fires anyway
            'watching_hidden'  => true,
        ]);

        self::assertSame([['real_name_hidden', 'watching_hidden']], $this->fired());
    }

    // ── the purge decision ──────────────────────────────────────────────

    /** A listing-visible key purges the members tag. */
    public function testAListingVisibleKeyPurgesTheMembersTag(): void
    {
        EdgeCache::registerMemberListingHooks();

        do_action('bcc_privacy_settings_changed', self::USER, ['real_name_hidden']);

        self::assertSame([EdgeCache::TAG_MEMBERS], $this->purged());
    }

    /**
     * ⚠ ANTI-VACUITY CONTROL: a privacy key that no listing renders does
     * NOT purge. Without this, a listener that purged on anything at all
     * would pass every other assertion here.
     */
    public function testAPrivacyKeyNoListingRendersDoesNotPurge(): void
    {
        EdgeCache::registerMemberListingHooks();

        do_action('bcc_privacy_settings_changed', self::USER, ['email_hidden', 'delegations_hidden']);

        self::assertSame([], $this->purged());
    }

    /** The endpoint's own action reaches the same decision. */
    public function testTheDirectWriteActionAlsoPurges(): void
    {
        EdgeCache::registerMemberListingHooks();

        do_action('bcc_member_listing_fields_changed', self::USER, ['show_about_on_profile']);

        self::assertSame([EdgeCache::TAG_MEMBERS], $this->purged());
    }

    /** And one purge per fire, not one per matching key. */
    public function testSeveralMatchingKeysPurgeOnce(): void
    {
        EdgeCache::registerMemberListingHooks();

        do_action('bcc_privacy_settings_changed', self::USER, [
            'real_name_hidden',
            'watching_hidden',
            'reviews_hidden',
        ]);

        self::assertSame([EdgeCache::TAG_MEMBERS], $this->purged());
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @param array<string, string> $meta */
    private function given(array $meta): void
    {
        $GLOBALS['__bcc_pcs']['meta'][self::USER] = $meta;
    }

    /** @return list<list<string>> every fire's key list, in order */
    private function fired(): array
    {
        /** @var list<list<string>> $f */
        $f = $GLOBALS['__bcc_pcs']['fired'] ?? [];

        return $f;
    }

    /** @return list<string> every purged tag, in order */
    private function purged(): array
    {
        /** @var list<string> $p */
        $p = $GLOBALS['__bcc_pcs']['purged'] ?? [];

        return $p;
    }
}
