<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Handle assignment for accounts created outside BCC signup must never
 * derive the public handle from user_login.
 *
 * - bcc_trust_bch_derive_base(): display_name slug, but NOT when the
 *   display name is just the login (WordPress's default for wp-admin /
 *   WP-CLI accounts); otherwise 'member-{id}'. The old login step is gone.
 * - bcc_trust_assign_handle_if_missing(): the user_register callback.
 *   Leaves an existing handle alone (BCC signup writes one via
 *   meta_input before user_register fires) and assigns one otherwise.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CanonicalHandleNoLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!\function_exists('get_user_meta')) {
            eval(<<<'PHP'
                function get_user_meta(int $userId, string $key = '', bool $single = false)
                {
                    return $GLOBALS['__bcc_ch_meta'][$userId][$key] ?? '';
                }
                PHP);
        }
        require_once __DIR__ . '/../Stubs/canonical-backfill-stubs.php';
        require_once __DIR__ . '/../../includes/database/backfill-canonical-handles.php';
        $GLOBALS['__bcc_ch_validate']  = null;
        $GLOBALS['__bcc_ch_available'] = null;
        $GLOBALS['__bcc_ch_assigned']  = [];
        $GLOBALS['__bcc_ch_meta']      = [];
    }

    public function testRealDisplayNameIsUsed(): void
    {
        self::assertSame('alice-smith', bcc_trust_bch_derive_base('Alice Smith', 'jsmith77', 5));
    }

    public function testDisplayNameEqualToLoginFallsToMemberId(): void
    {
        self::assertSame('member-5', bcc_trust_bch_derive_base('jsmith77', 'jsmith77', 5));
        self::assertSame('member-5', bcc_trust_bch_derive_base('JSmith77', 'jsmith77', 5));
    }

    public function testShortDisplayNameNeverFallsBackToLogin(): void
    {
        // Pre-fix this returned 'jsmith77' (login-derived).
        self::assertSame('member-5', bcc_trust_bch_derive_base('Al', 'jsmith77', 5));
        self::assertSame('member-5', bcc_trust_bch_derive_base('', 'jsmith77', 5));
        self::assertSame('member-5', bcc_trust_bch_derive_base('', 'u_jsmith77', 5));
    }

    public function testRegisterHookAssignsAHandleWhenMissing(): void
    {
        // The stub get_userdata() returns display_name 'Member 42'.
        self::assertSame('member-42', bcc_trust_assign_handle_if_missing(42));
        self::assertSame(['42' => 'member-42'], array_map('strval', $GLOBALS['__bcc_ch_assigned']));
    }

    public function testRegisterHookLeavesAnExistingHandleAlone(): void
    {
        $GLOBALS['__bcc_ch_meta'][42]['bcc_handle'] = 'chosen-at-signup';

        self::assertNull(bcc_trust_assign_handle_if_missing(42));
        self::assertSame([], $GLOBALS['__bcc_ch_assigned']);
    }

    public function testRegisterHookIgnoresANonPositiveId(): void
    {
        self::assertNull(bcc_trust_assign_handle_if_missing(0));
        self::assertSame([], $GLOBALS['__bcc_ch_assigned']);
    }
}
