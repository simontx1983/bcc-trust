<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

/**
 * F-312 (M7, M8): two wp-admin › Moderation flows that ended on the wrong
 * page. Pinned in source, comments removed, because both live in procedural
 * admin templates that render inside WordPress's admin chrome.
 *
 *   M7 "Sync All Users" ran its redirect from INSIDE the page render, after
 *      the admin header was printed: the headers could not be sent and `exit`
 *      cut the page off with no "Synced N users". It now runs on admin_init.
 *   M8 Whitelisting from an IP or Device investigation posted to the bare
 *      moderation URL, and the router reads `ip` / `fingerprint` from $_GET,
 *      so the admin landed on the Users list. The forms now keep them.
 */
final class ModerationPagesRoutingTest extends TestCase
{
    public function testTheUserListRenderNoLongerRedirects(): void
    {
        $render = GateOrder::body(GateOrder::code('includes/admin/moderation-list.php'), 'bcc_trust_render_user_list');
        self::assertStringNotContainsString('wp_safe_redirect', $render, 'a redirect after output cannot send its headers');
        self::assertStringNotContainsString('userSyncService()->sync()', $render);
    }

    public function testTheSyncRunsOnAdminInitWithItsGates(): void
    {
        $code = GateOrder::code('includes/admin/moderation.php');
        self::assertStringContainsString("add_action( 'admin_init', 'bcc_trust_handle_user_sync' )", $code);

        $handler = GateOrder::body($code, 'bcc_trust_handle_user_sync');
        GateOrder::assertOrder(
            $handler,
            ["current_user_can( 'manage_options' )", "'bcc_sync_users'", 'userSyncService()->sync()', 'wp_safe_redirect', 'exit'],
            'bcc_trust_handle_user_sync'
        );
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function investigationPages(): iterable
    {
        yield 'ip' => ['includes/admin/moderation-ip.php', "'ip' => rawurlencode( \$ip )"];
        yield 'device' => ['includes/admin/moderation-device.php', "'fingerprint' => rawurlencode( \$fingerprint )"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('investigationPages')]
    public function testWhitelistFormsReturnToTheSameInvestigation(string $file, string $param): void
    {
        $code = GateOrder::code($file);
        self::assertSame(2, substr_count($code, $param), 'both the whitelist and the un-whitelist form keep the investigation');
        self::assertStringNotContainsString(
            "action=\"<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation' ) ); ?>\"",
            $code,
            'no whitelist form posts to the bare moderation URL'
        );
    }
}
