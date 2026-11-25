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
 * Structure A row 16 — the card bio is cut at 25 words, not ~200 characters.
 *
 * The bio moves to the FRONT of the card, where the space is a line count
 * rather than a character count, so the bound is now words.
 *
 * ## The part that was not in the design
 *
 * "One place, all five kinds" was not true of the code. `truncateBio()`
 * served the page and member kinds; community cards BYPASSED it entirely
 * and leaned on the producers' ~200-char cut
 * (`GroupsDiscoveryEndpoint::truncateDescription`,
 * `GroupsService::getGroup`). Changing only the truncator would have left
 * community cards on the old bound while the other four moved — five kinds
 * disagreeing about one field.
 *
 * ⇒ Community cards now go through the same function, and
 * {@see testACommunityCardsBioGoesThroughTheSameCut()} is the assertion
 * that keeps them there.
 *
 * ## Levels, stated plainly
 *
 * The community case is driven for real:
 * `getCommunityCardFromGroupData()` is public and pure composition, so the
 * test builds an actual card and reads the emitted field. The page and
 * member kinds go through `truncateBio()` by reflection — the house
 * pattern for this class (`MemberOpenDisputePermissionTest` documents the
 * eleven-dependency constructor that makes a full member build
 * unreachable in a unit test) — so for those two, the wiring is covered by
 * review of the diff and the RULE is covered here.
 */
