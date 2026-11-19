<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\HeliusSubscriptionManager as M;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-314 follow-up: Provision checked the stored webhook id and POSTed a new
 * webhook with no lock, so two Provisions at once (a double click, two
 * admins) could each create one, the second id overwriting the first and
 * the first webhook left on Helius with no id to delete it by.
 *
 * Pinned here, against modelled Helius responses: the id check that gates
 * the POST and the POST itself run with the address-list lock held, a busy
 * lock sends nothing, and the public ?string contract is unchanged.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HeliusProvisionLockTest extends TestCase
{
    private const CB = 'https://api.example/wp-json/bcc/v1/helius/webhook';

    protected function setUp(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        if (!defined('BCC_HELIUS_API_KEY')) {
            define('BCC_HELIUS_API_KEY', 'test-key');
        }
        if (!defined('BCC_HELIUS_WEBHOOK_SECRET')) {
            define('BCC_HELIUS_WEBHOOK_SECRET', 'test-secret');
        }
        require_once __DIR__ . '/../Stubs/helius-subscription-stubs.php';
        \HeliusWorld::reset();
        unset(\HeliusWorld::$options[M::OPTION_WEBHOOK_ID]);   // not provisioned yet
    }

    private static function heliusCreates(string $id): void
    {
        \HeliusWorld::$createResponses[] = ['code' => 200, 'body' => (string) json_encode(['webhookID' => $id])];
    }

    public function testTheIdCheckAndThePostBothRunWithTheLockHeld(): void
    {
        self::heliusCreates('wh-2');

        $r = M::provisionSharedWebhookResult(self::CB);

        self::assertSame(['outcome' => 'provisioned', 'webhook_id' => 'wh-2'], $r);
        self::assertSame('wh-2', \HeliusWorld::$options[M::OPTION_WEBHOOK_ID] ?? null);
        self::assertNotSame([], \HeliusWorld::$idReadLocks);
        self::assertNotContains(0, \HeliusWorld::$idReadLocks, 'the stored id is never read without the lock');
        self::assertSame([1], \HeliusWorld::$createLocks, 'one POST, with the lock held');
        self::assertSame(0, \HeliusWorld::$lockHeld, 'the lock is released');
    }

    public function testAProvisionThatFindsAnIdUnderTheLockSendsNothing(): void
    {
        // What a Provision that waited for the lock sees: the id the first one stored.
        \HeliusWorld::$options[M::OPTION_WEBHOOK_ID] = 'wh-1';

        $r = M::provisionSharedWebhookResult(self::CB);

        self::assertSame(['outcome' => 'provisioned', 'webhook_id' => 'wh-1'], $r);
        self::assertSame([], \HeliusWorld::$createLocks, 'no second webhook is created');
        self::assertSame('wh-1', \HeliusWorld::$options[M::OPTION_WEBHOOK_ID] ?? null);
    }

    public function testABusyLockReadsAndSendsNothing(): void
    {
        \HeliusWorld::$lockResults = [false];
        self::heliusCreates('wh-2');

        $r = M::provisionSharedWebhookResult(self::CB);

        self::assertSame(['outcome' => 'busy', 'webhook_id' => null], $r);
        self::assertSame([], \HeliusWorld::$idReadLocks, 'nothing is read without the lock');
        self::assertSame([], \HeliusWorld::$createLocks);
        self::assertArrayNotHasKey(M::OPTION_WEBHOOK_ID, \HeliusWorld::$options);
    }

    public function testARefusedCreateStoresNothing(): void
    {
        // No create response queued: the POST fails.
        $r = M::provisionSharedWebhookResult(self::CB);

        self::assertSame(['outcome' => 'failed', 'webhook_id' => null], $r);
        self::assertArrayNotHasKey(M::OPTION_WEBHOOK_ID, \HeliusWorld::$options);
        self::assertSame(0, \HeliusWorld::$lockHeld);
    }

    public function testTheIdReturningMethodKeepsItsContract(): void
    {
        self::heliusCreates('wh-2');
        self::assertSame('wh-2', M::provisionSharedWebhook(self::CB));
        self::assertSame('wh-2', M::provisionSharedWebhook(self::CB), 'already provisioned: the same id, nothing sent');
        self::assertCount(1, \HeliusWorld::$createLocks);

        unset(\HeliusWorld::$options[M::OPTION_WEBHOOK_ID]);
        \HeliusWorld::$lockResults = [false];
        self::assertNull(M::provisionSharedWebhook(self::CB), 'busy reads as null here');
    }
}
