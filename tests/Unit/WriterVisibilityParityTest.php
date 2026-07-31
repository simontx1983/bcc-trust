<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\PostsService;
use BCC\Trust\Core\Services\VoteService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Per-path proof that all three create flows — status, photo, GIF — thread
 * `$visibility` into the ONE shared gate (PostsService::gateGroupPost) and
 * honour its verdict identically. Calling only gateGroupPost() would not
 * prove each creation path passes visibility correctly, so this exercises
 * the real createStatus / createPhotoPost / createGifPost methods with the
 * PeepSo writers stubbed as spies.
 *
 * For each path: an ineligible `public_all` is rejected with
 * bcc_permission_denied BEFORE the writer runs (spy shows 0 calls → no
 * post/activity/media side effect); an eligible `public_all` proceeds
 * (spy shows 1 call carrying visibility=public_all); and members_only /
 * public_group are unaffected for the same public_all-ineligible member.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WriterVisibilityParityTest extends TestCase
{
    private const CLOSED = 1;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/writer-parity-stubs.php';
        $GLOBALS['__bcc_puball_fixture'] = ['groups' => [], 'admins' => [], 'writer_calls' => []];
    }

    /** @return iterable<string, array{string}> */
    public static function kinds(): iterable
    {
        yield 'status' => ['status'];
        yield 'photo'  => ['photo'];
        yield 'gif'    => ['gif'];
    }

    private function service(): PostsService
    {
        return new PostsService(new VoteService(), new VoteRepository());
    }

    /**
     * @param array<int, string> $membership
     */
    private function registerGroup(int $id, bool $optIn, array $membership): void
    {
        $GLOBALS['__bcc_puball_fixture']['groups'][$id] = [
            'post_type'  => 'peepso-group',
            'meta'       => [
                'peepso_group_privacy'          => (string) self::CLOSED,
                '_bcc_group_public_all_members' => $optIn ? '1' : '',
            ],
            'membership' => $membership,
        ];
    }

    /** @return array<string, mixed> */
    private function invoke(string $kind, int $authorId, int $groupId, string $visibility): array
    {
        $svc = $this->service();
        return match ($kind) {
            'status' => $svc->createStatus($authorId, 'hello world', $groupId, $visibility),
            'photo'  => $svc->createPhotoPost($authorId, ['tmp_name' => 'x', 'name' => 'x.jpg'], 'cap', $groupId, $visibility),
            'gif'    => $svc->createGifPost($authorId, 'https://giphy.com/gifs/x', 'cap', $groupId, $visibility),
            default  => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private function writerCalls(string $kind): array
    {
        return $GLOBALS['__bcc_puball_fixture']['writer_calls'][$kind] ?? [];
    }

    #[DataProvider('kinds')]
    public function testIneligiblePublicAllRejectedWithoutInvokingWriter(string $kind): void
    {
        // Ordinary member of a closed group, opt-in off → public_all denied.
        $this->registerGroup(700, false, [7 => 'member']);

        $result = $this->invoke($kind, 7, 700, 'public_all');

        self::assertSame('bcc_permission_denied', $result['error'] ?? null, "{$kind}: rejected");
        self::assertCount(0, $this->writerCalls($kind), "{$kind}: writer must NOT be invoked (no side effect)");
    }

    #[DataProvider('kinds')]
    public function testEligiblePublicAllProceedsToWriterWithVisibility(string $kind): void
    {
        // Owner is always eligible to syndicate.
        $this->registerGroup(700, false, [1 => 'member_owner']);

        $result = $this->invoke($kind, 1, 700, 'public_all');

        self::assertTrue($result['ok'] ?? false, "{$kind}: eligible public_all proceeds");
        $calls = $this->writerCalls($kind);
        self::assertCount(1, $calls, "{$kind}: writer invoked exactly once");
        self::assertSame('public_all', $calls[0]['visibility'], "{$kind}: writer received public_all");
        self::assertSame(700, $calls[0]['group_id'], "{$kind}: single origin group");
    }

    #[DataProvider('kinds')]
    public function testMembersOnlyUnaffectedForPublicAllIneligibleMember(string $kind): void
    {
        // Same member who CANNOT public_all — members_only must still post.
        $this->registerGroup(700, false, [7 => 'member']);

        $result = $this->invoke($kind, 7, 700, 'members_only');

        self::assertTrue($result['ok'] ?? false, "{$kind}: members_only unaffected");
        $calls = $this->writerCalls($kind);
        self::assertCount(1, $calls, "{$kind}: writer invoked");
        self::assertSame('members_only', $calls[0]['visibility'], "{$kind}: visibility threaded");
    }

    #[DataProvider('kinds')]
    public function testPublicGroupUnaffectedForPublicAllIneligibleMember(string $kind): void
    {
        $this->registerGroup(700, false, [7 => 'member']);

        $result = $this->invoke($kind, 7, 700, 'public_group');

        self::assertTrue($result['ok'] ?? false, "{$kind}: public_group unaffected");
        $calls = $this->writerCalls($kind);
        self::assertCount(1, $calls, "{$kind}: writer invoked");
        self::assertSame('public_group', $calls[0]['visibility'], "{$kind}: visibility threaded");
    }

    #[DataProvider('kinds')]
    public function testFloorPostBypassesGroupGate(string $kind): void
    {
        // group_id = 0 → Floor/own-wall: the group gate never runs, so even
        // public_all is not group-gated (moot — no group to syndicate from).
        $result = $this->invoke($kind, 7, 0, 'public_all');

        self::assertTrue($result['ok'] ?? false, "{$kind}: Floor post proceeds");
        $calls = $this->writerCalls($kind);
        self::assertCount(1, $calls, "{$kind}: writer invoked once");
        self::assertSame(0, $calls[0]['group_id'], "{$kind}: no group origin");
    }

    #[DataProvider('kinds')]
    public function testReadonlyMemberCannotAuthorAnyVisibility(string $kind): void
    {
        // A muted (member_readonly) member is an active member (READ set) so
        // the membership gate passes, but read-only means "read, not post" —
        // rejected before the writer for EVERY visibility, no side effect.
        $this->registerGroup(700, false, [7 => 'member_readonly']);

        foreach (['members_only', 'public_group', 'public_all'] as $vis) {
            $result = $this->invoke($kind, 7, 700, $vis);
            self::assertSame('bcc_permission_denied', $result['error'] ?? null, "{$kind}/{$vis}: readonly rejected");
        }
        self::assertCount(0, $this->writerCalls($kind), "{$kind}: writer never invoked for a readonly author");
    }
}
