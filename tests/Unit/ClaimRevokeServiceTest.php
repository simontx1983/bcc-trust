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
        \ClaimRevokeWorld::reset();
    }

    private static function revoked(): void
    {
        \ClaimRevokeWorld::$result = [
            'revoked' => true, 'user_id' => 5, 'entity_type' => 'validator',
            'entity_id' => 12, 'claim_role' => 'operator', 'mirror_page_ids' => [77],
            'bound_page_ids' => [77, 88],
        ];
    }

    public function testEveryBoundPageReadModelIsMarkedDirty(): void
    {
        // `bcc_trust_verification_changed` resyncs only the claimant's OWN
        // page; the claimed page's has_verified_claim lives on the bound page.
        self::revoked();

        ClaimService::revokeClaim(41, 'wallet_link_gone');

        self::assertSame([77, 88], \ClaimRevokeWorld::$dirty);
    }

    /** @return array<string, array{string}> */
    public static function postCommitSteps(): array
    {
        return [
            'audit'                => ['audit'],
            'bonus'                => ['bonus'],
            'read model'           => ['read_model'],
            'verification_changed' => ['bcc_trust_verification_changed'],
            'claim_revoked event'  => ['bcc_onchain_claim_revoked'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('postCommitSteps')]
    public function testAFailingFollowUpStepNeitherThrowsNorStopsTheOthers(string $failing): void
    {
        // #561 review: a throw after the commit escaped, and the next sweep
        // saw the claim as already revoked — the lost step never retried.
        self::revoked();
        \ClaimRevokeWorld::$fail[$failing] = true;

        self::assertTrue(ClaimService::revokeClaim(41, 'wallet_link_gone'), 'a committed revoke reports success');

        $ran = [
            'audit'                          => \ClaimRevokeWorld::$audits !== [],
            'bonus'                          => \ClaimRevokeWorld::$recomputes !== [],
            'read_model'                     => \ClaimRevokeWorld::$dirty !== [],
            'bcc_trust_verification_changed' => in_array('bcc_trust_verification_changed', array_column(\ClaimRevokeWorld::$actions, 0), true),
            'bcc_onchain_claim_revoked'      => in_array('bcc_onchain_claim_revoked', array_column(\ClaimRevokeWorld::$actions, 0), true),
        ];
        foreach ($ran as $step => $didRun) {
            self::assertSame($step !== $failing, $didRun, $step === $failing ? "{$step} failed" : "{$step} still ran");
        }

        self::assertNotSame([], \ClaimRevokeWorld::$errors, 'the failure is logged');
        self::assertSame('[bcc-trust] claim_revoke_followup_failed', \ClaimRevokeWorld::$errors[0][0]);
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
