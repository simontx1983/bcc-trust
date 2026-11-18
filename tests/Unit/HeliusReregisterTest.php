<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\HeliusSubscriptionManager as M;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-314: a Helius webhook deleted on Helius's side, or pointing at an old
 * callback URL (e.g. after a domain cutover), could not be re-registered —
 * Provision returned the stale stored id, and the panel showed "—" with no
 * reason. Pinned here, against modelled Helius responses:
 *
 *   - only a 404 means "missing"; any other failure is "unreachable" and
 *     changes nothing (never orphan a live webhook);
 *   - a wrong URL is fixed with a FULL-body PUT (Helius does not document
 *     whether unsent fields survive), carrying the live address list;
 *   - a missing webhook is recreated and refilled.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HeliusReregisterTest extends TestCase
{
    private const CB  = 'https://api.example/wp-json/bcc/v1/helius/webhook';
    private const OLD = 'https://old.example/wp-json/bcc/v1/helius/webhook';
    private const A   = 'AaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaA';

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
    }

    private static function live(string $url, array $addresses = [self::A]): void
    {
        \HeliusWorld::remoteDetail([
            'webhookID'        => 'wh-1',
            'webhookURL'       => $url,
            'accountAddresses' => $addresses,
            'transactionTypes' => ['NFT_SALE', 'TRANSFER'],
            'webhookType'      => 'enhanced',
        ]);
    }

    // ── status() ──────────────────────────────────────────────────────

    public function testStatusTellsAMissingWebhookFromAnUnreachableOne(): void
    {
        \HeliusWorld::$gets[] = ['code' => 404, 'body' => '{}'];
        self::assertSame(M::REMOTE_MISSING, M::status(self::CB)['remote_state']);

        \HeliusWorld::$gets[] = ['code' => 500, 'body' => '{}'];
        self::assertSame(M::REMOTE_UNREACHABLE, M::status(self::CB)['remote_state'], 'only a 404 means missing');

        // No response queued: a transport error.
        self::assertSame(M::REMOTE_UNREACHABLE, M::status(self::CB)['remote_state']);
    }

    public function testStatusFlagsACallbackThatPointsElsewhere(): void
    {
        self::live(self::OLD);
        $s = M::status(self::CB);
        self::assertSame(M::REMOTE_OK, $s['remote_state']);
        self::assertFalse($s['callback_matches']);

        self::live(self::CB);
        self::assertTrue(M::status(self::CB)['callback_matches']);
    }

    // ── reregisterSharedWebhook() ─────────────────────────────────────

    public function testAWrongUrlIsFixedWithAFullBodyPutCarryingTheLiveList(): void
    {
        self::live(self::OLD);   // the check
        self::live(self::OLD);   // the re-read under the lock

        $r = M::reregisterSharedWebhook(self::CB);

        self::assertSame('updated', $r['outcome']);
        self::assertCount(1, \HeliusWorld::$putBodies);
        $body = \HeliusWorld::$putBodies[0];
        self::assertSame(self::CB, $body['webhookURL']);
        self::assertSame([self::A], $body['accountAddresses'], 'the live address list is sent back, not dropped');
        self::assertSame(['NFT_SALE', 'TRANSFER'], $body['transactionTypes']);
        self::assertSame('enhanced', $body['webhookType']);
        self::assertSame('test-secret', $body['authHeader']);
        self::assertSame(0, \HeliusWorld::$lockHeld, 'the lock is released');
    }

    public function testAMissingWebhookIsRecreatedAndRefilled(): void
    {
        \HeliusWorld::$gets[] = ['code' => 404, 'body' => '{}'];   // the first check
        \HeliusWorld::$gets[] = ['code' => 404, 'body' => '{}'];   // the re-check under the lock
        \HeliusWorld::$createResponses[] = ['code' => 200, 'body' => (string) json_encode(['webhookID' => 'wh-2'])];
        \HeliusWorld::remoteList([]);                     // the refill reads the new, empty list
        \HeliusWorld::$links[1] = [['id' => 11, 'wallet_address' => self::A]]; // one managed link to put back

        $r = M::reregisterSharedWebhook(self::CB);

        self::assertSame('recreated', $r['outcome']);
        self::assertSame('wh-1', $r['previous_webhook_id'] ?? null, 'the result names the id it replaced');
        self::assertSame('wh-2', \HeliusWorld::$options[M::OPTION_WEBHOOK_ID] ?? null, 'the new id replaces the stale one');
        self::assertSame([[self::A]], \HeliusWorld::$puts, 'the fresh webhook is refilled with the managed address');
        self::assertSame(0, \HeliusWorld::$lockHeld, 'every acquire is released');
    }

    // ── F-314 follow-up: a single 404 never recreates ─────────────────

    public function testARecreateIsDecidedUnderTheLockAndLogsBothIds(): void
    {
        \HeliusWorld::$gets[] = ['code' => 404, 'body' => '{}'];
        \HeliusWorld::$gets[] = ['code' => 404, 'body' => '{}'];
        \HeliusWorld::$createResponses[] = ['code' => 200, 'body' => (string) json_encode(['webhookID' => 'wh-2'])];
        \HeliusWorld::remoteList([]);
        // The re-check GET must run with the lock held.
        $heldAtRecheck = null;
        \HeliusWorld::$onNextAcquire = static function () use (&$heldAtRecheck): void {
            $heldAtRecheck = \HeliusWorld::$getCalls;
        };

        M::reregisterSharedWebhook(self::CB);

        self::assertSame(1, $heldAtRecheck, 'the lock is taken after the first GET and before the re-check');
        $at = array_search(
            'warning:[HeliusSubscriptionManager] shared webhook missing on Helius; recreated',
            \BCC\Core\Log\Logger::$lines,
            true
        );
        self::assertIsInt($at, 'a recreate is logged');
        self::assertSame(
            ['previous_webhook_id' => 'wh-1', 'webhook_id' => 'wh-2'],
            \BCC\Core\Log\Logger::$contexts[$at]
        );
    }

    public function testAOneOff404RecreatesNothing(): void
    {
        \HeliusWorld::$gets[] = ['code' => 404, 'body' => '{}'];
        self::live(self::CB);   // the re-check finds it after all

        $r = M::reregisterSharedWebhook(self::CB);

        self::assertSame('already_current', $r['outcome']);
        self::assertSame('wh-1', \HeliusWorld::$options[M::OPTION_WEBHOOK_ID] ?? null, 'the live id is kept');
        self::assertSame([], \HeliusWorld::$postArgs, 'no webhook is created');
    }

    public function testAOneOff404WithTheOldUrlIsFixedNotRecreated(): void
    {
        \HeliusWorld::$gets[] = ['code' => 404, 'body' => '{}'];
        self::live(self::OLD);   // the re-check
        self::live(self::OLD);   // the PUT's own re-read

        $r = M::reregisterSharedWebhook(self::CB);

        self::assertSame('updated', $r['outcome']);
        self::assertCount(1, \HeliusWorld::$putBodies);
        self::assertSame(self::CB, \HeliusWorld::$putBodies[0]['webhookURL']);
        self::assertSame('wh-1', \HeliusWorld::$options[M::OPTION_WEBHOOK_ID] ?? null);
        self::assertSame(0, \HeliusWorld::$lockHeld, 'the nested acquire is released too');
    }

    public function testAWebhookAnotherRequestRecreatedIsCheckedNotReplaced(): void
    {
        \HeliusWorld::$gets[] = ['code' => 404, 'body' => '{}'];   // wh-1 is gone
        // While this request waited for the lock, another one recreated it.
        \HeliusWorld::$onNextAcquire = static function (): void {
            \HeliusWorld::$options[M::OPTION_WEBHOOK_ID] = 'wh-2';
        };
        \HeliusWorld::remoteDetail(['webhookID' => 'wh-2', 'webhookURL' => self::CB, 'accountAddresses' => [self::A]]);

        $r = M::reregisterSharedWebhook(self::CB);

        self::assertSame('already_current', $r['outcome']);
        self::assertSame('wh-2', $r['webhook_id']);
        self::assertSame('wh-2', \HeliusWorld::$options[M::OPTION_WEBHOOK_ID] ?? null, 'the other request\'s webhook is kept');
        self::assertStringContainsString('/v0/webhooks/wh-2?', \HeliusWorld::$getUrls[1], 'the re-check reads the id stored NOW');
        self::assertSame([], \HeliusWorld::$postArgs, 'no third webhook is created');
    }

    public function testAnUnreachableRecheckRecreatesNothing(): void
    {
        \HeliusWorld::$gets[] = ['code' => 404, 'body' => '{}'];
        \HeliusWorld::$gets[] = ['code' => 503, 'body' => '{}'];

        $r = M::reregisterSharedWebhook(self::CB);

        self::assertSame('unreachable', $r['outcome']);
        self::assertSame('wh-1', \HeliusWorld::$options[M::OPTION_WEBHOOK_ID] ?? null);
        self::assertSame([], \HeliusWorld::$postArgs);
    }

    public function testABusyLockAfterA404RecreatesNothing(): void
    {
        \HeliusWorld::$gets[] = ['code' => 404, 'body' => '{}'];
        \HeliusWorld::$lockResults = [false];

        $r = M::reregisterSharedWebhook(self::CB);

        self::assertSame('busy', $r['outcome']);
        self::assertSame(1, \HeliusWorld::$getCalls, 'nothing is read or written without the lock');
        self::assertSame('wh-1', \HeliusWorld::$options[M::OPTION_WEBHOOK_ID] ?? null);
        self::assertSame([], \HeliusWorld::$postArgs);
    }

    public function testAFailedRecreateIsLoggedWithTheIdItForgot(): void
    {
        \HeliusWorld::$gets[] = ['code' => 404, 'body' => '{}'];
        \HeliusWorld::$gets[] = ['code' => 404, 'body' => '{}'];
        // No create response queued: the POST fails.

        $r = M::reregisterSharedWebhook(self::CB);

        self::assertSame('recreate_failed', $r['outcome']);
        self::assertSame('wh-1', $r['previous_webhook_id'] ?? null);
        $at = array_search(
            'error:[HeliusSubscriptionManager] shared webhook missing on Helius; creating a new one failed',
            \BCC\Core\Log\Logger::$lines,
            true
        );
        self::assertIsInt($at);
        self::assertSame(['previous_webhook_id' => 'wh-1'], \BCC\Core\Log\Logger::$contexts[$at]);
    }

    public function testAnUnreachableHeliusChangesNothing(): void
    {
        \HeliusWorld::$gets[] = ['code' => 401, 'body' => '{}'];

        $r = M::reregisterSharedWebhook(self::CB);

        self::assertSame('unreachable', $r['outcome']);
        self::assertSame('wh-1', \HeliusWorld::$options[M::OPTION_WEBHOOK_ID] ?? null, 'a live webhook is never orphaned');
        self::assertSame([], \HeliusWorld::$putBodies);
        self::assertSame([], \HeliusWorld::$postArgs);
    }

    public function testACorrectUrlIsANoOp(): void
    {
        self::live(self::CB);

        self::assertSame('already_current', M::reregisterSharedWebhook(self::CB)['outcome']);
        self::assertSame([], \HeliusWorld::$putBodies);
    }

    public function testABusyListLockSendsNothing(): void
    {
        self::live(self::OLD);
        \HeliusWorld::$lockResults = [false];

        self::assertSame('busy', M::reregisterSharedWebhook(self::CB)['outcome']);
        self::assertSame([], \HeliusWorld::$putBodies);
    }

    public function testWithoutAStoredIdItPointsAtProvision(): void
    {
        unset(\HeliusWorld::$options[M::OPTION_WEBHOOK_ID]);

        self::assertSame('not_provisioned', M::reregisterSharedWebhook(self::CB)['outcome']);
    }
}
