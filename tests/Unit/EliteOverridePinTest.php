<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\EliteOverridePin;
use BCC\Trust\Core\Services\MemberSelfPageService;
use PHPUnit\Framework\TestCase;


/**
 * F-113 — the manual Elite pin: split store, and it FAILS CLOSED.
 *
 * Runs the real resolver logic; only the four WordPress meta calls are
 * replaced (in-memory) through the class's protected collaborator hooks.
 */
final class EliteOverridePinTest extends TestCase
{
    private const ENTITY_PAGE = 4242;

    public function testAnEntityPagePinLivesInPostMeta(): void
    {
        $pin = $this->pin();
        self::assertTrue($pin->pin(self::ENTITY_PAGE, 7, '2026-11-11 10:00:00'));

        self::assertTrue($pin->isElitePinned(self::ENTITY_PAGE));
        self::assertSame(['admin_id' => 7, 'granted_at' => '2026-11-11 10:00:00'], $pin->read(self::ENTITY_PAGE));
        self::assertArrayHasKey(self::ENTITY_PAGE, $pin->post);
        self::assertSame([], $pin->user, 'nothing written to user meta for an entity page');
    }

    public function testASelfPagePinLivesInTheOwnersUserMeta(): void
    {
        $pin  = $this->pin();
        $self = MemberSelfPageService::ID_BASE + 55;
        self::assertTrue($pin->pin($self, 7, '2026-11-11 10:00:00'));

        self::assertTrue($pin->isElitePinned($self));
        self::assertArrayHasKey(55, $pin->user, 'keyed by the OWNER, since a self-page id is not a wp_post');
        self::assertSame([], $pin->post, 'no orphan post meta for a synthetic id');

        $pin->unpin($self);
        self::assertFalse($pin->isElitePinned($self));
    }

    public function testUnpinRemovesAnEntityPin(): void
    {
        $pin = $this->pin();
        $pin->pin(self::ENTITY_PAGE, 7, '2026-11-11 10:00:00');
        $pin->unpin(self::ENTITY_PAGE);
        self::assertFalse($pin->isElitePinned(self::ENTITY_PAGE));
        self::assertNull($pin->read(self::ENTITY_PAGE));
    }

    /** @return array<string, array{0: mixed}> */
    public static function unreadableValues(): array
    {
        return [
            'read error / absent (empty string)' => [''],
            'read error (false)'                 => [false],
            'not JSON'                           => ['garbage'],
            'JSON without an admin'              => ['{"granted_at":"x"}'],
            'admin id zero'                      => ['{"admin_id":0}'],
            'a JSON scalar'                      => ['1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableValues')]
    public function testAnUnreadableMarkerIsNotPinned(mixed $raw): void
    {
        $pin = $this->pin();
        $pin->post[self::ENTITY_PAGE] = $raw;
        self::assertFalse($pin->isElitePinned(self::ENTITY_PAGE), 'fails CLOSED: a DB blip can never make a page elite');
        self::assertNull($pin->read(self::ENTITY_PAGE));
    }

    public function testInvalidIdsAreRefused(): void
    {
        $pin = $this->pin();
        self::assertFalse($pin->pin(0, 7, 'x'));
        self::assertFalse($pin->pin(self::ENTITY_PAGE, 0, 'x'), 'a pin always names its admin');
        self::assertFalse($pin->isElitePinned(0));
    }

    private function pin(): object
    {
        return new class extends EliteOverridePin {
            /** @var array<int, mixed> */
            public array $post = [];
            /** @var array<int, mixed> */
            public array $user = [];

            protected function readPostMeta(int $pageId): mixed
            {
                return $this->post[$pageId] ?? '';
            }
            protected function writePostMeta(int $pageId, string $value): bool
            {
                $this->post[$pageId] = $value;
                return true;
            }
            protected function deletePostMeta(int $pageId): void
            {
                unset($this->post[$pageId]);
            }
            protected function readUserMeta(int $userId): mixed
            {
                return $this->user[$userId] ?? '';
            }
            protected function writeUserMeta(int $userId, string $value): bool
            {
                $this->user[$userId] = $value;
                return true;
            }
            protected function deleteUserMeta(int $userId): void
            {
                unset($this->user[$userId]);
            }
        };
    }
}
