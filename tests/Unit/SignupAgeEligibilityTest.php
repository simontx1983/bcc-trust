<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\OAuthController;
use BCC\Trust\Core\REST\Auth\PasswordAuthController;
use BCC\Trust\Core\REST\Auth\WalletAuthController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SignupAgeEligibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = ['users' => [], 'transients' => [], 'mail' => [], 'actions' => [], 'locks' => []];
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
        yield 'under 18' => [['birth_date' => gmdate('Y-m-d')], 403, 'age_ineligible'];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('rejectedDates')]
    public function testRejectedDatesNeverIssueProof(array $params, int $status, string $code): void
    {
        $response = (new PasswordAuthController())->ageEligibility(new WP_REST_Request($params));
        $this->assertError($response, $status, $code);
        self::assertEmpty($GLOBALS['__bcc_ph']['transients']);
    }

    public function testProofCarriesNoDateAndMustBeProvidedInTheJsonBody(): void
    {
        $date = '1990-04-07';
        $response = (new PasswordAuthController())->ageEligibility(new WP_REST_Request(['birth_date' => $date]));
        self::assertSame(200, $response->get_status());
        self::assertSame('no-store', $response->get_headers()['Cache-Control']);
        $data = $response->get_data()['data'];
        self::assertSame(['eligibility_token'], array_keys($data));
        self::assertStringNotContainsString($date, $data['eligibility_token']);
        self::assertEmpty($GLOBALS['__bcc_ph']['transients']);

        self::assertNull(AuthSupport::requireSignupEligibility(new WP_REST_Request($data)));
        $this->assertError(
            AuthSupport::requireSignupEligibility(new WP_REST_Request($data, '{}')),
            403,
            'age_eligibility_required',
        );
        $this->assertError(
            (new PasswordAuthController())->ageEligibility(new WP_REST_Request(['birth_date' => $date], '{}')),
            400,
            'bcc_invalid_request',
        );
    }

    public function testProofVerificationRejectsInvalidAndExpiredProofs(): void
    {
        $token = AuthSupport::issueSignupEligibilityToken();
        self::assertNotNull($token);
        $changed = substr($token, 0, -1) . (str_ends_with($token, '0') ? '1' : '0');
        $expiredPayload = (time() - 1) . '.' . str_repeat('1', 64);
        $expired = $expiredPayload . '.' . hash_hmac('sha256', 'bcc-signup-eligibility-v1|' . $expiredPayload, wp_salt('auth'));

        foreach ([$changed, $expired, ['token'], null] as $invalidToken) {
            $response = AuthSupport::requireSignupEligibility(new WP_REST_Request(['eligibility_token' => $invalidToken]));
            $this->assertError($response, 403, 'age_eligibility_required');
        }
    }

    public function testAllSelfServiceCreationPathsRequireProofBeforeCreatingAnything(): void
    {
        $params = ['handle' => 'member', 'email' => 'member@example.test', 'password' => 'example password'];
        $this->assertError(
            (new PasswordAuthController())->signup(new WP_REST_Request($params)),
            403,
            'age_eligibility_required',
        );
        $this->assertError(
            (new WalletAuthController())->walletSignup(new WP_REST_Request($params + [
                'wallet_address' => 'synthetic-wallet', 'signature' => 'synthetic-signature',
            ])),
            403,
            'age_eligibility_required',
        );

        foreach (['google', 'twitter'] as $provider) {
            $providerToken = 'pending-' . $provider;
            AuthSupport::storeOauthProviderToken($providerToken, [
                'provider' => $provider, 'provider_id' => 'synthetic-' . $provider,
                'email' => '', 'display_name' => 'Synthetic',
            ]);
            $this->assertError(
                (new OAuthController())->oauthComplete(new WP_REST_Request($params + ['provider_token' => $providerToken])),
                403,
                'age_eligibility_required',
            );
            self::assertNotNull(AuthSupport::peekOauthProviderToken($providerToken));
        }

        self::assertEmpty($GLOBALS['__bcc_ph']['users']);
        self::assertEmpty($GLOBALS['__bcc_ph']['mail']);
        self::assertEmpty($GLOBALS['__bcc_ph']['actions']);
    }

    private function assertError(?WP_REST_Response $response, int $status, string $code): void
    {
        self::assertNotNull($response);
        self::assertSame($status, $response->get_status());
        self::assertSame($code, $response->get_data()['error']['code']);
        self::assertArrayNotHasKey('data', $response->get_data());
        self::assertSame('no-store', $response->get_headers()['Cache-Control']);
    }
}