#[Group('unit')]
#[CoversClass(CardViewService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CardBioWordCutTest extends TestCase
{
    /**
     * ⭐ ANTI-VACUITY CONTROL: an ordinary bio comes back verbatim.
     *
     * A cut that returned '' or always ellipsised would satisfy most of
     * the assertions below.
     */
    public function testAnOrdinaryBioIsUntouched(): void
    {
        $bio = 'Cosmos validator since 2021. Plain-English notes on every upgrade.';

        self::assertSame($bio, $this->cut($bio));
    }

    public function testEmptyAndWhitespaceOnlyBiosAreEmpty(): void
    {
        self::assertSame('', $this->cut(''));
        self::assertSame('', $this->cut("   \n\t "));
    }

    /** Whitespace still collapses, as it did before — including newlines. */
    public function testWhitespaceCollapsesToSingleSpaces(): void
    {
        self::assertSame('one two three', $this->cut("one\n\ntwo\t three  "));
    }

    public function testExactlyTwentyFiveWordsIsNotCut(): void
    {
        $bio = $this->words(25);

        self::assertSame($bio, $this->cut($bio));
        self::assertStringEndsNotWith('…', $this->cut($bio));
    }

    public function testTwentySixWordsKeepsTwentyFiveAndEllipsises(): void
    {
        $out = $this->cut($this->words(26));

        self::assertSame($this->words(25) . '…', $out);
        self::assertSame(25, count(explode(' ', rtrim($out, '…'))));
    }

    /**
     * ⚠ The character bound is not redundant with the word bound: 25 words
     * can be far longer than a card's three lines.
     */
    public function testTwentyFiveLongWordsAreCutByTheCharacterBound(): void
    {
        // 25 × 9 characters + 24 spaces = 249, well past 120.
        $out = $this->cut(trim(str_repeat('abcdefghi ', 25)));

        self::assertStringEndsWith('…', $out);
        self::assertLessThanOrEqual(121, $this->length($out)); // 120 + the '…'
    }

    /**
     * ⭐ THE CASE THE CHARACTER BOUND EXISTS FOR: text with no spaces.
     *
     * CJK is one whitespace-delimited "word", so the word bound alone
     * would pass the whole paragraph onto the front of the card.
     */
    public function testTextWithoutSpacesIsCutByCharacters(): void
    {
        $out = $this->cut(str_repeat('区块链验证人', 60)); // 360 characters, one word

        self::assertStringEndsWith('…', $out);
        self::assertSame(121, $this->length($out)); // 120 + the '…'
    }

    /** And a multibyte cut never produces invalid UTF-8. */
    public function testAMultibyteCutStaysValidUtf8(): void
    {
        $out = $this->cut(str_repeat('区块链验证人', 60));

        self::assertSame($out, (string) mb_convert_encoding($out, 'UTF-8', 'UTF-8'));
        self::assertNotFalse(mb_check_encoding($out, 'UTF-8'));
    }

    /** A URL is ONE word, and a bio holding one is not cut because of it. */
    public function testAUrlCountsAsASingleWord(): void
    {
        $bio = 'Notes and uptime at https://validator.example/status every week.';

        self::assertSame($bio, $this->cut($bio));
    }

    /**
     * ⚠⚠ A URL that does not fit is DROPPED WHOLE, never cut into.
     *
     * Half a link is not a shorter link, it is a broken one.
     */
    public function testAnOverlongUrlIsDroppedRatherThanCut(): void
    {
        $lead = $this->words(8);                            // ~47 characters
        $url  = 'https://validator.example/' . str_repeat('x', 100);
        $out  = $this->cut($lead . ' ' . $url);

        self::assertStringNotContainsString('https://', $out);
        self::assertStringStartsWith($lead, $out);
        self::assertStringEndsWith('…', $out);
    }

    /** The same for an @handle: half a handle names somebody else. */
    public function testAnOverlongHandleIsDroppedRatherThanCut(): void
    {
        $lead   = $this->words(8);
        $handle = '@' . str_repeat('h', 110);
        $out    = $this->cut($lead . ' ' . $handle);

        self::assertStringNotContainsString('@', $out);
        self::assertStringStartsWith($lead, $out);
    }

    /**
     * ⚠ The stated consequence, asserted so nobody has to wonder: a bio
     * that is NOTHING BUT one over-long URL yields "" — no line on the
     * card, rather than a truncated link. The whole bio is on the page.
     */
    public function testABioThatIsOnlyAnOverlongUrlYieldsNoLine(): void
    {
        self::assertSame('', $this->cut('https://validator.example/' . str_repeat('x', 200)));
    }

    /** A short URL-only bio is fine, though — it fits, so it is kept. */
    public function testAShortUrlOnlyBioIsKept(): void
    {
        self::assertSame('https://validator.example/x', $this->cut('https://validator.example/x'));
    }

    /**
     * Never '……'. The group producers cut to ~200 characters and append an
     * ellipsis, so an already-ellipsised value can arrive here.
     */
    public function testAnAlreadyEllipsisedValueNeverGetsASecondEllipsis(): void
    {
        $out = $this->cut($this->words(24) . ' … ' . $this->words(5));

        self::assertStringEndsWith('…', $out);
        self::assertStringEndsNotWith('……', $out);
    }

    /**
     * ⭐ AND THE FIFTH KIND. A community card's bio goes through the same
     * cut, which is the half the design's "one place" missed: this field
     * used to be passed through from the producers untouched.
     *
     * `getCommunityCardFromGroupData()` is public and pure composition
     * (its own docblock: zero extra queries), so this builds a real card.
     */
    public function testACommunityCardsBioGoesThroughTheSameCut(): void
    {
        // 30 words = 119 characters: over the WORD bound and under the
        // character bound, so this isolates the routing rather than
        // whichever cut it happens to land on.
        $long = $this->words(30);

        $service = (new ReflectionClass(CardViewService::class))->newInstanceWithoutConstructor();
        $card    = $service->getCommunityCardFromGroupData(self::groupData($long), 0);

        self::assertSame(
            $this->cut($long),
            $card['bio'],
            'a community card is back on its own bio bound — the five kinds'
            . ' must agree about this field'
        );
        self::assertSame($this->words(25) . '…', $card['bio']);
    }

    /** An absent group description is still '', not null. */
    public function testACommunityCardWithNoDescriptionSendsEmptyString(): void
    {
        $service = (new ReflectionClass(CardViewService::class))->newInstanceWithoutConstructor();
        $card    = $service->getCommunityCardFromGroupData(self::groupData(null), 0);

        self::assertSame('', $card['bio']);
    }

    /**
     * $n distinct THREE-character words: "a00 a01 …".
     *
     * ⚠ Three, not five, and that is load-bearing. At five characters
     * 25 words is 149 characters — past the 120-character bound — so
     * every "25 words" case would really have been testing the
     * CHARACTER cut, and the word bound would have had no test at all.
     * At three, 25 words is 99 characters and the word bound is the
     * only thing that can bite.
     *
     * {@see testTwentyFiveLongWordsAreCutByTheCharacterBound()} covers
     * the other bound deliberately, with nine-character words.
     */
    private function words(int $n): string
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = 'a' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        }

        return implode(' ', $out);
    }

    private function length(string $text): int
    {
        return function_exists('grapheme_strlen') && is_int(grapheme_strlen($text))
            ? (int) grapheme_strlen($text)
            : mb_strlen($text);
    }

    private function cut(string $raw): string
    {
        $truncate = new ReflectionMethod(CardViewService::class, 'truncateBio');

        return (string) $truncate->invoke(null, $raw);
    }

    /** @return array<string, mixed> */
    private static function groupData(?string $description): array
    {
        return [
            'group_id'          => 12,
            'name'              => 'A Group',
            'slug'              => 'a-group',
            'type'              => 'user',
            'privacy'           => 'public',
            'chain_tag'         => null,
            'description'       => $description,
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
