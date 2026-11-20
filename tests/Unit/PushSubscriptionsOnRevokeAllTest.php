<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\SessionController;
use BCC\Trust\Core\REST\MyAccountEndpoint;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-76 — revoking every token must also drop every push subscription.
 *
 * A push subscription outlives the session that registered it. Before, POST
 * /auth/logout-everywhere and PATCH /me/account/password revoked every bearer
 * but left the subscriptions, so the devices they were meant to cut off kept
 * receiving pushes (and their notification text).
 *
 * Pinned against the REAL SessionController::logoutEverywhere,
 * MyAccountEndpoint::patchPassword and PushSubscriptionRepository, over a
 * $wpdb double that records delete() and throws on anything else:
 *   - each path issues exactly one DELETE … WHERE user_id = <caller>;
 *   - a refused request (throttled / wrong password) deletes nothing.
 * Email-change verify is deliberately NOT a delete path; PendingEmailChangeTest
 * runs it over a $wpdb that throws on every call, which pins that.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/pending-email-stubs.php, then
 * tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushSubscriptionsOnRevokeAllTest extends TestCase
{
    private const USER     = 81;
    private const PASSWORD = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/pending-email-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        require_once __DIR__ . '/../Stubs/breached-password-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'        => [self::USER => ['email' => 'u81@example.com', 'login' => 'u81', 'display' => 'U81']],
            'meta'         => [self::USER => ['bcc_handle' => 'handle81']],
            'transients'   => [],
            'mail'         => [],
            'actions'      => [],
            'options'      => [],
            'audit'        => [],
            'canary'       => [],
            'current_user' => self::USER,
            'passwords'    => [self::USER => self::PASSWORD],
            'deletes'      => [],
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /**
             * @param array<string, mixed> $where
             * @param list<string>         $format
             */
            public string $last_error = '';

            /** @return int|false */
            public function delete(string $table, array $where, array $format = [])
            {
                $GLOBALS['__bcc_ph']['deletes'][] = [$table, $where, $format];
                if (!empty($GLOBALS['__bcc_ph']['delete_fails'])) {
                    $this->last_error = 'stub: table locked';
                    return false;
                }
                return 2;
            }

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('unexpected $wpdb->' . $name . ' in unit test');
            }
        };
    }

    private const EXPECTED_DELETE = ['wp_bcc_push_subscriptions', ['user_id' => self::USER], ['%d']];

    public function testLogoutEverywhereDropsEveryPushSubscription(): void
    {
        $res = (new SessionController())->logoutEverywhere(new \WP_REST_Request([]));

        self::assertSame(200, $res->get_status());
        self::assertSame([self::EXPECTED_DELETE], $GLOBALS['__bcc_ph']['deletes']);
        self::assertContains('sessionsRevokedAll:' . self::USER, $GLOBALS['__bcc_ph']['canary'], 'control: the revoke path ran');
    }

    /** H3: "everywhere" includes WordPress's own credentials of the account. */
    public function testLogoutEverywhereEndsEveryWordPressSessionAndApplicationPassword(): void
    {
        $res = (new SessionController())->logoutEverywhere(new \WP_REST_Request([]));

        self::assertSame(200, $res->get_status());
        self::assertSame([self::USER], $GLOBALS['__bcc_ph']['wp_sessions_destroyed'] ?? []);
        self::assertSame([self::USER], $GLOBALS['__bcc_ph']['app_passwords_deleted'] ?? []);
    }

    public function testAThrottledLogoutEverywhereDeletesNothing(): void
    {
        $GLOBALS['__bcc_ph']['throttle_deny'] = true;

        $res = (new SessionController())->logoutEverywhere(new \WP_REST_Request([]));

        self::assertSame(429, $res->get_status());
        self::assertSame([], $GLOBALS['__bcc_ph']['deletes']);
        self::assertSame([], $GLOBALS['__bcc_ph']['wp_sessions_destroyed'] ?? []);
        self::assertSame([], $GLOBALS['__bcc_ph']['app_passwords_deleted'] ?? []);
    }

    public function testAPasswordChangeDropsEveryPushSubscription(): void
    {
        $res = (new MyAccountEndpoint())->patchPassword(new \WP_REST_Request([
            'current_password' => self::PASSWORD,
            'password'         => 'a brand new long password',
        ]));

        self::assertSame(200, $res->get_status());
        self::assertSame([self::EXPECTED_DELETE], $GLOBALS['__bcc_ph']['deletes']);
        self::assertContains('passwordChanged:' . self::USER, $GLOBALS['__bcc_ph']['canary'], 'control: the rotation ran');
        self::assertSame([], $GLOBALS['__bcc_ph']['auth_cookies'] ?? [], 'no WordPress auth cookie re-issued (H3)');
    }

    public function testAFailedDeleteIsLoggedAndTheRevokeStillSucceeds(): void
    {
        $GLOBALS['__bcc_ph']['delete_fails'] = true;

        $res = (new SessionController())->logoutEverywhere(new \WP_REST_Request([]));

        self::assertSame(200, $res->get_status(), 'the session revoke is not undone by a push-cleanup failure');
        self::assertSame(['[bcc-trust] push subscriptions not deleted'], $GLOBALS['__bcc_ph']['log_errors'] ?? []);
    }

    /**
     * F-495: when the revocation write does not land, nothing was revoked, so
     * "sign out everywhere" answers 503 (try again), never 200. The
     * WordPress credentials and push subscriptions are still cut: each is
     * its own write.
     */
    public function testARevocationThatCannotBeWrittenIsA503(): void
    {
        $GLOBALS['__bcc_ph']['fail_update']['bcc_token_version'] = true;

        $res  = (new SessionController())->logoutEverywhere(new \WP_REST_Request([]));
        $data = $res->get_data();

        self::assertSame(503, $res->get_status());
        self::assertSame('bcc_unavailable', $data['error']['code'] ?? null);
        $headers = new \ReflectionProperty($res, 'headers');
        $headers->setAccessible(true);
        self::assertSame('private, no-store', $headers->getValue($res)['Cache-Control'] ?? null);
        self::assertSame(['bcc_token_version'], $GLOBALS['__bcc_ph']['failed_updates'] ?? null, 'control: the revoke was attempted');
        self::assertSame([self::USER], $GLOBALS['__bcc_ph']['wp_sessions_destroyed'] ?? []);
        self::assertSame([self::EXPECTED_DELETE], $GLOBALS['__bcc_ph']['deletes']);
    }

    /**
     * F-508: logout-everywhere audits and mails only a revocation that
     * landed; a failed one is audited as such and claims nothing.
     */
    public function testAFailedRevocationIsAuditedAsFailedAndMailsNothing(): void
    {
        $GLOBALS['__bcc_ph']['fail_update']['bcc_token_version'] = true;

        $res = (new SessionController())->logoutEverywhere(new \WP_REST_Request([]));

        self::assertSame(503, $res->get_status());
        self::assertSame(['sessions_revoke_failed'], $GLOBALS['__bcc_ph']['audit'] ?? null);
        self::assertNotContains('sessionsRevokedAll:' . self::USER, $GLOBALS['__bcc_ph']['canary'] ?? [], 'no "sessions revoked" mail');
    }

    public function testASuccessfulRevocationIsAuditedAndMailedAfterTheRevoke(): void
    {
        $res = (new SessionController())->logoutEverywhere(new \WP_REST_Request([]));

        self::assertSame(200, $res->get_status());
        self::assertSame(['sessions_revoked_all'], $GLOBALS['__bcc_ph']['audit'] ?? null);
        self::assertContains('sessionsRevokedAll:' . self::USER, $GLOBALS['__bcc_ph']['canary']);
        self::assertSame(['bcc_token_version'], $GLOBALS['__bcc_ph']['updates'] ?? null, 'the revocation landed');
    }

    /**
     * F-508: the password change revokes FIRST. When that write does not
     * land, nothing changes: 503, the password stands, no fresh token.
     */
    public function testAPasswordChangeWhoseRevocationFailsChangesNothing(): void
    {
        $GLOBALS['__bcc_ph']['fail_update']['bcc_token_version'] = true;

        $res = (new MyAccountEndpoint())->patchPassword(new \WP_REST_Request([
            'current_password' => self::PASSWORD,
            'password'         => 'a brand new long password',
        ]));
        $data = $res->get_data();

        self::assertSame(503, $res->get_status());
        self::assertSame('bcc_unavailable', $data['error']['code'] ?? null);
        self::assertSame(self::PASSWORD, $GLOBALS['__bcc_ph']['passwords'][self::USER] ?? null, 'the password is unchanged');
        self::assertSame([], $GLOBALS['__bcc_ph']['deletes'], 'push subscriptions untouched');
        self::assertNotContains('account_password_changed', $GLOBALS['__bcc_ph']['audit'] ?? []);
    }

    public function testAWrongCurrentPasswordDeletesNothing(): void
    {
        $res = (new MyAccountEndpoint())->patchPassword(new \WP_REST_Request([
            'current_password' => 'wrong',
            'password'         => 'a brand new long password',
        ]));

        self::assertNotSame(200, $res->get_status());
        self::assertSame([], $GLOBALS['__bcc_ph']['deletes']);
    }
}
