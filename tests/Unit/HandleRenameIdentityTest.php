<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\PasswordAuthController;
use BCC\Trust\Core\REST\OnboardingEndpoint;
use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Support\UserSlugResolver;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-280: a handle rename kept the account reachable only by its OLD handle.
 *
 * The login is minted once, at signup, as u_<handle>, and sign-in by handle
 * looked up that login. So after a rename the new handle could not sign in,
 * the old /u/<old> links went 404 (nothing recorded the old handle), and the
 * old handle showed as available while signup (same login) answered 409.
 *
 * Pinned here, against the REAL PasswordAuthController::login,
 * OnboardingEndpoint::updateHandle, HandleService and UserSlugResolver:
 *   - the new handle signs in, the old one does not (a retired handle is a
 *     link alias, never a credential), and a handle two members hold signs
 *     in nobody;
 *   - a rename retires the old handle: its links resolve, and no OTHER
 *     member may take it, while its owner may reclaim it (and the retired
 *     row goes, so links resolve directly again), except one equal to the
 *     login (#540's rotation retired those so the login stays hidden);
 *   - a pre-fix rename (no retired row) still reports the original handle
 *     taken, matching what signup will do, and never signs into the
 *     renamed account through its login.
 *
 * "Signed in as X" is observed through the email-verification gate: an
 * account flagged unverified ('0') answers 403 bcc_email_not_verified once
 * its password has matched, so 403 = this account was resolved and its
 * password accepted; 401 = no account / wrong password.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HandleRenameIdentityTest extends TestCase
{
    private const ALICE = 10;
    private const CAROL = 20;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'        => [],
            'meta'         => [],
            'passwords'    => [],
            'transients'   => [],
            'mail'         => [],
            'actions'      => [],
            'current_user' => 0,
        ];
        self::member(self::ALICE, 'alice');
        self::member(self::CAROL, 'carol');
    }

    /** A member who signed up as $handle (login u_<handle>), unverified so sign-in stops at 403. */
    private static function member(int $id, string $handle, ?string $login = null): void
    {
        $GLOBALS['__bcc_ph']['users'][$id] = [
            'email'   => $handle . '@example.test',
            'login'   => $login ?? AuthSupport::deriveLogin($handle),
            'display' => ucfirst($handle),
        ];
        $GLOBALS['__bcc_ph']['meta'][$id] = [
            HandleService::META_HANDLE       => $handle,
            AuthSupport::META_EMAIL_VERIFIED => '0',
        ];
        $GLOBALS['__bcc_ph']['passwords'][$id] = 'pw-' . $id;
    }

    private static function signIn(string $identifier, int $passwordOf): int
    {
        $req = new \WP_REST_Request(['identifier' => $identifier, 'password' => 'pw-' . $passwordOf]);
        return (new PasswordAuthController())->login($req)->get_status();
    }

    private static function rename(int $userId, string $handle): \WP_REST_Response
    {
        $GLOBALS['__bcc_ph']['current_user'] = $userId;
        // The 7-day cooldown is not what these cases exercise.
        unset($GLOBALS['__bcc_ph']['meta'][$userId][HandleService::META_HANDLE_LAST_CHANGED]);
        $endpoint = (new \ReflectionClass(OnboardingEndpoint::class))->newInstanceWithoutConstructor();
        return $endpoint->updateHandle(new \WP_REST_Request(['handle' => $handle]));
    }

    /** @return list<string> */
    private static function retired(int $userId): array
    {
        return (array) get_user_meta($userId, HandleService::META_HANDLE_RETIRED, false);
    }

    private static function handleOf(int $userId): string
    {
        return (string) get_user_meta($userId, HandleService::META_HANDLE, true);
    }

    public function testTheNewHandleSignsInAfterARename(): void
    {
        self::assertSame(200, self::rename(self::ALICE, 'bob')->get_status());

        self::assertSame(403, self::signIn('bob', self::ALICE), 'the new handle reached the account');
        self::assertSame(403, self::signIn('@Bob', self::ALICE), 'case and a leading @ are tolerated');
    }

    public function testTheOldHandleNeverSignsIn(): void
    {
        self::rename(self::ALICE, 'bob');

        self::assertSame(401, self::signIn('alice', self::ALICE), 'a retired handle is a link alias, not a credential');
    }

    public function testARenameRetiresTheOldHandleAndItsLinksStillResolve(): void
    {
        self::rename(self::ALICE, 'bob');

        self::assertSame(['alice'], self::retired(self::ALICE));
        self::assertSame(self::ALICE, UserSlugResolver::resolve('alice'), 'old /u/alice links and @alice mentions');
        self::assertSame(self::ALICE, UserSlugResolver::resolve('bob'));
    }

    public function testNoOtherMemberMayTakeARetiredHandle(): void
    {
        self::rename(self::ALICE, 'bob');

        self::assertSame(409, self::rename(self::CAROL, 'alice')->get_status());
        self::assertFalse((new HandleService())->isAvailable('alice'), 'signup sees it taken too');
        self::assertSame('carol', self::handleOf(self::CAROL));
    }

    public function testTheOwnerMayReclaimTheirRetiredHandle(): void
    {
        self::rename(self::ALICE, 'bob');

        self::assertSame(200, self::rename(self::ALICE, 'alice')->get_status());

        self::assertSame('alice', self::handleOf(self::ALICE));
        self::assertSame(['bob'], self::retired(self::ALICE), 'the reclaimed handle is no longer retired');
        self::assertSame(403, self::signIn('alice', self::ALICE));
        self::assertSame(401, self::signIn('bob', self::ALICE));
        self::assertSame(self::ALICE, UserSlugResolver::resolve('bob'));
    }

    public function testRenamingBackAndForthDoesNotPileUpRetiredRows(): void
    {
        self::rename(self::ALICE, 'bob');
        self::rename(self::ALICE, 'alice');
        self::rename(self::ALICE, 'bob');
        self::rename(self::ALICE, 'alice');

        self::assertSame(['bob'], self::retired(self::ALICE));
    }

    public function testARetryAfterAFailedHandleWriteDoesNotRetireTwice(): void
    {
        // The state a rename leaves when the retired row landed but the
        // bcc_handle write did not: still 'alice', and 'alice' already retired.
        add_user_meta(self::ALICE, HandleService::META_HANDLE_RETIRED, 'alice');

        self::assertSame(200, self::rename(self::ALICE, 'bob')->get_status());

        self::assertSame(['alice'], self::retired(self::ALICE));
    }

    public function testALoginEqualRetiredHandleStaysLockedForItsOwner(): void
    {
        // #540's rotation: a legacy member whose handle WAS their login got
        // a new handle, and the old one was retired so the login is never
        // on show again — not even by its owner.
        self::member(30, 'member-30', 'dave');
        add_user_meta(30, HandleService::META_HANDLE_RETIRED, 'dave');

        self::assertFalse((new HandleService())->isAvailable('dave', 30));
        self::assertSame(409, self::rename(30, 'dave')->get_status());
    }

    public function testAFailedRetireWriteLeavesTheHandleUnchanged(): void
    {
        $GLOBALS['__bcc_ph']['fail_add_meta'] = true;

        self::assertSame(500, self::rename(self::ALICE, 'bob')->get_status());

        self::assertSame('alice', self::handleOf(self::ALICE), 'no rename that would orphan alice');
    }

    public function testAPreFixRenameReportsTheOriginalHandleTakenLikeSignupWill(): void
    {
        // Renamed before F-280: login u_alice, handle bob, nothing retired.
        $GLOBALS['__bcc_ph']['meta'][self::ALICE][HandleService::META_HANDLE] = 'bob';

        $svc = new HandleService();
        self::assertFalse($svc->isAvailable('alice'), 'signup would mint u_alice and 409');
        self::assertFalse($svc->isAvailable('alice', self::CAROL), 'nor may another member rename to it');
        self::assertTrue($svc->isAvailable('alice', self::ALICE), 'its owner may take it back');
    }

    public function testATakenOldHandleNeverSignsIntoTheRenamedAccount(): void
    {
        // Pre-fix: alice renamed to bob, then carol renamed to alice. The old
        // sign-in derived u_alice and reached ALICE's account.
        $GLOBALS['__bcc_ph']['meta'][self::ALICE][HandleService::META_HANDLE] = 'bob';
        $GLOBALS['__bcc_ph']['meta'][self::CAROL][HandleService::META_HANDLE] = 'alice';

        self::assertSame(401, self::signIn('alice', self::ALICE), 'alice is carol now');
        self::assertSame(403, self::signIn('alice', self::CAROL));
    }

    public function testAHandleTwoMembersHoldSignsInNobody(): void
    {
        $GLOBALS['__bcc_ph']['meta'][self::CAROL][HandleService::META_HANDLE] = 'alice';

        self::assertSame(401, self::signIn('alice', self::ALICE));
        self::assertSame(401, self::signIn('alice', self::CAROL));
        self::assertNull(HandleService::currentOwner('alice'));
    }

    public function testEmailSignInIsUnchanged(): void
    {
        self::rename(self::ALICE, 'bob');

        self::assertSame(403, self::signIn('alice@example.test', self::ALICE));
    }

    // ── F-333: the cooldown is readable before and at submit ────────────

    public function testNextChangeAtIsNullWithNoCooldownAndTheDateWithOne(): void
    {
        $svc = new HandleService();
        self::assertNull($svc->nextChangeAt(self::ALICE), 'never renamed: can change now');

        $changed = time() - 3600;
        $GLOBALS['__bcc_ph']['meta'][self::ALICE][HandleService::META_HANDLE_LAST_CHANGED] = (string) $changed;

        self::assertSame(
            gmdate('Y-m-d\TH:i:s\Z', $changed + HandleService::COOLDOWN_SECONDS),
            $svc->nextChangeAt(self::ALICE)
        );
    }

    public function testTheCooldown429CarriesNextChangeAtInItsBody(): void
    {
        $changed = time() - 3600;
        $GLOBALS['__bcc_ph']['current_user'] = self::ALICE;
        $GLOBALS['__bcc_ph']['meta'][self::ALICE][HandleService::META_HANDLE_LAST_CHANGED] = (string) $changed;
        $endpoint = (new \ReflectionClass(OnboardingEndpoint::class))->newInstanceWithoutConstructor();

        $resp = $endpoint->updateHandle(new \WP_REST_Request(['handle' => 'bob']));

        self::assertSame(429, $resp->get_status());
        $body = $resp->get_data();
        self::assertIsArray($body);
        self::assertSame(
            gmdate('Y-m-d\TH:i:s\Z', $changed + HandleService::COOLDOWN_SECONDS),
            $body['error']['data']['next_change_at'] ?? null,
            'the client error object does not surface Retry-After'
        );
    }

    public function testProfilePrefsReportsTheSameDate(): void
    {
        // readAll() needs the mentor service and PeepSo tables to run, so
        // pin that it reads the ONE formatter rather than a second copy.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/REST/MyProfilePrefsEndpoint.php');
        self::assertMatchesRegularExpression(
            "/'handle_next_change_at'\s*=>\s*\\\\BCC\\\\Trust\\\\Core\\\\Plugin::instance\(\)->handleService\(\)->nextChangeAt\(\\\$userId\)/",
            $src
        );
    }
}
