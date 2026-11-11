<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\HeliusSubscriptionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-64 / F-70: the shared Helius webhook address list.
 *
 * Every change is GET → edit → PUT of the WHOLE list, so what this pins is
 * that nothing ever PUTs a list built from something it could not read:
 *
 *   - an unreadable webhook list (missing, wrong-typed, non-string entry)
 *     used to read as [] — and addAddress() then PUT just the new address,
 *     wiping every other subscriber;
 *   - a failed wallet_links read used to read as [] — and the twice-daily
 *     reconcile then PUT [], wiping every subscription;
 *   - the three writers ran unlocked, so the last PUT won;
 *   - removeAddress() took an address off even while another link held it;
 *   - (F-70) resync marked the rows it had just listed as NOT managed.
 *
 * A deferred change returns false / null, which the async jobs in
 * bcc-trust.php already treat as "retry later" (bounded backoff, with the
 * reconcile as backstop) — so a busy lock defers a change, never drops it.
 */
#[CoversClass(HeliusSubscriptionManager::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HeliusSubscriptionManagerTest extends TestCase
{
    private const A = 'AaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaA';
    private const B = 'BbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbB';
    private const C = 'CcccccccccccccccccccccccccccccccccccccccccccC';
    private const NEW = 'NnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnN';

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        if (!defined('BCC_HELIUS_API_KEY')) {
            define('BCC_HELIUS_API_KEY', 'test-key');
        }
        require_once __DIR__ . '/../Stubs/helius-subscription-stubs.php';
        \HeliusWorld::reset();
    }

    private static function assertLockBalanced(): void
    {
        self::assertSame(0, \HeliusWorld::$lockHeld, 'every acquire is matched by a release');
    }

    // ── add ─────────────────────────────────────────────────────────────

    public function testAddPutsTheWholeListPlusTheNewAddress(): void
    {
        \HeliusWorld::remoteList([self::A, self::B]);

        self::assertTrue(HeliusSubscriptionManager::addAddress(10, self::NEW));
        self::assertSame([[self::A, self::B, self::NEW]], \HeliusWorld::$puts);
        self::assertSame([[10, true]], \HeliusWorld::$marked);
        // L2: the lock name carries this site's table prefix, so staging and
        // production sharing one MySQL server do not serialise each other.
        self::assertSame(['acquire:wpsa6_bcc_helius_address_list:10:ok', 'release:wpsa6_bcc_helius_address_list'], \HeliusWorld::$lockLog);
        self::assertLockBalanced();
    }

    public function testAddOfAnAddressAlreadyListedMakesNoPut(): void
    {
        \HeliusWorld::remoteList([self::A, self::NEW]);

        self::assertTrue(HeliusSubscriptionManager::addAddress(10, self::NEW));
        self::assertSame([], \HeliusWorld::$puts);
        self::assertSame([[10, true]], \HeliusWorld::$marked);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unreadableWebhooks(): array
    {
        return [
            'no accountAddresses key'       => [['webhookID' => 'wh-1']],
            'accountAddresses is a string'  => [['webhookID' => 'wh-1', 'accountAddresses' => 'none']],
            'accountAddresses is null'      => [['webhookID' => 'wh-1', 'accountAddresses' => null]],
            'accountAddresses is a map'     => [['webhookID' => 'wh-1', 'accountAddresses' => ['x' => self::A]]],
            'a non-string entry'            => [['webhookID' => 'wh-1', 'accountAddresses' => [self::A, 7, self::B]]],
            'an empty-string entry'         => [['webhookID' => 'wh-1', 'accountAddresses' => [self::A, '']]],
            'an error-shaped 200'           => [['error' => 'internal']],
        ];
    }

    /** @param array<string, mixed> $detail */
    #[DataProvider('unreadableWebhooks')]
    public function testAddNeverPutsOverAListItCouldNotRead(array $detail): void
    {
        // The wipe: this used to read as [] and PUT [NEW] — every other
        // subscriber gone.
        \HeliusWorld::remoteDetail($detail);

        self::assertFalse(HeliusSubscriptionManager::addAddress(10, self::NEW));
        self::assertSame([], \HeliusWorld::$puts);
        self::assertSame([], \HeliusWorld::$marked);
        self::assertLockBalanced();
    }

    /** @param array<string, mixed> $detail */
    #[DataProvider('unreadableWebhooks')]
    public function testRemoveNeverPutsOverAListItCouldNotRead(array $detail): void
    {
        \HeliusWorld::remoteDetail($detail);

        self::assertFalse(HeliusSubscriptionManager::removeAddress(10, self::A));
        self::assertSame([], \HeliusWorld::$puts);
        self::assertLockBalanced();
    }

    // ── remove ──────────────────────────────────────────────────────────

    public function testRemovePutsTheListWithoutTheAddress(): void
    {
        \HeliusWorld::remoteList([self::A, self::B, self::C]);

        self::assertTrue(HeliusSubscriptionManager::removeAddress(10, self::B));
        self::assertSame([[self::A, self::C]], \HeliusWorld::$puts);
        self::assertSame([[10, false]], \HeliusWorld::$marked);
        self::assertLockBalanced();
    }

    public function testRemoveKeepsAnAddressAnotherLinkStillHolds(): void
    {
        \HeliusWorld::$otherLinks = 1;
        \HeliusWorld::remoteList([self::A, self::B]);

        self::assertTrue(HeliusSubscriptionManager::removeAddress(10, self::B));
        self::assertSame([], \HeliusWorld::$puts, 'the other user keeps their events');
        self::assertSame(0, \HeliusWorld::$getCalls);
        self::assertSame([[10, false]], \HeliusWorld::$marked, 'this link is still marked unmanaged');
        self::assertLockBalanced();
    }

    public function testRemoveDefersWhenItCannotTellWhetherAnotherLinkHoldsTheAddress(): void
    {
        \HeliusWorld::$countReadFails = true;
        \HeliusWorld::remoteList([self::A, self::B]);

        self::assertFalse(HeliusSubscriptionManager::removeAddress(0, self::B), 'false = the job retries');
        self::assertSame([], \HeliusWorld::$puts);
        self::assertLockBalanced();
    }

    // ── the lock ────────────────────────────────────────────────────────

    public function testABusyLockDefersAnAddRatherThanDroppingIt(): void
    {
        // false is the async job's retry signal (bcc_helius_subscribe_wallet
        // re-enqueues with backoff); the list is not touched while busy.
        \HeliusWorld::$lockResults = [false];
        \HeliusWorld::remoteList([self::A]);

        self::assertFalse(HeliusSubscriptionManager::addAddress(10, self::NEW));
        self::assertSame(0, \HeliusWorld::$getCalls);
        self::assertSame([], \HeliusWorld::$puts);
        self::assertSame([], \HeliusWorld::$marked);
        self::assertLockBalanced();

        // …and the retry, once the lock is free, lands the change.
        self::assertTrue(HeliusSubscriptionManager::addAddress(10, self::NEW));
        self::assertSame([[self::A, self::NEW]], \HeliusWorld::$puts);
        self::assertLockBalanced();
    }

    public function testABusyLockDefersARemoveRatherThanDroppingIt(): void
    {
        \HeliusWorld::$lockResults = [false];
        \HeliusWorld::remoteList([self::A, self::B]);

        self::assertFalse(HeliusSubscriptionManager::removeAddress(0, self::B));
        self::assertSame([], \HeliusWorld::$puts);

        self::assertTrue(HeliusSubscriptionManager::removeAddress(0, self::B));
        self::assertSame([[self::A]], \HeliusWorld::$puts);
        self::assertLockBalanced();
    }

    public function testABusyLockDefersAResync(): void
    {
        \HeliusWorld::$lockResults = [false];

        self::assertNull(HeliusSubscriptionManager::resyncFromWalletLinks());
        self::assertSame(0, \HeliusWorld::$getCalls);
        self::assertSame([], \HeliusWorld::$puts);
    }

    public function testTheLockIsReleasedWhenTheCriticalSectionThrows(): void
    {
        \HeliusWorld::$throwInsideLock = true;

        try {
            HeliusSubscriptionManager::addAddress(10, self::NEW);
            self::fail('expected the throw to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('boom inside the critical section', $e->getMessage());
        }
        self::assertLockBalanced();
    }

    public function testTheLockIsReleasedWhenThePutFails(): void
    {
        \HeliusWorld::remoteList([self::A]);
        \HeliusWorld::$putCodes = [500];

        self::assertFalse(HeliusSubscriptionManager::addAddress(10, self::NEW));
        self::assertSame([], \HeliusWorld::$marked);
        self::assertLockBalanced();
    }

    // ── resync ──────────────────────────────────────────────────────────

    public function testAResyncWhoseWalletReadFailsNeverPuts(): void
    {
        // The twice-daily wipe: a failed read used to be [] and PUT [].
        \HeliusWorld::$listReadFails = true;
        \HeliusWorld::remoteList([self::A, self::B]);

        self::assertNull(HeliusSubscriptionManager::resyncFromWalletLinks());
        self::assertSame([], \HeliusWorld::$puts);
        self::assertSame([], \HeliusWorld::$marked);
        self::assertLockBalanced();
    }

    public function testAResyncOverAnUnreadableRemoteListNeverPuts(): void
    {
        \HeliusWorld::$links[1] = [['id' => 1, 'wallet_address' => self::A]];
        \HeliusWorld::remoteDetail(['webhookID' => 'wh-1']);

        self::assertNull(HeliusSubscriptionManager::resyncFromWalletLinks());
        self::assertSame([], \HeliusWorld::$puts);
    }

    public function testShrinkingANonEmptyRemoteListToEmptyIsRefusedWithoutTheOverride(): void
    {
        // F-64 review shrink floor. A local set that reads as empty while the
        // remote holds addresses is far more likely a bad read than a world
        // where every Solana wallet unlinked at once — and the PUT would
        // unsubscribe everyone. Refused, flags untouched, lock released.
        \HeliusWorld::remoteList([self::A, self::B]);

        $stats = HeliusSubscriptionManager::resyncFromWalletLinks();

        self::assertSame([], \HeliusWorld::$puts);
        self::assertSame(['remote_count' => 2, 'local_count' => 0, 'applied' => false, 'refused' => 'shrink'], $stats);
        self::assertSame([], \HeliusWorld::$marked);
        self::assertLockBalanced();
    }

    public function testTheOperatorOverrideLetsAGenuineMassUnlinkConverge(): void
    {
        \HeliusWorld::remoteList([self::A, self::B]);

        $stats = HeliusSubscriptionManager::resyncFromWalletLinks(true);

        self::assertSame([[]], \HeliusWorld::$puts);
        self::assertSame(['remote_count' => 2, 'local_count' => 0, 'applied' => true], $stats);
        self::assertLockBalanced();
    }

    /** @return list<string> */
    private static function addresses(string $prefix, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $prefix . str_pad((string) $i, 40, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    /** @param list<string> $addresses */
    private static function localUnmanaged(array $addresses): void
    {
        $rows = [];
        foreach ($addresses as $i => $a) {
            $rows[] = ['id' => 1000 + $i, 'wallet_address' => $a];
        }
        \HeliusWorld::$links[0] = $rows;
    }

    public function testShrinkingALargeListToUnderHalfIsRefused(): void
    {
        $remote = self::addresses('R', 20);
        \HeliusWorld::remoteList($remote);
        self::localUnmanaged(array_slice($remote, 0, 9));    // 9 of 20: under half

        $stats = HeliusSubscriptionManager::resyncFromWalletLinks();

        self::assertSame('shrink', $stats['refused'] ?? null);
        self::assertSame([], \HeliusWorld::$puts);
    }

    public function testShrinkingALargeListToExactlyHalfIsApplied(): void
    {
        $remote = self::addresses('R', 20);
        \HeliusWorld::remoteList($remote);
        self::localUnmanaged(array_slice($remote, 0, 10));   // 10 of 20: not under half

        $stats = HeliusSubscriptionManager::resyncFromWalletLinks();

        self::assertTrue($stats['applied'] ?? false);
        self::assertArrayNotHasKey('refused', (array) $stats);
        self::assertCount(1, \HeliusWorld::$puts);
    }

    public function testASmallListIsExemptFromTheRatio(): void
    {
        // 3 → 1 is two-thirds gone, but below the 10-address minimum an
        // ordinary unlink must still converge without an operator.
        $remote = self::addresses('R', 3);
        \HeliusWorld::remoteList($remote);
        self::localUnmanaged([$remote[0]]);

        $stats = HeliusSubscriptionManager::resyncFromWalletLinks();

        self::assertTrue($stats['applied'] ?? false);
        self::assertSame([[$remote[0]]], \HeliusWorld::$puts);
    }

    public function testTheListPutGoesThroughTheHardenedClientNotARawRequest(): void
    {
        // SafeHttpClient's SSRF hardening is applied by ApiRetry::post (via
        // SafeHttpClient::prepareArgs). The PUT used to bypass it with a bare
        // wp_remote_request().
        \HeliusWorld::remoteList([self::A]);

        self::assertTrue(HeliusSubscriptionManager::addAddress(10, self::NEW));

        self::assertSame(0, \HeliusWorld::$rawRequests, 'no bare wp_remote_request');
        self::assertCount(1, \HeliusWorld::$postArgs);
        self::assertSame('PUT', \HeliusWorld::$postArgs[0]['method'] ?? null);
        self::assertSame([[self::A, self::NEW]], \HeliusWorld::$puts);
    }

    public function testResyncMarksEveryListedRowManaged(): void
    {
        // F-70: the rows it just PUT onto the list are managed — including
        // the ones that were marked unmanaged — and an already-managed row is
        // not rewritten. This used to come out exactly inverted.
        \HeliusWorld::$links[1] = [['id' => 1, 'wallet_address' => self::A]];
        \HeliusWorld::$links[0] = [['id' => 2, 'wallet_address' => self::B], ['id' => 3, 'wallet_address' => self::C]];
        \HeliusWorld::remoteList([self::A]);

        $stats = HeliusSubscriptionManager::resyncFromWalletLinks();

        self::assertTrue($stats['applied'] ?? false);
        self::assertCount(1, \HeliusWorld::$puts);
        $put = \HeliusWorld::$puts[0];
        sort($put);
        self::assertSame([self::A, self::B, self::C], $put);
        self::assertSame([[2, true], [3, true]], \HeliusWorld::$marked);
        self::assertLockBalanced();
    }

    public function testResyncWithNothingToChangeStillRepairsAWrongFlag(): void
    {
        // The list already matches, so no PUT — but a row on the list whose
        // flag says unmanaged is put right.
        \HeliusWorld::$links[1] = [['id' => 1, 'wallet_address' => self::A]];
        \HeliusWorld::$links[0] = [['id' => 2, 'wallet_address' => self::B]];
        \HeliusWorld::remoteList([self::B, self::A]);

        $stats = HeliusSubscriptionManager::resyncFromWalletLinks();

        self::assertFalse($stats['applied'] ?? true);
        self::assertSame([], \HeliusWorld::$puts);
        self::assertSame([[2, true]], \HeliusWorld::$marked);
    }

    public function testAFailedResyncPutMarksNothing(): void
    {
        \HeliusWorld::$links[0] = [['id' => 2, 'wallet_address' => self::B]];
        \HeliusWorld::remoteList([self::A]);
        \HeliusWorld::$putCodes = [500];

        self::assertNull(HeliusSubscriptionManager::resyncFromWalletLinks());
        self::assertSame([], \HeliusWorld::$marked);
        self::assertLockBalanced();
    }
}
