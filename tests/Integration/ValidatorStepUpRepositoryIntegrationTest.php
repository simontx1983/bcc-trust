<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Repositories\ValidatorStepUpRepository as StepUps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The step-up table's conditional UPDATEs on a real engine: an attempt is
 * counted only while the step-up is live and under the cap; a consume needs
 * EVERY binding column and works once; a resend respects its window; an
 * expired row is dead; the purge is bounded to expired rows.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(StepUps::class)]
final class ValidatorStepUpRepositoryIntegrationTest extends TestCase
{
    private const STAFF = 951001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM `' . StepUps::table() . '` WHERE user_id IN (%d, %d)', self::STAFF, self::STAFF + 1));
    }

    private static function at(int $offset): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offset);
    }

    private static function make(string $id, int $ttl = 600, ?string $resendAfter = null): void
    {
        StepUps::create([
            'id' => $id, 'user_id' => self::STAFF, 'action' => 'link_owner', 'page_id' => 501,
            'target_user_id' => 900, 'role' => 'owner', 'code_hash' => str_repeat('c', 64),
            'created_at' => self::at(0), 'expires_at' => self::at($ttl), 'resend_after' => $resendAfter,
        ]);
    }

    /** @return array{id: string, user_id: int, action: string, page_id: int, target_user_id: int, role: string} */
    private static function binding(string $id): array
    {
        return ['id' => $id, 'user_id' => self::STAFF, 'action' => 'link_owner', 'page_id' => 501, 'target_user_id' => 900, 'role' => 'owner'];
    }

    public function testAttemptsAreCappedAtomically(): void
    {
        $id = str_repeat('a', 32);
        self::make($id);

        for ($i = 0; $i < StepUps::MAX_ATTEMPTS; $i++) {
            self::assertTrue(StepUps::countAttempt($id, self::STAFF, self::at(0)), 'attempt ' . ($i + 1));
        }
        self::assertFalse(StepUps::countAttempt($id, self::STAFF, self::at(0)), 'the cap');
        self::assertSame('5', (string) StepUps::find($id, self::STAFF)?->attempts);
        self::assertTrue(StepUps::consume(self::binding($id), self::at(0)), 'the last allowed attempt can still be the right one');
    }

    public function testAConsumeNeedsEveryBindingColumnAndWorksOnce(): void
    {
        $id = str_repeat('b', 32);
        self::make($id);

        foreach ([
            ['user_id' => self::STAFF + 1], ['action' => 'revoke_owner'], ['page_id' => 502],
            ['target_user_id' => 901], ['role' => 'administrator'], ['id' => str_repeat('f', 32)],
        ] as $override) {
            self::assertFalse(StepUps::consume($override + self::binding($id), self::at(0)), json_encode($override) ?: '');
        }
        self::assertTrue(StepUps::consume(self::binding($id), self::at(0)));
        self::assertFalse(StepUps::consume(self::binding($id), self::at(0)), 'single use');
        self::assertFalse(StepUps::countAttempt($id, self::STAFF, self::at(0)), 'a spent step-up takes no more attempts');
    }

    public function testAConsumeRollsBackWithTheActionsTransaction(): void
    {
        global $wpdb;
        $id = str_repeat('c', 32);
        self::make($id);

        $wpdb->query('START TRANSACTION');
        self::assertTrue(StepUps::consume(self::binding($id), self::at(0)));
        $wpdb->query('ROLLBACK');

        self::assertNull(StepUps::find($id, self::STAFF)?->used_at, 'not spent: the action rolled back');
        self::assertTrue(StepUps::consume(self::binding($id), self::at(0)));
    }

    public function testAnExpiredStepUpIsDead(): void
    {
        $id = str_repeat('d', 32);
        self::make($id, -1);

        self::assertFalse(StepUps::countAttempt($id, self::STAFF, self::at(0)));
        self::assertFalse(StepUps::consume(self::binding($id), self::at(0)));
    }

    public function testAResendRespectsItsWindowAndKeepsTheAttempts(): void
    {
        $id = str_repeat('e', 32);
        self::make($id, 600, self::at(60));
        StepUps::countAttempt($id, self::STAFF, self::at(0));

        self::assertFalse(StepUps::replaceCode($id, self::STAFF, str_repeat('9', 64), self::at(600), self::at(60), self::at(0)), 'inside the window');
        self::assertTrue(StepUps::replaceCode($id, self::STAFF, str_repeat('9', 64), self::at(600), self::at(120), self::at(61)));
        $row = StepUps::find($id, self::STAFF);
        self::assertSame([str_repeat('9', 64), '1'], [$row?->code_hash, (string) $row?->attempts]);
    }

    public function testThePurgeDeletesOnlyExpiredRows(): void
    {
        self::make(str_repeat('1', 32), -7200);
        self::make(str_repeat('2', 32), 600);

        self::assertSame(1, StepUps::purgeExpired(self::at(-3600)));
        self::assertNull(StepUps::find(str_repeat('1', 32), self::STAFF));
        self::assertNotNull(StepUps::find(str_repeat('2', 32), self::STAFF));
    }

    public function testAFailedReadThrows(): void
    {
        self::make(str_repeat('3', 32));
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote(StepUps::table(), '/') . '/';

        $this->expectException(RepositoryReadFailure::class);
        StepUps::find(str_repeat('3', 32), self::STAFF);
    }
}
