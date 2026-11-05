<?php
/**
 * A SERVER problem must never be reported as a WALLET problem.
 *
 * `mapClaimFailure()` special-cased only `needs_wallet` and
 * `already_claimed`; everything else fell through to 403 "Your connected
 * wallet does not match the operator for this entity." So an advisory-lock
 * timeout (two quick clicks on Claim), a failed write, or a transient DB
 * error told a legitimate operator their wallet was wrong — and the
 * plausible reaction to that is to unlink a correct wallet or give up.
 *
 * These cases pin the mapping, in both directions: the transient and
 * not-found cases must NOT be 403, and a genuine mismatch must STILL be 403.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(\BCC\Trust\Core\REST\PagesEndpoint::class)]
final class ClaimFailureMappingTest extends TestCase
{
    private const SRC = 'app/Domain/Core/REST/PagesEndpoint.php';

    private static function source(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The mapping table, asserted against the real method body.
     *
     * `mapClaimFailure()` is private static and builds a WP_REST_Response,
     * so a reflective invoke would need the whole REST stack. The method is
     * a pure match on one array key, so the branch set is what matters: each
     * sentinel must appear with its own status, and the four must be distinct.
     */
    public function testEachFailureKindMapsToItsOwnStatus(): void
    {
        $src = self::source(self::SRC);
        $at  = strpos($src, 'private static function mapClaimFailure(');
        self::assertNotFalse($at, 'mapClaimFailure is gone');

        $body = substr($src, $at, 2600);

        // Pre-existing branches, unchanged.
        self::assertStringContainsString("'needs_wallet'", $body);
        self::assertStringContainsString('412', $body);
        self::assertStringContainsString("=== 'already_claimed'", $body);
        self::assertStringContainsString('409', $body);

        // The branches this fix adds.
        self::assertStringContainsString("=== 'transient'", $body, 'a transient failure has no branch');
        self::assertStringContainsString('503', $body, 'a transient failure must be retryable');
        self::assertStringContainsString("=== 'entity_not_found'", $body, 'a missing entity has no branch');
        self::assertStringContainsString('404', $body);

        // And the mismatch default survives — this fix must not swallow it.
        self::assertStringContainsString('403', $body);
        self::assertStringContainsString('does not match the operator', $body);
    }

    /**
     * ⚠ The ordering that makes it work: both new branches must sit BEFORE
     * the 403 default, or they are unreachable and the bug is unchanged.
     */
    public function testTheNewBranchesPrecedeTheMismatchDefault(): void
    {
        $src = self::source(self::SRC);
        $at  = strpos($src, 'private static function mapClaimFailure(');
        self::assertNotFalse($at);
        $body = substr($src, $at, 2600);

        $transient = strpos($body, "=== 'transient'");
        $notFound  = strpos($body, "=== 'entity_not_found'");
        $default   = strpos($body, 'does not match the operator');

        self::assertNotFalse($transient);
        self::assertNotFalse($notFound);
        self::assertNotFalse($default);
        self::assertLessThan($default, $transient, 'the transient branch is unreachable');
        self::assertLessThan($default, $notFound, 'the not-found branch is unreachable');
    }

    /**
     * The sentinels are only useful if the producers actually set them.
     * Each transient failure site must carry `'error' => 'transient'`.
     */
    public function testTheProducersTagTheirTransientFailures(): void
    {
        $repo    = self::source('app/Domain/Onchain/Repositories/ClaimRepository.php');
        $service = self::source('app/Domain/Onchain/Services/ClaimService.php');

        // Lock timeout, write failure and caught DB error — all server-side.
        self::assertStringContainsString("'error' => 'transient', 'message' => 'Could not acquire claim lock", $repo);
        self::assertStringContainsString("'error' => 'transient', 'message' => 'Failed to save claim.'", $repo);
        self::assertStringContainsString("'error'   => 'transient',", $repo);
        self::assertStringContainsString("'error' => 'transient', 'message' => 'Failed to save claim.'", $service);

        // A missing entity is NOT transient — it gets its own sentinel.
        self::assertStringContainsString("'error' => 'entity_not_found'", $service);
        self::assertStringNotContainsString("'error' => 'transient', 'message' => 'Entity not found.'", $service);
    }

    /**
     * ⚠ Anti-vacuity: prove the slice this test reads is really the method
     * body and not an empty string or the whole file, either of which would
     * make every assertion above pass or fail for the wrong reason.
     */
    public function testTheSliceUnderTestIsTheMethodBody(): void
    {
        $src = self::source(self::SRC);
        $at  = strpos($src, 'private static function mapClaimFailure(');
        self::assertNotFalse($at);
        $body = substr($src, $at, 2600);

        self::assertNotSame('', trim($body));
        self::assertLessThan(strlen($src), strlen($body), 'the slice is the whole file');
        self::assertStringContainsString('ApiResponse::error', $body, 'the slice is not the mapper');
    }
}
