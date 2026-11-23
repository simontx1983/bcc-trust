<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\ClaimService;
use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-521: a wallet unlink and an account deletion revoke the user's verified
 * claims through ClaimService::revokeClaim() — audited, the page released,
 * the bonus recomputed, the revoke event fired — before any row is purged.
 * The collaborators are the recording doubles of ClaimRevokeServiceTest.
 */
#[CoversClass(ClaimService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ClaimRevokeAllTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/claim-revoke-service-stubs.php';
        \ClaimRevokeWorld::reset();
        \ClaimRevokeWorld::$result = [
            'revoked' => true, 'user_id' => 5, 'entity_type' => 'validator',
            'entity_id' => 12, 'claim_role' => 'operator', 'mirror_page_ids' => [77],
            'bound_page_ids' => [77],
        ];
    }

    /** @return list<string> the reason on each claim_revoked audit row */
    private static function auditedReasons(): array
    {
        $out = [];
        foreach (\ClaimRevokeWorld::$audits as $audit) {
            $meta  = is_array($audit[2] ?? null) ? $audit[2] : [];
            $out[] = (string) ($meta['reason'] ?? '');
        }

        return $out;
    }

    public function testEveryVerifiedClaimIsRevokedThroughTheAuditedPath(): void
    {
        \ClaimRevokeWorld::$userClaimIds = [41, 42];

        self::assertSame(2, ClaimService::revokeAllForUser(5, 'wallet_unlinked', '0xabc', 7));

        self::assertSame([41, 42], \ClaimRevokeWorld::$revokeCalls);
        self::assertSame(['wallet_unlinked', 'wallet_unlinked'], self::auditedReasons(), 'each revoke is audited with the reason');
        self::assertSame([[77, 5], [77, 5]], \ClaimRevokeWorld::$released, 'each frees the page it granted');
        self::assertCount(2, array_filter(\ClaimRevokeWorld::$actions, static fn (array $a): bool => $a[0] === 'bcc_onchain_claim_revoked'));
    }

    public function testTheWalletAndChainScopeTheRead(): void
    {
        ClaimService::revokeAllForUser(5, 'wallet_unlinked', '0xabc', 7);
        ClaimService::revokeAllForUser(5, 'account_deleted');

        self::assertSame([[5, '0xabc', 7], [5, null, null]], \ClaimRevokeWorld::$idReads);
    }

    public function testAClaimNoLongerVerifiedIsNotCounted(): void
    {
        \ClaimRevokeWorld::$userClaimIds = [41];
        \ClaimRevokeWorld::$result       = null; // revoke() answers "nothing to revoke"

        self::assertSame(0, ClaimService::revokeAllForUser(5, 'account_deleted'));
        self::assertSame([], \ClaimRevokeWorld::$audits);
    }

    public function testAnUnknownReasonIsRefusedBeforeAnythingIsRead(): void
    {
        try {
            ClaimService::revokeAllForUser(5, 'because');
            self::fail('an unknown reason must be refused');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], \ClaimRevokeWorld::$idReads);
        self::assertSame([], \ClaimRevokeWorld::$revokeCalls);
    }

    public function testAFailedReadThrowsAndRevokesNothing(): void
    {
        \ClaimRevokeWorld::$idsThrow = new \RuntimeException('read failed');

        try {
            ClaimService::revokeAllForUser(5, 'wallet_unlinked', '0xabc', 7);
            self::fail('a failed read must throw, so the caller purges nothing');
        } catch (\RuntimeException $e) {
            self::assertSame('read failed', $e->getMessage());
        }

        self::assertSame([], \ClaimRevokeWorld::$revokeCalls);
    }

    public function testAFailedRevokeThrowsAndTheEarlierRevokesStand(): void
    {
        \ClaimRevokeWorld::$userClaimIds = [41, 42, 43];
        \ClaimRevokeWorld::$throw        = new \RuntimeException('COMMIT failed');
        \ClaimRevokeWorld::$throwOnCall  = 2;

        try {
            ClaimService::revokeAllForUser(5, 'wallet_unlinked', '0xabc', 7);
            self::fail('a failed revoke must throw');
        } catch (\RuntimeException $e) {
            self::assertSame('COMMIT failed', $e->getMessage());
        }

        self::assertSame([41, 42], \ClaimRevokeWorld::$revokeCalls, 'the third is never attempted');
        self::assertSame(['wallet_unlinked'], self::auditedReasons(), 'the first revoke is audited and stands');
    }

    // ── account deletion ───────────────────────────────────────────────

    public function testAnAccountDeletionRevokesThenPurges(): void
    {
        \ClaimRevokeWorld::$userClaimIds = [41];

        ClaimService::purgeForDeletedUser(5);

        self::assertSame(['account_deleted'], self::auditedReasons());
        self::assertSame([5], \ClaimRevokeWorld::$deletedFor);
        $order = \ClaimRevokeWorld::$order;
        self::assertSame('delete_for_user', end($order), 'the rows are purged last, after the revoke');
    }

    /**
     * Like the rest of the account-deletion cleanup, a failed revoke must not
     * block the WordPress deletion: it is logged and the rows are still purged.
     */
    public function testAFailedRevokeIsLoggedAndTheRowsAreStillPurged(): void
    {
        \ClaimRevokeWorld::$idsThrow = new \RuntimeException('read failed');

        ClaimService::purgeForDeletedUser(5);

        self::assertSame([5], \ClaimRevokeWorld::$deletedFor);
        self::assertNotSame([], \ClaimRevokeWorld::$errors, 'the failure is logged');
    }

    /**
     * Security L2: on the deletion path one failed revoke must not leave the
     * user's other pages unreleased. Every other claim is still revoked.
     */
    public function testOnDeletionOneFailedRevokeDoesNotStopTheOthers(): void
    {
        \ClaimRevokeWorld::$userClaimIds = [41, 42, 43];
        \ClaimRevokeWorld::$throw        = new \RuntimeException('COMMIT failed');
        \ClaimRevokeWorld::$throwOnCall  = 2;

        ClaimService::purgeForDeletedUser(5);

        self::assertSame([41, 42, 43], \ClaimRevokeWorld::$revokeCalls, 'the third is still attempted');
        self::assertSame(['account_deleted', 'account_deleted'], self::auditedReasons(), 'the first and third are revoked');
        self::assertCount(2, \ClaimRevokeWorld::$released, 'their pages are released');
        self::assertSame([5], \ClaimRevokeWorld::$deletedFor, 'the rows are still purged');
        $logged = array_column(array_column(\ClaimRevokeWorld::$errors, 1), 'claim_id');
        self::assertSame([42], $logged, 'the failed claim is logged by id');
    }

    public function testNoUserIsANoOp(): void
    {
        ClaimService::purgeForDeletedUser(0);

        self::assertSame([], \ClaimRevokeWorld::$idReads);
        self::assertSame([], \ClaimRevokeWorld::$deletedFor);
    }

    /**
     * Wiring (source text; the behaviour is proven above): the account
     * deletion cleanup purges claims ONLY through purgeForDeletedUser(). No
     * bare `ClaimRepository::deleteForUser(` exists anywhere in the plugin
     * outside ClaimService, so no hook can purge claims without revoking
     * them first, whatever the hook order.
     */
    public function testClaimsArePurgedOnlyThroughTheRevokeFirstPath(): void
    {
        $body = GateOrder::body(GateOrder::code('app/Domain/Core/Services/UserLifecycleService.php'), 'onUserDelete');
        self::assertSame(1, substr_count($body, 'ClaimService::purgeForDeletedUser($userId)'));

        $root  = dirname(__DIR__, 2);
        $files = ['bcc-trust.php'];
        foreach (['app', 'includes'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }

        $callers = [];
        foreach ($files as $relative) {
            if (str_contains(GateOrder::code($relative), 'ClaimRepository::deleteForUser(')) {
                $callers[] = $relative;
            }
        }
        self::assertContains('bcc-trust.php', $files, 'the scan covers the plugin bootstrap');
        self::assertGreaterThan(100, count($files), 'the scan covers the plugin tree (it is not scanning nothing)');
        self::assertSame(['app/Domain/Onchain/Services/ClaimService.php'], $callers, 'no bare claim purge outside ClaimService');
    }
}
