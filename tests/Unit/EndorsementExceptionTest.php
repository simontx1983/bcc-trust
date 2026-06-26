<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Exceptions\EndorsementException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the §1.4 API-contract mapping carried by EndorsementException's named
 * constructors. This is the regression guard that makes the typed-exception
 * refactor worth doing: if a future edit drifts a (errorCode, httpStatus) pair
 * — or changes the soft-gate data['unlock_hint'] companion — this test fails
 * BEFORE the contract break can ship. The pairs below are docs/api-contract-v1.md
 * §1.4; do not "fix" the test to match changed code without updating the contract.
 */
final class EndorsementExceptionTest extends TestCase
{
    public function testAuthRequiredMapsToUnauthorized401(): void
    {
        $e = EndorsementException::authRequired();
        self::assertSame('bcc_unauthorized', $e->errorCode());
        self::assertSame(401, $e->httpStatus());
        self::assertSame('Authentication required', $e->getMessage());
    }

    public function testInvalidPageMapsToInvalidRequest400(): void
    {
        $e = EndorsementException::invalidPage();
        self::assertSame('bcc_invalid_request', $e->errorCode());
        self::assertSame(400, $e->httpStatus());
    }

    public function testSelfPageMapsToEndorseSelf403(): void
    {
        $e = EndorsementException::selfPage();
        self::assertSame('bcc_endorse_self', $e->errorCode());
        self::assertSame(403, $e->httpStatus());
    }

    public function testAlreadyEndorsedMapsToConflict409(): void
    {
        $e = EndorsementException::alreadyEndorsed();
        self::assertSame('bcc_conflict', $e->errorCode());
        self::assertSame(409, $e->httpStatus());
    }

    public function testFraudLockedMapsToFraudLocked403(): void
    {
        $e = EndorsementException::fraudLocked('flagged for unusual activity');
        self::assertSame('bcc_fraud_locked', $e->errorCode());
        self::assertSame(403, $e->httpStatus());
        self::assertSame('flagged for unusual activity', $e->getMessage());
    }

    public function testBusyMapsToRateLimited429(): void
    {
        $e = EndorsementException::busy();
        self::assertSame('bcc_rate_limited', $e->errorCode());
        self::assertSame(429, $e->httpStatus());
    }

    public function testNotFoundMapsToNotFound404(): void
    {
        $e = EndorsementException::notFound();
        self::assertSame('bcc_not_found', $e->errorCode());
        self::assertSame(404, $e->httpStatus());
    }

    public function testDailyLimitMapsToRateLimited429(): void
    {
        $e = EndorsementException::dailyLimit('daily endorsement limit reached');
        self::assertSame('bcc_rate_limited', $e->errorCode());
        self::assertSame(429, $e->httpStatus());
        self::assertSame('daily endorsement limit reached', $e->getMessage());
    }

    public function testSoftGateMapsToPermissionDenied403AndCarriesUnlockHint(): void
    {
        $hint = 'Your account must be at least 7 days old to endorse pages.';
        $e = EndorsementException::softGate($hint);

        self::assertSame('bcc_permission_denied', $e->errorCode());
        self::assertSame(403, $e->httpStatus());
        self::assertSame($hint, $e->getMessage());
        // The message IS the unlock hint (§1.4.5 data.unlock_hint companion).
        self::assertSame(['unlock_hint' => $hint], $e->data());
    }

    public function testNonSoftGateConstructorsCarryEmptyData(): void
    {
        self::assertSame([], EndorsementException::authRequired()->data());
        self::assertSame([], EndorsementException::busy()->data());
    }

    public function testIsCatchableAsAnEsotericException(): void
    {
        // Extends \RuntimeException → \Exception, so existing non-REST
        // `catch (\Exception)` call sites keep working unchanged.
        self::assertInstanceOf(\Exception::class, EndorsementException::authRequired());
        self::assertInstanceOf(\RuntimeException::class, EndorsementException::authRequired());
    }

    public function testPreservesPreviousThrowable(): void
    {
        $prev = new \RuntimeException('root cause');
        $e = new EndorsementException('wrapped', 'bcc_internal', 500, [], $prev);
        self::assertSame($prev, $e->getPrevious());
    }
}
