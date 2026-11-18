<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Security\SuspendedWriteGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-305 — ONE gate for every BCC write by a suspended member.
 *
 * A suspended member stays signed in read-only (user decision 2026-11-12),
 * so writes must be refused centrally: several routes never checked
 * suspension. The REAL SuspendedWriteGate is driven with a suspension-state
 * double (true / false / null = unknown).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SuspendedWriteGateTest extends TestCase
{
    private const USER = 70;

    protected function setUp(): void
    {
        parent::setUp();
        // Permissions::suspensionState double + the WP_REST_Response double.
        require_once __DIR__ . '/../Stubs/auth-session-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_susp'] = [];
    }

    /** @return array{int, array<string, mixed>}|null */
    private static function answer(string $method, string $route, ?bool $state, int $user = self::USER): ?array
    {
        $GLOBALS['__bcc_susp'] = [$user => $state];
        $res = SuspendedWriteGate::decide($method, $route, $user);
        if ($res === null) {
            return null;
        }
        $data = $res->get_data();
        self::assertIsArray($data);
        return [$res->get_status(), $data];
    }

    /** @return array<string, array{string, string}> every route a suspended member must still reach */
    public static function allowed(): array
    {
        return [
            'log out'            => ['POST', '/bcc/v1/auth/logout'],
            'log out everywhere' => ['POST', '/bcc/v1/auth/logout-everywhere'],
            'refresh'            => ['POST', '/bcc/v1/auth/refresh'],
            'session'            => ['POST', '/bcc/v1/auth/session'],
            'password sign-in'   => ['POST', '/bcc/v1/auth/login'],
            'nonce'              => ['POST', '/bcc/v1/auth/nonce'],
            'wallet nonce'       => ['POST', '/bcc/v1/auth/wallet-nonce'],
            'wallet sign-in'     => ['POST', '/bcc/v1/auth/wallet-login'],
            'wallet sign-up'     => ['POST', '/bcc/v1/auth/wallet-signup'],
            'sign-up'            => ['POST', '/bcc/v1/auth/signup'],
            'oauth bridge'       => ['POST', '/bcc/v1/auth/oauth'],
            'oauth complete'     => ['POST', '/bcc/v1/auth/oauth-complete'],
            '2fa verify'         => ['POST', '/bcc/v1/auth/2fa/verify'],
            '2fa resend'         => ['POST', '/bcc/v1/auth/2fa/resend'],
            'forgot password'    => ['POST', '/bcc/v1/auth/forgot-password'],
            'reset password'     => ['POST', '/bcc/v1/auth/reset-password'],
            'verify email'       => ['POST', '/bcc/v1/auth/verify-email'],
            'resend verify'      => ['POST', '/bcc/v1/auth/resend-verification'],
            'appeal the finding' => ['POST', '/bcc/v1/me/findings/123/appeal'],
            'notification prefs' => ['PATCH', '/bcc/v1/me/notification-prefs'],
            'privacy'            => ['PATCH', '/bcc/v1/me/privacy'],
            'push unsubscribe'   => ['DELETE', '/bcc/v1/me/push-subscriptions/9'],
            'block'              => ['POST', '/bcc/v1/me/blocks'],
            'unblock'            => ['DELETE', '/bcc/v1/me/blocks/71'],
            'password change'    => ['PATCH', '/bcc/v1/me/account/password'],
        ];
    }

    #[DataProvider('allowed')]
    public function testASuspendedMemberCanStillDoTheAllowListedThings(string $method, string $route): void
    {
        self::assertSame(SuspendedWriteGate::ALLOW, SuspendedWriteGate::classify($method, $route));
        self::assertNull(self::answer($method, $route, true));
    }

    /** @return array<string, array{string, string}> a sample of writes the gate must refuse */
    public static function blocked(): array
    {
        return [
            'cover image upload'   => ['POST', '/bcc/v1/blogs/cover-image'],
            'collection stance'    => ['POST', '/bcc/v1/collections/5/stance'],
            'announcement comment' => ['POST', '/bcc/v1/announcements/7/comments'],
            'attestation'          => ['POST', '/bcc/v1/me/attestations'],
            'report'               => ['POST', '/bcc/v1/me/reports'],
            'unlisted auth route'  => ['POST', '/bcc/v1/auth/something-new'],
            'push subscribe'       => ['POST', '/bcc/v1/me/push-subscriptions'],
            'push sub, not delete' => ['POST', '/bcc/v1/me/push-subscriptions/9'],
            'claim a page'         => ['POST', '/bcc/v1/pages/12/claim'],
            'post'                 => ['POST', '/bcc/v1/posts'],
            'GitHub connect'       => ['POST', '/bcc-trust/v1/github/auth'],
            'X connect'            => ['POST', '/bcc-trust/v1/x/auth'],
            'another appeal verb'  => ['DELETE', '/bcc/v1/me/findings/123/appeal'],
        ];
    }

    #[DataProvider('blocked')]
    public function testEveryOtherWriteIsRefusedWhileSuspended(string $method, string $route): void
    {
        self::assertSame(SuspendedWriteGate::GATE, SuspendedWriteGate::classify($method, $route));

        $refusal = self::answer($method, $route, true);
        self::assertNotNull($refusal);
        [$status, $body] = $refusal;
        self::assertSame(403, $status);
        self::assertSame('bcc_forbidden', $body['error']['code']);
        self::assertSame('Your account is suspended.', $body['error']['message']);
        self::assertSame('account_suspended', $body['error']['data']['reason_code']);
    }

    #[DataProvider('blocked')]
    public function testAnUnknownStateFallsThroughToTheRoutesOwnChecks(string $method, string $route): void
    {
        self::assertNull(self::answer($method, $route, null), 'a DB blip must not fail the whole write API');
        self::assertNull(self::answer($method, $route, false));
    }

    /** @return array<string, array{string, string}> */
    public static function mixedCase(): array
    {
        return [
            'upper namespace'        => ['POST', '/BCC/v1/posts'],
            'mixed namespace'        => ['POST', '/Bcc/V1/me/reports'],
            'mixed bcc-trust'        => ['POST', '/Bcc-Trust/v1/github/auth'],
            'upper path'             => ['POST', '/bcc/v1/ME/ATTESTATIONS'],
            'lower method'           => ['post', '/bcc/v1/posts'],
        ];
    }

    /** WordPress routes case-insensitively, so the gate must too (review on #637). */
    #[DataProvider('mixedCase')]
    public function testCapitalLettersDoNotSkipTheGate(string $method, string $route): void
    {
        self::assertSame(SuspendedWriteGate::GATE, SuspendedWriteGate::classify($method, $route));
        [$status] = self::answer($method, $route, true) ?? [0];
        self::assertSame(403, $status);
    }

    public function testMixedCaseAccountAndAllowListRoutesKeepTheirRules(): void
    {
        self::assertSame(SuspendedWriteGate::ACCOUNT, SuspendedWriteGate::classify('DELETE', '/BCC/v1/ME/Account'));
        self::assertSame(SuspendedWriteGate::ALLOW, SuspendedWriteGate::classify('PATCH', '/BCC/v1/me/Account/Password'));
    }

    /**
     * The filter itself, as WordPress calls it, including for each
     * sub-request of a /batch/v1 call (WP_REST_Server::serve_batch_request_v1
     * applies rest_pre_dispatch per sub-request, with that sub-request's route).
     */
    public function testTheFilterRefusesAMixedCaseRouteAndLeavesAnEarlierAnswerAlone(): void
    {
        $GLOBALS['__bcc_susp'] = [71 => true]; // get_current_user_id() double = 71
        $request = new class([]) extends \WP_REST_Request {
            public function get_method(): string { return 'POST'; }
            public function get_route(): string { return '/BCC/v1/collections/5/stance'; }
        };

        $res = SuspendedWriteGate::filter(null, null, $request);
        self::assertInstanceOf(\WP_REST_Response::class, $res);
        self::assertSame(403, $res->get_status());

        $earlier = new \WP_REST_Response(['ok' => true], 200);
        self::assertSame($earlier, SuspendedWriteGate::filter($earlier, null, $request), 'an earlier answer is never overridden');
        self::assertSame('passthrough', SuspendedWriteGate::filter('passthrough', null, new \stdClass()));
    }

    /** @return array<string, array{string, string}> */
    public static function accountIdentity(): array
    {
        return [
            'email change'        => ['PATCH', '/bcc/v1/me/account/email'],
            'email change verify' => ['POST', '/bcc/v1/me/account/email/verify'],
            'account delete'      => ['DELETE', '/bcc/v1/me/account'],
            'recovery email'      => ['PUT', '/bcc/v1/me/account/recovery-email'],
            'recovery verify'     => ['POST', '/bcc/v1/me/account/recovery-email/verify'],
            'link a wallet'       => ['POST', '/bcc/v1/auth/wallet-link'],
            'link, mixed case'    => ['POST', '/BCC/v1/Auth/Wallet-Link'],
        ];
    }

    #[DataProvider('accountIdentity')]
    public function testEmailChangeAndAccountDeleteAreRefusedWhileSuspendedAndOnUnknown(string $method, string $route): void
    {
        self::assertSame(SuspendedWriteGate::ACCOUNT, SuspendedWriteGate::classify($method, $route));

        [$status, $body] = self::answer($method, $route, true) ?? [0, []];
        self::assertSame(403, $status);
        self::assertSame('account_suspended', $body['error']['data']['reason_code']);

        [$status, $body] = self::answer($method, $route, null) ?? [0, []];
        self::assertSame(503, $status, 'F-109: an identity write denies on unknown');
        self::assertSame('bcc_unavailable', $body['error']['code']);

        self::assertNull(self::answer($method, $route, false));
    }

    public function testThePasswordChangeIsAllowedWhateverTheState(): void
    {
        foreach ([true, null, false] as $state) {
            self::assertNull(self::answer('PATCH', '/bcc/v1/me/account/password', $state));
        }
    }

    /** @return array<string, array{string, string}> */
    public static function untouched(): array
    {
        return [
            'a read'            => ['GET', '/bcc/v1/posts'],
            'HEAD'              => ['HEAD', '/bcc/v1/me/blocks'],
            'OPTIONS preflight' => ['OPTIONS', '/bcc/v1/posts'],
            'core WP route'     => ['POST', '/wp/v2/posts'],
            'another plugin'    => ['POST', '/other/v1/thing'],
        ];
    }

    #[DataProvider('untouched')]
    public function testReadsAndNonBccRoutesAreNeverGated(string $method, string $route): void
    {
        self::assertSame(SuspendedWriteGate::NONE, SuspendedWriteGate::classify($method, $route));
        self::assertNull(self::answer($method, $route, true));
    }

    public function testAnonymousRequestsPassToTheRoutesOwn401(): void
    {
        // suspensionState() answers TRUE for user 0; the gate must not read it.
        self::assertNull(self::answer('POST', '/bcc/v1/posts', true, 0));
    }

    public function testTheGateIsRegisteredAtBoot(): void
    {
        $boot = (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');
        self::assertStringContainsString('\BCC\Trust\Core\Security\SuspendedWriteGate::register();', $boot);

        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Security/SuspendedWriteGate.php');
        self::assertStringContainsString("add_filter('rest_pre_dispatch', [self::class, 'filter'], 20, 3);", $src);
        self::assertStringContainsString('suspensionState($userId, false)', $src, 'admin bypass off');
    }
}
