<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\PeepSoLeftoversRepository;
use BCC\Trust\Core\Services\PeepSoLeftoversService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The one-off removal of removed add-ons' data. Pins: the dry run reads
 * only; a run removes exactly the hardcoded inventory and nothing next to
 * it (the core / other-plugin keys that share a name fragment survive);
 * a group refuses while its add-on is loaded; table names are guarded.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoLeftoversServiceTest extends TestCase
{
    /** @var list<array{string, string, array<string, int|string|null>}> */
    private array $audit = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/peepso-leftovers-stubs.php';
        $GLOBALS['wpdb'] = new \PeepSoLeftoversFakeWpdb();

        // A site after the add-ons were removed (illustrative counts).
        $GLOBALS['__pl_tables'] = [
            'wp_peepso_friend_requests'           => 7,
            'wp_peepso_friends'                   => 8,
            'wp_peepso_friends_cache'             => 12,
            'wp_peepso_user_autofriends'          => 0,
            'wp_peepso_email_digest_content'      => 2,
            'wp_peepso_email_digest_log'          => 30,
            'wp_peepso_social_login_user_profile' => 6,
            'wp_social_users'                     => 9,
            'wp_peepso_api_rate_limit'            => 12,   // core — must survive
            'wp_peepso_activities'                => 900,  // core — must survive
        ];
        $GLOBALS['__pl_options'] = [
            'peepso_config' => [
                'friends_can_send_message_to'        => 1,
                'site_license_friendso'              => 'x',
                'email_digest_enable'                => 1,
                'email_digest_'                      => '',
                'email_digest_title'                 => 'stage.bluecollarcrypto.io',
                'site_license_peepso-email-digest'   => 'x',
                'site_license_social-login'          => 'x',
                'site_license_limitusers'            => 'x',
                // ⛔ core keys sharing a fragment
                'messages_friends_only'              => 1,
                'site_walls_friends'                 => 1,
                'limitusers_blacklist_domain'        => 'spam.test',
                'notification_digest_limit_per_section' => 5,
                'social_sharing_enable'              => 1,
            ],
            'peepso_email_digest'                 => 'tpl',
            'peepso_email_friend_request_send'    => 'tpl',
            'widget_peepsowidgetfriends'          => [],
            'nextend_social_login'                => ['x'],
            'social_login_option'                 => ['x'],
            // ⛔ not ours
            'peepso_email_notification_digest'    => 'core tpl',
            '_fluentmail_last_email_digest'       => 1,
            'wpseo_social'                        => [],
        ];
        $GLOBALS['__pl_usermeta'] = [
            'peepso_email_digest_sent'            => 11,
            'social_login_profile_id'             => 4,
            'social_login_open_google'            => 2,
            'social_login_open_x'                 => 1,
            // ⛔ keep
            'peepso_email_digest_receive_enabled' => 40,
            'peepso_chat_friends_only'            => 1,
            'social_loginx_unrelated'             => 1,   // shares no full prefix
        ];
        $this->audit = [];
    }

    private function auditor(): callable
    {
        return function (string $kind, string $name, array $detail): void {
            $this->audit[] = [$kind, $name, $detail];
        };
    }

    public function testThePlanReadsOnlyAndListsExactlyWhatIsPresent(): void
    {
        $before = [$GLOBALS['__pl_tables'], $GLOBALS['__pl_options'], $GLOBALS['__pl_usermeta']];
        $plan   = (new PeepSoLeftoversService())->plan('friends');

        self::assertSame([$GLOBALS['__pl_tables'], $GLOBALS['__pl_options'], $GLOBALS['__pl_usermeta']], $before, 'plan must not write');
        self::assertSame(
            ['wp_peepso_friend_requests' => 7, 'wp_peepso_friends' => 8, 'wp_peepso_friends_cache' => 12, 'wp_peepso_user_autofriends' => 0],
            $plan['tables']
        );
        self::assertSame(['friends_can_send_message_to', 'site_license_friendso'], $plan['config_keys']);
        self::assertSame(['peepso_email_friend_request_send', 'widget_peepsowidgetfriends'], $plan['options']);
        self::assertFalse($plan['nothing_left']);
    }

    public function testFriendsRunRemovesItsInventoryAndLeavesCoreAlone(): void
    {
        $r = (new PeepSoLeftoversService())->run('friends', $this->auditor());

        self::assertTrue($r['ok'], json_encode($r) ?: '');
        self::assertSame(
            ['wp_peepso_friend_requests', 'wp_peepso_friends', 'wp_peepso_friends_cache', 'wp_peepso_user_autofriends'],
            $GLOBALS['__pl_dropped']
        );
        $config = $GLOBALS['__pl_options']['peepso_config'];
        self::assertArrayNotHasKey('friends_can_send_message_to', $config);
        self::assertArrayNotHasKey('site_license_friendso', $config);
        foreach (['messages_friends_only', 'site_walls_friends'] as $core) {
            self::assertArrayHasKey($core, $config, $core . ' is PeepSo core');
        }
        self::assertArrayHasKey('wp_peepso_activities', $GLOBALS['__pl_tables']);
        self::assertSame(1, $GLOBALS['__pl_usermeta']['peepso_chat_friends_only'], 'BCC messages prefs own it');
        self::assertCount(count($r['removed']), $this->audit, 'one audit row per removed item');
    }

    public function testDigestRunKeepsCoresOwnDigestDataAndOtherPlugins(): void
    {
        $r = (new PeepSoLeftoversService())->run('email_digest', $this->auditor());

        self::assertTrue($r['ok']);
        self::assertSame(['wp_peepso_email_digest_content', 'wp_peepso_email_digest_log'], $GLOBALS['__pl_dropped']);
        self::assertArrayNotHasKey('peepso_email_digest', $GLOBALS['__pl_options']);
        self::assertArrayNotHasKey('peepso_email_digest_sent', $GLOBALS['__pl_usermeta']);
        foreach (['email_digest_enable', 'email_digest_', 'email_digest_title', 'site_license_peepso-email-digest'] as $k) {
            self::assertArrayNotHasKey($k, $GLOBALS['__pl_options']['peepso_config'], $k);
        }

        // ⛔ survivors
        self::assertSame(40, $GLOBALS['__pl_usermeta']['peepso_email_digest_receive_enabled']);
        self::assertSame('core tpl', $GLOBALS['__pl_options']['peepso_email_notification_digest']);
        self::assertSame(1, $GLOBALS['__pl_options']['_fluentmail_last_email_digest']);
        self::assertSame(5, $GLOBALS['__pl_options']['peepso_config']['notification_digest_limit_per_section']);
    }

    public function testSocialLoginListsThePrefixFamilyThenDeletesKeyByKey(): void
    {
        $plan = (new PeepSoLeftoversService())->plan('peepso_social_login');
        self::assertSame(
            ['social_login_profile_id' => 4, 'social_login_open_google' => 2, 'social_login_open_x' => 1],
            $plan['usermeta']
        );

        (new PeepSoLeftoversService())->run('peepso_social_login', $this->auditor());
        self::assertArrayNotHasKey('social_login_open_google', $GLOBALS['__pl_usermeta']);
        self::assertArrayHasKey('social_loginx_unrelated', $GLOBALS['__pl_usermeta']);
        self::assertSame(['wp_peepso_social_login_user_profile'], $GLOBALS['__pl_dropped']);
    }

    public function testUserLimitsStripsOnlyTheLicenceKey(): void
    {
        (new PeepSoLeftoversService())->run('user_limits', $this->auditor());

        $config = $GLOBALS['__pl_options']['peepso_config'];
        self::assertArrayNotHasKey('site_license_limitusers', $config);
        self::assertSame('spam.test', $config['limitusers_blacklist_domain'], 'core domain filter stays');
        self::assertSame([], $GLOBALS['__pl_dropped']);
    }

    public function testNextendRunLeavesYoastAndFluentAlone(): void
    {
        (new PeepSoLeftoversService())->run('nextend', $this->auditor());

        self::assertSame(['wp_social_users'], $GLOBALS['__pl_dropped']);
        self::assertArrayNotHasKey('nextend_social_login', $GLOBALS['__pl_options']);
        self::assertArrayHasKey('wpseo_social', $GLOBALS['__pl_options']);
        self::assertArrayHasKey('_fluentmail_last_email_digest', $GLOBALS['__pl_options']);
    }

    public function testAGroupRefusesWhileItsAddOnIsLoaded(): void
    {
        eval('final class NextendSocialLogin {}');

        $r = (new PeepSoLeftoversService())->run('nextend', $this->auditor());

        self::assertFalse($r['ok']);
        self::assertSame('the add-on is still installed', $r['refused'] ?? null);
        self::assertSame([], $GLOBALS['__pl_dropped']);
        self::assertSame([], $this->audit);
        self::assertTrue((new PeepSoLeftoversService())->plan('nextend')['installed']);
    }

    public function testASecondRunFindsNothingLeft(): void
    {
        $s = new PeepSoLeftoversService();
        $s->run('email_digest', $this->auditor());

        self::assertTrue($s->plan('email_digest')['nothing_left']);
    }

    public function testAnUnknownGroupIsRefused(): void
    {
        $r = (new PeepSoLeftoversService())->run('peepso', $this->auditor());
        self::assertSame('unknown group', $r['refused'] ?? null);
    }

    public function testTheRepositoryRefusesATableOutsideThePrefixOrWithOddCharacters(): void
    {
        $repo = new PeepSoLeftoversRepository();
        foreach (['users', 'wp_x`; DROP TABLE wp_users; --', 'wp_peepso friends'] as $bad) {
            try {
                $repo->dropTable($bad);
                self::fail('accepted ' . $bad);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Refusing table name', $e->getMessage());
            }
        }
        self::assertSame([], $GLOBALS['__pl_dropped']);
    }
}
