<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\PasswordAuthController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SignupAgeEligibilityIssuerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = ['users' => [], 'transients' => [], 'mail' => [], 'actions' => []];
    }

    public function testUtcCalendarBoundaryIncludesTheEighteenthBirthday(): void
    {
        $now = gmmktime(0, 0, 0, 11, 26, 2026);
        self::assertTrue(AuthSupport::signupAgeEligible('2008-11-26', $now));
        self::assertFalse(AuthSupport::signupAgeEligible('2008-11-27', $now));
        self::assertTrue(AuthSupport::signupAgeEligible('2008-11-25', $now));
        self::assertNull(AuthSupport::signupAgeEligible('2026-11-27', $now));
        self::assertFalse(AuthSupport::signupAgeEligible('2008-02-29', gmmktime(0, 0, 0, 2, 28, 2026)));
        self::assertTrue(AuthSupport::signupAgeEligible('2008-02-29', gmmktime(0, 0, 0, 3, 1, 2026)));
    }

    /** @return iterable<string, array{array<string, mixed>, int, string}> */
    public static function rejectedDates(): iterable
    {
        yield 'missing date' => [[], 400, 'bcc_invalid_request'];
        yield 'impossible date' => [['birth_date' => '2000-02-30'], 400, 'bcc_invalid_request'];
        yield 'wrong format' => [['birth_date' => '2000-2-03'], 400, 'bcc_invalid_request'];
        yield 'array' => [['birth_date' => ['2000-02-03']], 400, 'bcc_invalid_request'];
        yield 'future date' => [['birth_date' => '9999-12-31'], 400, 'bcc_invalid_request'];
        yield 'under 18' => [['birth_date' => gmdate('Y-m-d')], 403, 'age_ineligible'];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('rejectedDates')]
    public function testRejectedDatesNeverIssueProof(array $params, int $status, string $code): void
    {
        $response = (new PasswordAuthController())->ageEligibility(new WP_REST_Request($params));
        $this->assertError($response, $status, $code);
        $this->assertNoSignupSideEffects();
    }

    public function testProofRequiresJsonAndCarriesNoDate(): void
    {
        $date = '1990-04-07';
        $controller = new PasswordAuthController();
        $startedAt = time();
        $response = $controller->ageEligibility(new WP_REST_Request(['birth_date' => $date]));

        self::assertSame(200, $response->get_status());
        self::assertSame('no-store', $response->get_headers()['Cache-Control']);
        $data = $response->get_data()['data'];
        self::assertSame(['eligibility_token'], array_keys($data));
        $proof = $data['eligibility_token'];
        self::assertStringNotContainsString($date, $proof);
        self::assertMatchesRegularExpression('/\A[0-9]{1,11}\.[0-9a-f]{64}\.[0-9a-f]{64}\z/', $proof);
        [$expiry, $nonce, $signature] = explode('.', $proof);
        self::assertGreaterThanOrEqual($startedAt + 1200, (int) $expiry);
        self::assertLessThanOrEqual(time() + 1200, (int) $expiry);
        self::assertSame(
            hash_hmac('sha256', 'bcc-signup-eligibility-v1|' . $expiry . '.' . $nonce, wp_salt('auth')),
            $signature,
        );
        $this->assertError(
            $controller->ageEligibility(new WP_REST_Request(['birth_date' => $date], '{}')),
            400,
            'bcc_invalid_request',
        );
        $this->assertNoSignupSideEffects();
    }

    public function testRateLimitRefusesAnEligibleDateWithoutIssuingProof(): void
    {
        $GLOBALS['__bcc_ph']['throttle_deny'] = true;
        $response = (new PasswordAuthController())->ageEligibility(new WP_REST_Request(['birth_date' => '1990-04-07']));

        $this->assertError($response, 429, 'bcc_rate_limited');
        self::assertSame(['signup_age_eligibility'], $GLOBALS['__bcc_ph']['throttle_keys']);
        self::assertSame(5, $GLOBALS['__bcc_ph']['throttle_limits']['signup_age_eligibility']);
        self::assertSame(60, $GLOBALS['__bcc_ph']['throttle_windows']['signup_age_eligibility']);
        $this->assertNoSignupSideEffects();
    }

    private function assertError(WP_REST_Response $response, int $status, string $code): void
    {
        self::assertSame($status, $response->get_status());
        self::assertSame($code, $response->get_data()['error']['code']);
        self::assertArrayNotHasKey('data', $response->get_data());
        self::assertSame('no-store', $response->get_headers()['Cache-Control']);
    }

    private function assertNoSignupSideEffects(): void
    {
        self::assertEmpty($GLOBALS['__bcc_ph']['transients']);
        self::assertEmpty($GLOBALS['__bcc_ph']['users']);
        self::assertEmpty($GLOBALS['__bcc_ph']['mail']);
        self::assertEmpty($GLOBALS['__bcc_ph']['actions']);
    }
}
