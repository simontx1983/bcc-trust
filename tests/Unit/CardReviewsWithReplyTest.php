<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\CardReviewsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-345: CardReviewsService::withReply() is the one row mapper BOTH Reviews
 * lists use (the entity tab and the member tab). It must carry the viewer's
 * stale reply (user decision 2026-11-18) through the same author hydration
 * as `reply`, and default every field when a row has no bundle.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(CardReviewsService::class)]
final class CardReviewsWithReplyTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/card-reviews-with-reply-stubs.php';
    }

    /** @return array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string, permissions: array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>} */
    private static function reply(int $authorId, string $body): array
    {
        return [
            'id'          => 9000 + $authorId,
            'body'        => $body,
            'author_id'   => $authorId,
            'posted_at'   => '2026-11-18T10:00:00+00:00',
            'edited_at'   => null,
            'feed_id'     => 'feed_' . (9100 + $authorId),
            'permissions' => [],
        ];
    }

    public function testTheViewersStaleReplyIsHydratedBesideTheReply(): void
    {
        $prefetched = ['follower_counts' => []];
        $bundle     = [
            'reply'              => self::reply(506, 'from the new operator'),
            'viewer_stale_reply' => self::reply(504, 'from the old operator'),
            'can_reply'          => ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'not_applicable'],
        ];

        $item = CardReviewsService::withReply(['id' => 70], $bundle, 504, $prefetched);

        self::assertSame('from the new operator', $item['reply']['body'] ?? null);
        self::assertSame('from the old operator', $item['viewer_stale_reply']['body'] ?? null);
        self::assertSame(['id' => 504], $item['viewer_stale_reply']['author'] ?? null, 'hydrated like the reply');
        self::assertSame('not_applicable', $item['permissions']['can_reply']['reason_code']);
        self::assertSame(
            [['author_id' => 506, 'viewer' => 504, 'prefetched' => $prefetched], ['author_id' => 504, 'viewer' => 504, 'prefetched' => $prefetched]],
            Plugin::$withAuthorCalls,
            'both through the list prefetch'
        );
    }

    /** An author id no fixture value shares, so it is recognisable anywhere in the output. */
    private const HIDDEN_AUTHOR = 987654;

    /** @return array{id: int, card_kind: string, name: string, handle: string, avatar_url: string} */
    private static function page(): array
    {
        return ['id' => 4521, 'card_kind' => 'validator', 'name' => 'Acme Validator', 'handle' => 'acme-validator', 'avatar_url' => 'https://img.example/acme.png'];
    }

    /**
     * The person keys a page-attributed reply must never carry, at any depth
     * (the security checklist for this shape).
     */
    private const PERSON_KEYS = [
        'author', 'author_id', 'user_id', 'member_id', 'handle_of_author', 'display_name', 'avatar',
        'profile_url', 'profile', 'rank', 'rank_label', 'current_rank_label', 'trust', 'trust_score',
        'reputation_tier', 'follower_count', 'followers', 'x', 'x_handle', 'github', 'operator',
        'is_operator', 'badges', 'login', 'user_login', 'email',
    ];

    /**
     * @param array<array-key, mixed> $value
     * @return list<string> every key, at every depth
     */
    private static function allKeys(array $value): array
    {
        $keys = [];
        foreach ($value as $key => $inner) {
            $keys[] = (string) $key;
            if (is_array($inner)) {
                array_push($keys, ...self::allKeys($inner));
            }
        }
        return $keys;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return list<mixed> every scalar, at every depth
     */
    private static function allScalars(array $value): array
    {
        $out = [];
        foreach ($value as $inner) {
            if (is_array($inner)) {
                array_push($out, ...self::allScalars($inner));
            } else {
                $out[] = $inner;
            }
        }
        return $out;
    }

    public function testAReplyMarkedAsPageIsThePagesResponseWithNothingOfThePerson(): void
    {
        $reply              = self::reply(self::HIDDEN_AUTHOR, 'we fixed the signer');
        $reply['as_page']   = true;
        $reply['permissions'] = [
            'can_edit'   => ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'not_author'],
            'can_delete' => ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'not_author'],
            'can_report' => ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null],
        ];
        $bundle = ['reply' => $reply, 'viewer_stale_reply' => null, 'can_reply' => ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'not_applicable']];

        $item = CardReviewsService::withReply(['id' => 70], $bundle, 503, null, self::page());

        self::assertSame([
            'id'          => 9000 + self::HIDDEN_AUTHOR,
            'body'        => 'we fixed the signer',
            'author_kind' => 'page',
            'page'        => self::page(),
            'posted_at'   => '2026-11-18T10:00:00+00:00',
            'edited_at'   => null,
            'feed_id'     => 'feed_' . (9100 + self::HIDDEN_AUTHOR),
            'permissions' => $reply['permissions'],
        ], $item['reply'], 'exactly these keys, in this order');

        $keys = self::allKeys($item['reply']);
        foreach (self::PERSON_KEYS as $personKey) {
            self::assertNotContains($personKey, $keys, "no `{$personKey}` at any depth");
        }
        self::assertNotContains('as_page', $keys, 'the internal marker never reaches the wire');
        self::assertNotContains(self::HIDDEN_AUTHOR, self::allScalars($item['reply']), 'the author id appears nowhere');
        self::assertSame([], Plugin::$withAuthorCalls, 'the person is never even hydrated');
    }

    public function testAReplyMarkedAsPageIsNotShownWithoutThePage(): void
    {
        $reply            = self::reply(self::HIDDEN_AUTHOR, 'we fixed the signer');
        $reply['as_page'] = true;

        $item = CardReviewsService::withReply(['id' => 70], ['reply' => $reply, 'viewer_stale_reply' => null, 'can_reply' => ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'not_applicable']], 503, null);

        self::assertNull($item['reply'], 'the member tab (no page) never shows it');
        self::assertSame([], Plugin::$withAuthorCalls);
    }

    /** The REAL ReviewReplyService::withAuthor(): every member reply now says so. */
    public function testAMemberReplySaysItsAuthorIsAMember(): void
    {
        $service = (new \ReflectionClass(\BCC\Trust\Core\Services\ReviewReplyService::class))->newInstanceWithoutConstructor();

        $shaped = $service->withAuthor(self::reply(506, 'from the operator'), 503, null);

        self::assertSame('member', $shaped['author_kind'] ?? null);
        self::assertSame(['id' => 506, 'handle' => 'member506'], $shaped['author'] ?? null);
        self::assertArrayNotHasKey('author_id', $shaped ?? []);
    }

    /**
     * Only a card page's tab lets a reply stand as the page's: the endpoint
     * passes the card kind for the three card targets and none for a
     * member's received reviews, and the service passes that on.
     */
    public function testOnlyACardPagesTabLetsTheReplyStandAsThePages(): void
    {
        $votes = new class extends \BCC\Trust\Core\Repositories\VoteRepository {
            public function __construct()
            {
            }

            public function countByPageId(int $pageId): int
            {
                return 1;
            }

            /** @return list<object> */
            public function findByPageIdPaginated(int $pageId, int $limit, int $offset): array
            {
                return [(object) ['id' => 70, 'voter_user_id' => 0, 'vote_type' => 1, 'explanation' => 'x', 'reason' => null, 'created_at' => '2026-11-18 10:00:00']];
            }
        };
        $service = new CardReviewsService($votes);

        $service->getReviews(4521, 503, 1, 20, 'validator');
        $service->getReviews(4521, 503, 1, 20);

        self::assertSame([true, false], Plugin::$forReviewsCalls);

        $map = (new \ReflectionClassConstant(\BCC\Trust\Core\REST\CardReviewsEndpoint::class, 'CARD_KIND_OF_TARGET'))->getValue();
        self::assertSame(
            ['validator_card' => 'validator', 'project_card' => 'project', 'creator_card' => 'creator'],
            $map,
            'the three card targets, and never user_profile'
        );
    }

    public function testARowWithoutABundleHasNoReplyNoStaleReplyAndCannotReply(): void
    {
        $item = CardReviewsService::withReply(['id' => 70], null, 504, null);

        self::assertArrayHasKey('viewer_stale_reply', $item, 'the field is always present');
        self::assertNull($item['viewer_stale_reply']);
        self::assertNull($item['reply']);
        self::assertSame('not_applicable', $item['permissions']['can_reply']['reason_code']);
        self::assertSame([], Plugin::$withAuthorCalls);
    }
}
