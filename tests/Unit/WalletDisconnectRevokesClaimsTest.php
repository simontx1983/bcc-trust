<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\BonusService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-521: BonusService::handleWalletDisconnect() revokes the wallet's
 * verified claims through the audited revoke-and-release path BEFORE it
 * purges the rows and signals, and purges nothing when that revoke fails.
 *
 * The real BonusService runs; its collaborators are the recording fakes in
 * tests/Stubs/wallet-disconnect-revoke-stubs.php, loaded in the child
 * process before the real classes can load.
 */
#[CoversClass(BonusService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WalletDisconnectRevokesClaimsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/wallet-disconnect-revoke-stubs.php';
        $GLOBALS['__f521'] = ['calls' => [], 'revoke_throws' => false, 'chain_id' => 7];
    }

    public function testTheClaimsAreRevokedBeforeTheRowsArePurged(): void
    {
        BonusService::handleWalletDisconnect(5, 'cosmoshub', 'cosmos1abc');

        self::assertSame(
            [
                ['revokeAllForUser', 5, 'wallet_unlinked', 'cosmos1abc', 7],
                ['purgeDisconnectedWallet', 5, 'cosmos1abc', 7, 'cosmoshub'],
            ],
            $GLOBALS['__f521']['calls']
        );
    }

    public function testAnUnresolvedChainRevokesByWalletAlone(): void
    {
        $GLOBALS['__f521']['chain_id'] = null;

        BonusService::handleWalletDisconnect(5, 'unknown-chain', 'cosmos1abc');

        self::assertSame(['revokeAllForUser', 5, 'wallet_unlinked', 'cosmos1abc', null], $GLOBALS['__f521']['calls'][0]);
    }

    public function testAFailedRevokePurgesNothingAndThrows(): void
    {
        $GLOBALS['__f521']['revoke_throws'] = true;

        try {
            BonusService::handleWalletDisconnect(5, 'cosmoshub', 'cosmos1abc');
            self::fail('a failed revoke must throw');
        } catch (\RuntimeException $e) {
            self::assertSame('revoke failed', $e->getMessage());
        }

        self::assertSame([['revokeAllForUser', 5, 'wallet_unlinked', 'cosmos1abc', 7]], $GLOBALS['__f521']['calls'], 'no purge after a failed revoke');
    }
}
