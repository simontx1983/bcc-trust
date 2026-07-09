<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\UserLifecycleService;
use BCC\Trust\Core\Services\UserSyncService;
use BCC\Trust\Core\Support\JwtToken;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WP_User;

/**
 * Audit H-2 residual — password changes made OUTSIDE the BCC REST endpoints
 * (wp-admin user edit, PeepSo reset/profile) must still revoke bearer JWTs.
 *
 * UserLifecycleService listens on WP's `after_password_reset` (reset flow,
 * incl. PeepSo's reset shortcode) and `profile_update` (diffing the stored
 * hash so only a real change bumps the token version), both bumping
 * bcc_token_version via JwtToken::revokeAllForUser().
 *
 * ## Isolation
 * Own subprocess; setUp() pulls in tests/Stubs/password-change-stubs.php
 * (WP_User + get_userdata + user-meta backing store), so the real
 * JwtToken::revokeAllForUser() runs and its counter bump is observable.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PasswordChangeRevokesTokensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/password-change-stubs.php';
        $GLOBALS['__bcc_token_versions'] = [];
        $GLOBALS['__bcc_pw_fresh_user']  = null;
    }

    /** UserLifecycleService with a no-op sync service injected. */
    private function service(): UserLifecycleService
    {
        $svc  = (new ReflectionClass(UserLifecycleService::class))->newInstanceWithoutConstructor();
        $sync = new class extends UserSyncService {
            public function __construct()
            {
            }
            public function sync(?int $userId = null): int
            {
                return 0;
            }
        };
        $prop = (new ReflectionClass(UserLifecycleService::class))->getProperty('syncService');
        $prop->setAccessible(true);
        $prop->setValue($svc, $sync);

        return $svc;
    }

    private function user(int $id, string $passHash): WP_User
    {
        $u            = new WP_User();
        $u->ID        = $id;
        $u->user_pass = $passHash;
        return $u;
    }

    private function tokenVersion(int $userId): int
    {
        return (int) ($GLOBALS['__bcc_token_versions'][$userId][JwtToken::TOKEN_VERSION_META_KEY] ?? 0);
    }

    public function testPasswordResetRevokesTokens(): void
    {
        $this->service()->onPasswordReset($this->user(7, 'new-hash'));
        self::assertSame(1, $this->tokenVersion(7), 'after_password_reset must bump the token version');
    }

    public function testPasswordResetIgnoresNonUser(): void
    {
        $this->service()->onPasswordReset(null);
        self::assertSame(0, $this->tokenVersion(7), 'a non-WP_User arg must be a no-op');
    }

    public function testProfileUpdateRevokesWhenPasswordChanged(): void
    {
        // Post-save hash differs from the pre-update snapshot → revoke.
        $GLOBALS['__bcc_pw_fresh_user'] = $this->user(7, 'hash-AFTER');
        $this->service()->onProfileUpdate(7, $this->user(7, 'hash-BEFORE'));
        self::assertSame(1, $this->tokenVersion(7), 'a real password change must bump the token version');
    }

    public function testProfileUpdateDoesNotRevokeWhenPasswordUnchanged(): void
    {
        // Same hash before and after → a non-password profile edit → no bump.
        $GLOBALS['__bcc_pw_fresh_user'] = $this->user(7, 'same-hash');
        $this->service()->onProfileUpdate(7, $this->user(7, 'same-hash'));
        self::assertSame(0, $this->tokenVersion(7), 'a non-password profile edit must NOT log the user out');
    }

    public function testProfileUpdateWithoutOldDataDoesNotRevoke(): void
    {
        // No pre-update snapshot supplied → cannot diff → no bump.
        $GLOBALS['__bcc_pw_fresh_user'] = $this->user(7, 'whatever');
        $this->service()->onProfileUpdate(7, null);
        self::assertSame(0, $this->tokenVersion(7));
    }
}
