<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Support\UserSlugResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Real-engine coverage for the two identity migrations and the retired-
 * handle resolution:
 *
 * - rotate_login_handles_v1: a member whose bcc_handle equals their login
 *   (case-insensitive, via the column collation) gets a new handle, and
 *   the old one is recorded as retired. Display-name slug when it isn't
 *   the login, else member-{id}. Idempotent (a second run finds nothing).
 * - display_name_placeholders_v3: display_name = user_login is rewritten
 *   to the handle, and to 'Member <id>' when the handle is the login.
 * - UserSlugResolver / HandleService: an exact retired handle resolves to
 *   its member and is never available again; an arbitrary login with no
 *   retired record does NOT resolve through the retired path.
 *
 * The WordPress user-meta functions are DB-backed doubles over a real
 * wp_usermeta, so the migrations' SQL (collation compare, self-narrowing
 * selection, ORDER/LIMIT) runs against the real engine. Separate
 * processes keep those doubles out of sibling suites.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LoginHandleRotationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->clearFaultInjection();

        self::defineUserMetaDoubles();
        require_once dirname(__DIR__, 2) . '/includes/database/migration-runner.php';
        require_once dirname(__DIR__, 2) . '/includes/database/backfill-canonical-handles.php';
        require_once dirname(__DIR__, 2) . '/includes/database/rotate-login-handles.php';
        require_once dirname(__DIR__, 2) . '/includes/database/backfill-display-name-placeholders.php';

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->usermeta}` (
                umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) NULL DEFAULT NULL,
                meta_value LONGTEXT NULL,
                PRIMARY KEY (umeta_id),
                KEY user_id (user_id),
                KEY meta_key (meta_key(191))
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        self::assertSame('', $wpdb->last_error, 'wp_usermeta must be creatable');
        $wpdb->query("DELETE FROM `{$wpdb->usermeta}`");
        $wpdb->query("DELETE FROM `{$wpdb->users}`");
        $GLOBALS['__bcc_handle_changed'] = [];
        $GLOBALS['__bcc_fail_handle_write'] = false;
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM `{$wpdb->usermeta}`");
        $wpdb->query("DELETE FROM `{$wpdb->users}`");
        parent::tearDown();
    }

    private static function defineUserMetaDoubles(): void
    {
        if (\function_exists('get_user_meta')) {
            return;
        }
        eval(<<<'PHP'
            function get_user_meta(int $userId, string $key = '', bool $single = false)
            {
                global $wpdb;
                $v = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id LIMIT 1",
                    $userId, $key
                ));
                return $v === null ? '' : $v;
            }
            function update_user_meta(int $userId, string $key, $value): bool
            {
                global $wpdb;
                if (!empty($GLOBALS['__bcc_fail_handle_write']) && $key === 'bcc_handle') {
                    return false; // simulated write failure, nothing written
                }
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $userId, $key));
                $wpdb->insert($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $key, 'meta_value' => (string) $value]);
                return true;
            }
            function add_user_meta(int $userId, string $key, $value, bool $unique = false)
            {
                global $wpdb;
                $wpdb->insert($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $key, 'meta_value' => (string) $value]);
                return $wpdb->insert_id;
            }
            // Only the shape the code under test uses: exact meta_key +
            // meta_value, fields=ID, number.
            function get_users(array $args = []): array
            {
                global $wpdb;
                $sql = $wpdb->prepare(
                    "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s ORDER BY user_id LIMIT %d",
                    (string) ($args['meta_key'] ?? ''),
                    (string) ($args['meta_value'] ?? ''),
                    (int) ($args['number'] ?? 100)
                );
                return array_map('strval', $wpdb->get_col($sql));
            }
            function get_user_by(string $field, $value) { return false; }
            function sanitize_title(string $s): string
            {
                $s = strtolower(trim($s));
                $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
                return trim($s, '-');
            }
            function clean_user_cache($user): void {}
            function do_action(string $hook, ...$args): void
            {
                if ($hook === 'bcc_handle_changed') {
                    $GLOBALS['__bcc_handle_changed'][] = $args;
                }
            }
            PHP);
    }

    private static function user(int $id, string $login, string $displayName, ?string $handle): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->users, [
            'ID' => $id, 'user_login' => $login, 'user_email' => $login . '@example.test', 'display_name' => $displayName,
        ]);
        if ($handle !== null) {
            add_user_meta($id, HandleService::META_HANDLE, $handle);
        }
    }

    public function testLoginEqualHandlesAreRotatedAndRetired(): void
    {
        self::user(1, 'jsmith77', 'Jane Smith', 'jsmith77');   // display slug usable
        self::user(2, 'Bob99', 'Bob99', 'bob99');              // case-insensitive match, display = login
        self::user(3, 'carol-k', 'Carol K', 'carol-k');        // display != login, but its slug == login
        self::user(4, 'u_dave', 'Dave', 'dave');               // BCC signup: handle != login, untouched

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_rotate_login_handles());

        $svc = new HandleService();
        self::assertSame('jane-smith', $svc->getHandle(1));
        self::assertSame('member-2', $svc->getHandle(2));
        self::assertSame('member-3', $svc->getHandle(3));
        self::assertSame('dave', $svc->getHandle(4));

        self::assertSame(1, HandleService::retiredOwner('jsmith77'));
        self::assertSame(2, HandleService::retiredOwner('bob99'));
        self::assertSame(3, HandleService::retiredOwner('carol-k'));
        self::assertNull(HandleService::retiredOwner('dave'));

        self::assertCount(3, $GLOBALS['__bcc_handle_changed'], 'bcc_handle_changed fires once per rotation');
        self::assertSame([1, 'jsmith77', 'jane-smith'], $GLOBALS['__bcc_handle_changed'][0]);
    }

    public function testRotationIsIdempotent(): void
    {
        self::user(1, 'jsmith77', 'Jane Smith', 'jsmith77');
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_rotate_login_handles());
        $GLOBALS['__bcc_handle_changed'] = [];

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_rotate_login_handles());
        self::assertSame([], $GLOBALS['__bcc_handle_changed']);
        global $wpdb;
        self::assertSame('1', (string) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'bcc_handle_retired'"
        ), 'a re-run must not add a second retired row');
    }

    public function testRetiredHandleResolvesButIsNeverAvailable(): void
    {
        self::user(1, 'jsmith77', 'Jane Smith', 'jsmith77');
        bcc_trust_rotate_login_handles();

        self::assertSame(1, UserSlugResolver::resolve('jsmith77'));
        self::assertSame(1, UserSlugResolver::resolve('JSmith77'));
        self::assertSame(1, UserSlugResolver::resolve('jane-smith'));

        $svc = new HandleService();
        self::assertFalse($svc->isAvailable('jsmith77'), 'retired handle is reserved for everyone');
        self::assertFalse($svc->isAvailable('jsmith77', 1), 'even for its former holder');
        self::assertTrue($svc->isAvailable('someone-new'));
    }

    public function testRetiredPathIsNotALoginLookup(): void
    {
        // A login that was never a handle: no retired record, so the
        // retired path must not resolve it (get_user_by is stubbed to
        // false here, isolating the retired path from the legacy login
        // fallback that is held for a later change).
        self::user(5, 'privatelogin', 'Pat', 'pat');
        bcc_trust_rotate_login_handles();

        self::assertNull(HandleService::retiredOwner('privatelogin'));
        self::assertSame(0, UserSlugResolver::resolve('privatelogin'));
        self::assertSame(5, UserSlugResolver::resolve('pat'));
    }

    public function testSquattedMemberHandleIsRederivedButNotRetired(): void
    {
        // Member 10 chose 'member-7' before the form was reserved.
        self::user(10, 'tenlogin', 'Ten Person', 'member-7');
        // Member 11 holds their OWN fallback form: never selected.
        self::user(11, 'elevenlogin', 'Eleven', 'member-11');

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_rotate_login_handles());

        $svc = new HandleService();
        self::assertSame('ten-person', $svc->getHandle(10));
        self::assertSame('member-11', $svc->getHandle(11), 'own member-{id} is left alone');

        // Not retired: no redirect, and member 7 can still be given it.
        self::assertNull(HandleService::retiredOwner('member-7'));
        self::assertSame(0, UserSlugResolver::resolve('member-7'));
        self::assertTrue($svc->isAvailable('member-7'));
        self::assertNull($svc->validate('member-7', 7));

        self::assertCount(1, $GLOBALS['__bcc_handle_changed']);
        self::assertSame([10, 'member-7', 'ten-person'], $GLOBALS['__bcc_handle_changed'][0]);
    }

    public function testAFailedHandleWriteIsSkippedAndRetriedWithoutDuplicates(): void
    {
        global $wpdb;
        self::user(1, 'jsmith77', 'Jane Smith', 'jsmith77');

        $GLOBALS['__bcc_fail_handle_write'] = true;
        self::assertSame(BCC_TRUST_MIGRATION_INCOMPLETE, bcc_trust_rotate_login_handles());
        self::assertSame([], $GLOBALS['__bcc_handle_changed'], 'no bcc_handle_changed for a failed write');
        self::assertSame('jsmith77', (new HandleService())->getHandle(1));

        $GLOBALS['__bcc_fail_handle_write'] = false;
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_rotate_login_handles());
        self::assertSame('jane-smith', (new HandleService())->getHandle(1));
        self::assertCount(1, $GLOBALS['__bcc_handle_changed']);
        self::assertSame('1', (string) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'bcc_handle_retired'"
        ), 'the retry must not write a second retired row');
    }

    public function testDisplayNameEqualToLoginIsRewrittenToTheNewHandle(): void
    {
        self::user(2, 'bob99', 'bob99', 'bob99');       // rotated first -> member-2
        self::user(6, 'erin', 'ERIN', 'erin-x');        // display = login (ci), handle differs
        self::user(7, 'frank', 'Frank Lee', 'frank');   // real name: untouched by v3

        bcc_trust_rotate_login_handles();
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_backfill_display_name_placeholders());

        global $wpdb;
        $names = [];
        foreach ($wpdb->get_results("SELECT ID, display_name FROM {$wpdb->users} ORDER BY ID") ?? [] as $r) {
            $names[(int) $r->ID] = (string) $r->display_name;
        }
        self::assertSame([2 => 'member-2', 6 => 'erin-x', 7 => 'Frank Lee'], $names);

        // Idempotent: nothing matches any more.
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_backfill_display_name_placeholders());
    }

    public function testDisplayNameFallsToMemberIdWhenTheHandleIsTheLogin(): void
    {
        // v3 alone (rotation not run): a handle equal to the login must
        // not become the display name, or the row would re-match forever.
        self::user(8, 'gina', 'gina', 'gina');

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_backfill_display_name_placeholders());

        global $wpdb;
        self::assertSame('Member 8', (string) $wpdb->get_var("SELECT display_name FROM {$wpdb->users} WHERE ID = 8"));
    }
}
