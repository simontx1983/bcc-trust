<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\ClaimService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-14 (PR A): ClaimService::revokeClaim() undoes what a verified claim
 * granted — bonus recompute, page read-model resync, a revoke event, an audit
 * row — and does none of it when there was nothing to revoke or the revoke
 * failed. The repository side is ClaimRevokeIntegrationTest.
 */
#[CoversClass(ClaimService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ClaimRevokeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/claim-revoke-service-stubs.php';
        \ClaimRevokeWorld::$result      = null;
        \ClaimRevokeWorld::$throw       = null;
        \ClaimRevokeWorld::$revokeCalls = [];
        \ClaimRevokeWorld::$ownerPage   = 77;
        \ClaimRevokeWorld::$recomputes  = [];
        \ClaimRevokeWorld::$actions     = [];
        \ClaimRevokeWorld::$audits      = [];
    }

    private static function revoked(): void
    {
        \ClaimRevokeWorld::$result = [
            'revoked' => true, 'user_id' => 5, 'entity_type' => 'validator',
            'entity_id' => 12, 'claim_role' => 'operator', 'mirror_page_ids' => [77],
        ];
    }

    public function testARevokeUndoesWhatTheClaimGranted(): void
    {
        self::revoked();

        self::assertTrue(ClaimService::revokeClaim(41, 'wallet_link_gone'));

        self::assertSame([41], \ClaimRevokeWorld::$revokeCalls);
        self::assertSame([[77, 5]], \ClaimRevokeWorld::$recomputes, 'the bonus is recomputed on the claimant\'s page');
        self::assertSame([
            ['bcc_trust_verification_changed', 5],
            ['bcc_onchain_claim_revoked', 41, 5, 'validator', 12, 'operator', 'wallet_link_gone'],
        ], \ClaimRevokeWorld::$actions);
        self::assertCount(1, \ClaimRevokeWorld::$audits);
        [$action, $target, $meta, $type, $user] = \ClaimRevokeWorld::$audits[0];
        self::assertSame(['claim_revoked', 41, 'claim', 5], [$action, $target, $type, $user]);
        self::assertSame('wallet_link_gone', $meta['reason']);
        self::assertSame([77], $meta['mirror_page_ids']);
    }

    public function testAClaimantWithNoOwnPageSkipsOnlyTheRecompute(): void
    {
        self::revoked();
        \ClaimRevokeWorld::$ownerPage = null;

        self::assertTrue(ClaimService::revokeClaim(41, 'no_longer_holder'));

        self::assertSame([], \ClaimRevokeWorld::$recomputes);
        self::assertCount(2, \ClaimRevokeWorld::$actions);
        self::assertCount(1, \ClaimRevokeWorld::$audits);
    }

    public function testNothingToRevokeHasNoSideEffects(): void
    {
        self::assertFalse(ClaimService::revokeClaim(41, 'wallet_link_gone'));

        self::assertSame([], \ClaimRevokeWorld::$recomputes);
        self::assertSame([], \ClaimRevokeWorld::$actions);
        self::assertSame([], \ClaimRevokeWorld::$audits);
    }

    public function testAFailedRevokePropagatesWithNoSideEffects(): void
    {
        \ClaimRevokeWorld::$throw = new \RuntimeException('COMMIT failed');

        try {
            ClaimService::revokeClaim(41, 'wallet_link_gone');
            self::fail('a failed revoke must throw — the sweep reads that as UNKNOWN');
        } catch (\RuntimeException $e) {
            self::assertSame('COMMIT failed', $e->getMessage());
        }

        self::assertSame([], \ClaimRevokeWorld::$recomputes);
        self::assertSame([], \ClaimRevokeWorld::$actions);
        self::assertSame([], \ClaimRevokeWorld::$audits);
    }

    public function testAnUnknownReasonIsRefusedBeforeAnythingRuns(): void
    {
        self::revoked();

        try {
            ClaimService::revokeClaim(41, 'because');
            self::fail('an unknown reason must be refused');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], \ClaimRevokeWorld::$revokeCalls);
    }
}
