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
 * EVERY binding column, a verified code (when it carries one) and works
 * once; a resend respects its window, clears the verification and never
 * extends past 30 minutes; an expired row is dead; the purge is bounded to
 * expired rows.
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

    private static function make(string $id, int $ttl = 600, ?string $resendAfter = null, bool $withCode = true): void
    {
        StepUps::create([
            'id' => $id, 'user_id' => self::STAFF, 'action' => 'link_owner', 'page_id' => 501,
            'target_user_id' => 900, 'role' => 'owner', 'code_hash' => $withCode ? str_repeat('c', 64) : null,
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
        self::assertTrue(StepUps::markCodeVerified($id, self::STAFF, self::at(0), str_repeat('c', 64)), 'the last allowed attempt can still be the right one');
        self::assertTrue(StepUps::consume(self::binding($id), self::at(0)));
    }

    public function testACodeStepUpIsNeverConsumedWithoutAVerifiedCode(): void
    {
        $id = str_repeat('7', 32);
        self::make($id);
        StepUps::countAttempt($id, self::STAFF, self::at(0));

        self::assertFalse(StepUps::consume(self::binding($id), self::at(0)), 'attempts under the cap, but no verified code');
        self::assertTrue(StepUps::markCodeVerified($id, self::STAFF, self::at(0), str_repeat('c', 64)));
        self::assertFalse(StepUps::markCodeVerified($id, self::STAFF, self::at(1), str_repeat('c', 64)), 'recorded once');
        self::assertNotNull(StepUps::find($id, self::STAFF)?->code_verified_at);
        self::assertTrue(StepUps::consume(self::binding($id), self::at(0)));
        self::assertFalse(StepUps::markCodeVerified($id, self::STAFF, self::at(2), str_repeat('c', 64)), 'a spent step-up');
    }

    public function testAPasswordOnlyStepUpNeedsNoCodeAndCannotBeMarked(): void
    {
        $id = str_repeat('8', 32);
        StepUps::create([
            'id' => $id, 'user_id' => self::STAFF, 'action' => 'add_team_member', 'page_id' => 501,
            'target_user_id' => 900, 'role' => 'representative', 'code_hash' => null,
            'created_at' => self::at(0), 'expires_at' => self::at(600), 'resend_after' => null,
        ]);
        $binding = ['action' => 'add_team_member', 'role' => 'representative'] + self::binding($id);

        self::assertFalse(StepUps::markCodeVerified($id, self::STAFF, self::at(0), str_repeat('c', 64)), 'no code to verify');
        self::assertTrue(StepUps::consume($binding, self::at(0)));
    }

    public function testAnOwnerLevelStepUpIsNeverStoredOrConsumedWithoutACode(): void
    {
        global $wpdb;
        try {
            self::make(str_repeat('4', 32), 600, null, false);
            self::fail('an owner-level step-up was stored without a code');
        } catch (\InvalidArgumentException $e) {
            self::assertNull(StepUps::find(str_repeat('4', 32), self::STAFF));
        }

        // A row that lost its code some other way is still never consumed.
        $id = str_repeat('3', 32);
        self::make($id);
        $wpdb->query($wpdb->prepare('UPDATE `' . StepUps::table() . '` SET code_hash = NULL WHERE id = %s', $id));
        self::assertFalse(StepUps::consume(self::binding($id), self::at(0)));
    }

    public function testADeadStepUpCannotBeMarked(): void
    {
        $expired = str_repeat('9', 32);
        self::make($expired, -1);
        self::assertFalse(StepUps::markCodeVerified($expired, self::STAFF, self::at(0), str_repeat('c', 64)), 'expired');

        $other = str_repeat('6', 32);
        self::make($other);
        self::assertFalse(StepUps::markCodeVerified($other, self::STAFF + 1, self::at(0), str_repeat('c', 64)), 'another user');
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
        self::assertTrue(StepUps::markCodeVerified($id, self::STAFF, self::at(0), str_repeat('c', 64)));
        self::assertTrue(StepUps::consume(self::binding($id), self::at(0)));
        self::assertFalse(StepUps::consume(self::binding($id), self::at(0)), 'single use');
        // A later consume would rewrite used_at to a NEW value (a changed
        // row), so this one proves the used_at IS NULL condition itself.
        self::assertFalse(StepUps::consume(self::binding($id), self::at(5)), 'single use, later');
        self::assertFalse(StepUps::countAttempt($id, self::STAFF, self::at(0)), 'a spent step-up takes no more attempts');
    }

    public function testAConsumeRollsBackWithTheActionsTransaction(): void
    {
        global $wpdb;
        $id = str_repeat('c', 32);
        self::make($id);
        self::assertTrue(StepUps::markCodeVerified($id, self::STAFF, self::at(0), str_repeat('c', 64)));

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

    public function testConfirmationRequiresTheCurrentCodeVersion(): void
    {
        $id = str_repeat('6', 32);
        self::make($id);
        self::assertTrue(StepUps::countAttempt($id, self::STAFF, self::at(0)));
        $checkedHash = StepUps::find($id, self::STAFF)?->code_hash;
        self::assertSame(str_repeat('c', 64), $checkedHash);

        self::assertTrue(StepUps::replaceCode($id, self::STAFF, str_repeat('9', 64), self::at(600), self::at(60), self::at(0)));
        self::assertFalse(StepUps::markCodeVerified($id, self::STAFF, self::at(0), str_repeat('c', 64)));
        self::assertNull(StepUps::find($id, self::STAFF)?->code_verified_at);
        self::assertFalse(StepUps::consume(self::binding($id), self::at(0)));
        self::assertTrue(StepUps::markCodeVerified($id, self::STAFF, self::at(0), str_repeat('9', 64)));
        self::assertTrue(StepUps::consume(self::binding($id), self::at(0)));
    }

    public function testAResendClearsTheVerificationAndIsCappedAtThirtyMinutes(): void
    {
        $id      = str_repeat('5', 32);
        $created = time() - 1700; // one clock read: no second-boundary flake
        StepUps::create([
            'id' => $id, 'user_id' => self::STAFF, 'action' => 'link_owner', 'page_id' => 501,
            'target_user_id' => 900, 'role' => 'owner', 'code_hash' => str_repeat('c', 64),
            'created_at' => gmdate('Y-m-d H:i:s', $created), 'expires_at' => gmdate('Y-m-d H:i:s', $created + 1790),
            'resend_after' => gmdate('Y-m-d H:i:s', $created),
        ]);
        self::assertTrue(StepUps::markCodeVerified($id, self::STAFF, self::at(0), str_repeat('c', 64)));

        self::assertTrue(StepUps::replaceCode($id, self::STAFF, str_repeat('9', 64), gmdate('Y-m-d H:i:s', $created + 2300), self::at(60), self::at(0)));
        $row = StepUps::find($id, self::STAFF);
        self::assertNull($row?->code_verified_at, 'the new code must be entered');
        self::assertSame(gmdate('Y-m-d H:i:s', $created + 1800), $row?->expires_at, 'created_at + 30 min, not now + 10 min');
        self::assertFalse(StepUps::consume(self::binding($id), self::at(0)));
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
