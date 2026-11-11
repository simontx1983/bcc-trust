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
            public function delete(string $table, array $where, array $format = []): int
            {
                $GLOBALS['__bcc_ph']['deletes'][] = [$table, $where, $format];
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

    public function testAThrottledLogoutEverywhereDeletesNothing(): void
    {
        $GLOBALS['__bcc_ph']['throttle_deny'] = true;

        $res = (new SessionController())->logoutEverywhere(new \WP_REST_Request([]));

        self::assertSame(429, $res->get_status());
        self::assertSame([], $GLOBALS['__bcc_ph']['deletes']);
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
