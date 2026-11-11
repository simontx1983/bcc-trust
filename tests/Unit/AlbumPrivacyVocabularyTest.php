<?php
/**
 * The album privacy slug and label the frontend renders verbatim.
 *
 * ⚠ These are USER-VISIBLE STRINGS. Per §A2 the frontend renders the
 * server's slug and label as given, with no client-side enum→label
 * mapping, so a wrong value here is a wrong chip on someone's album —
 * not an internal detail.
 *
 * That is why level 30 is pinned: it reported `friends_only` / "Friends"
 * while `PeepSoAlbumAccess::canView()` resolved it to owner-only (BCC has
 * no friends feature, and peepso-friends was removed from production), so
 * an owner saw a "FRIENDS" chip on an album only they could open. Nothing
 * covered that mapping, which is why it survived.
 *
 * The methods are private because they are presentation details of one
 * endpoint; reflection is the proportionate way to pin them rather than
 * widening the API to make them testable.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\UserAlbumsEndpoint;
use BCC\Trust\Core\Support\PeepSoAlbumAccess;
use PHPUnit\Framework\TestCase;

final class AlbumPrivacyVocabularyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Shadows `apply_filters` inside BCC\Trust\Core\REST only — see
        // the stub's header for why it is not declared globally.
        require_once __DIR__ . '/../Stubs/album-privacy-vocabulary-stubs.php';
    }

    private function call(string $method, int $access): string
    {
        $endpoint = new UserAlbumsEndpoint();
        $ref      = new \ReflectionMethod(UserAlbumsEndpoint::class, $method);

        /** @var string $out */
        $out = $ref->invoke($endpoint, $access);

        return $out;
    }

    /** @return list<array{int, string, string}> */
    public static function levels(): array
    {
        return [
            [PeepSoAlbumAccess::ACCESS_PUBLIC,  'public',       'Public'],
            [PeepSoAlbumAccess::ACCESS_MEMBERS, 'site_members', 'Members'],
            // ⚠ 30 deliberately reports as owner-only, matching 40.
            [PeepSoAlbumAccess::ACCESS_FRIENDS, 'only_me',      'Private'],
            [PeepSoAlbumAccess::ACCESS_PRIVATE, 'only_me',      'Private'],
        ];
    }

    public function testEveryLevelReportsItsIntendedSlugAndLabel(): void
    {
        foreach (self::levels() as [$access, $slug, $label]) {
            self::assertSame($slug, $this->call('privacySlug', $access), "slug for level {$access}");
            self::assertSame($label, $this->call('privacyLabel', $access), "label for level {$access}");
        }
    }

    /**
     * The specific regression: an owner must never be told an owner-only
     * album is shared with "friends" that cannot exist.
     */
    public function testLevel30NeverReportsFriends(): void
    {
        self::assertSame('only_me', $this->call('privacySlug', PeepSoAlbumAccess::ACCESS_FRIENDS));
        self::assertNotSame('friends_only', $this->call('privacySlug', PeepSoAlbumAccess::ACCESS_FRIENDS));
        self::assertNotSame('Friends', $this->call('privacyLabel', PeepSoAlbumAccess::ACCESS_FRIENDS));
    }

    /**
     * Control: the mapping must still DISCRIMINATE. If both methods were
     * stubbed to a constant — or someone "simplified" the match into a
     * single default — every assertion above about 30 and 40 would still
     * pass while public and members silently broke.
     */
    public function testTheMappingStillDistinguishesTheOtherLevels(): void
    {
        self::assertSame('public', $this->call('privacySlug', PeepSoAlbumAccess::ACCESS_PUBLIC));
        self::assertSame('site_members', $this->call('privacySlug', PeepSoAlbumAccess::ACCESS_MEMBERS));
        self::assertNotSame(
            $this->call('privacySlug', PeepSoAlbumAccess::ACCESS_PUBLIC),
            $this->call('privacySlug', PeepSoAlbumAccess::ACCESS_PRIVATE),
            'public and only_me must not collapse to the same slug'
        );
    }

    /** An unknown enum must fall back to the most private value. */
    public function testAnUnknownLevelFallsBackToOwnerOnly(): void
    {
        self::assertSame('only_me', $this->call('privacySlug', 99));
        self::assertSame('Private', $this->call('privacyLabel', 99));
    }
}
