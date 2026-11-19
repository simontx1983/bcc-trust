<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\EmailVerificationController;
use BCC\Trust\Core\REST\Auth\WalletAuthController;
use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Services\PendingEmailService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-294 — a wallet sign-up's typed address no longer squats user_email.
 *
 * It used to go straight into user_email, unverified. user_email is UNIQUE,
 * so whoever typed an address first reserved it against its real owner, and
 * F-285's reclaim never touches an account with a wallet. Now user_email
 * keeps the wallet placeholder and the typed address is held PENDING
 * (PendingEmailService::META_PENDING_SIGNUP_EMAIL) until the inbox is
 * proven. Pinned against the REAL walletSignup, verifyEmail,
 * resendVerification and PendingEmailService:
 *   - sign-up writes the placeholder, holds the address pending and mails
 *     the code to it; a pending address is not a "taken" address;
 *   - the code (by that address) or the link applies it, marks it verified,
 *     and core's "Email Changed" mail (to the dead placeholder) stays off;
 *   - taken meanwhile → 409 and the pending address is dropped; a failed
 *     write keeps it for a retry;
 *   - several sign-ups holding it → the code is checked against each
 *     (oldest first, at most five), each under its own attempt cap, and
 *     verifies only the account it was sent for; every comparison also
 *     counts against one budget for the address (five per code lifetime,
 *     not re-armed by a resend); a resend mails each its own code; the
 *     account whose email it is still wins;
 *   - resend by that address or by handle mails the pending address;
 *   - confirming a recovery email clears it.
 *
 * ## Isolation
 * Subprocess-only; setUp() loads tests/Stubs/pending-email-stubs.php,
 * wallet-signup-stubs.php, wallet-login-throttle-stubs.php, then
 * account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WalletSignupPendingEmailTest extends TestCase
{
    private const ADDR  = '0xabcdef0000000000000000000000000000000001';
    private const TYPED = 'me@example.com';
    private const NEW   = 500; // the wallet-signup-stubs insert id
    private const OTHER = 3;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/pending-email-stubs.php';
        require_once __DIR__ . '/../Stubs/wallet-signup-stubs.php';
        require_once __DIR__ . '/../Stubs/wallet-login-throttle-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users' => [], 'meta' => [], 'transients' => [], 'mail' => [], 'actions' => [], 'options' => [],
            'audit' => [], 'canary' => [], 'core_email_notice' => [],
        ];
        $GLOBALS['__bcc_wl'] = [
            'challenges'  => [self::ADDR => [['chain_id' => 8, 'message' => 'msg-base']]],
            'valid'       => ['evm|msg-base|good-sig'],
            'consumed'    => [],
            'verified'    => [],
            'insert_ok'   => true,
            'link_result' => 900,
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            /** @return null no wallet link, no other owner */
            public function get_var(string $sql)
            {
                return null;
            }

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('unexpected $wpdb->' . $name);
            }
        };
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @param array<string, string> $extra */
    private static function signup(array $extra = ['email' => self::TYPED]): \WP_REST_Response
    {
        $req = new \WP_REST_Request(['wallet_address' => self::ADDR, 'signature' => 'good-sig', 'handle' => 'newhandle'] + $extra);
        return (new WalletAuthController())->walletSignup($req);
    }

    /** A wallet sign-up like the real one leaves it: placeholder in user_email, address pending. */
    private static function pendingAccount(int $id, string $pending): void
    {
        $GLOBALS['__bcc_ph']['users'][$id] = [
            'email' => 'wallet-' . str_repeat((string) ($id % 10), 16) . '@' . AccountRecoveryService::PLACEHOLDER_EMAIL_DOMAIN,
            'login' => 'u' . $id, 'display' => 'User ' . $id,
        ];
        $GLOBALS['__bcc_ph']['meta'][$id] = [
            'bcc_handle'                                    => 'handle' . $id,
            AuthSupport::META_EMAIL_VERIFIED                => '0',
            PendingEmailService::META_PENDING_SIGNUP_EMAIL => $pending,
        ];
    }

    private static function email(int $id): string
    {
        return $GLOBALS['__bcc_ph']['users'][$id]['email'];
    }

    private static function pending(int $id): ?string
    {
        $v = $GLOBALS['__bcc_ph']['meta'][$id][PendingEmailService::META_PENDING_SIGNUP_EMAIL] ?? null;
        return $v === null ? null : (string) $v;
    }

    private static function flag(int $id): string
    {
        return (string) ($GLOBALS['__bcc_ph']['meta'][$id][AuthSupport::META_EMAIL_VERIFIED] ?? '');
    }

    /** @return list<array{to: string, subject: string, html: string}> */
    private static function mailTo(string $to): array
    {
        return array_values(array_filter($GLOBALS['__bcc_ph']['mail'], static fn(array $m): bool => $m['to'] === $to));
    }

    private static function lastCodeTo(string $to): string
    {
        $mails = self::mailTo($to);
        self::assertNotEmpty($mails, 'a code was mailed to ' . $to);
        self::assertSame(1, preg_match('/>\s*(\d{6})\s*</', end($mails)['html'], $m));
        return $m[1];
    }

    /** The verify-link token stored for $userId (the one the mail links to). */
    private static function tokenFor(int $userId): string
    {
        foreach ($GLOBALS['__bcc_ph']['transients'] as $key => $value) {
            if (str_starts_with((string) $key, 'bcc_vt_') && explode(':', (string) $value)[0] === (string) $userId) {
                return substr((string) $key, strlen('bcc_vt_'));
            }
        }
        self::fail('no verify token stored for user ' . $userId);
    }

    /** @param array<string, string> $params */
    private static function verify(array $params): \WP_REST_Response
    {
        return (new EmailVerificationController())->verifyEmail(new \WP_REST_Request($params));
    }

    /** @param array<string, string> $params */
    private static function resend(array $params): int
    {
        return (new EmailVerificationController())->resendVerification(new \WP_REST_Request($params))->get_status();
    }

    private static function errorCode(\WP_REST_Response $res): ?string
    {
        $data = $res->get_data();
        return is_array($data) ? ($data['error']['code'] ?? null) : null;
    }

    // ── sign-up ─────────────────────────────────────────────────────────

    public function testSignupKeepsThePlaceholderAndHoldsTheTypedAddressPending(): void
    {
        self::assertSame(201, self::signup()->get_status());

        $inserted = $GLOBALS['__bcc_wl']['inserted_email'][0] ?? '';
        self::assertTrue(AccountRecoveryService::isPlaceholderEmail($inserted), 'user_email is the wallet placeholder');
        self::assertSame($inserted, self::email(self::NEW));
        self::assertSame(self::TYPED, self::pending(self::NEW), 'the typed address is held pending');
        self::assertSame('0', self::flag(self::NEW));
        self::assertCount(1, self::mailTo(self::TYPED), 'the code and link go to the typed address');
        self::assertCount(1, $GLOBALS['__bcc_ph']['mail']);
        self::assertSame([], $GLOBALS['__bcc_ph']['auth_cookies'] ?? [], 'a bearer token only: no WordPress auth cookie (H3)');
    }

    public function testSignupWithoutAnAddressHoldsNothingPendingAndMailsNothing(): void
    {
        self::assertSame(201, self::signup([])->get_status());

        self::assertTrue(AccountRecoveryService::isPlaceholderEmail(self::email(self::NEW)));
        self::assertNull(self::pending(self::NEW));
        self::assertSame([], $GLOBALS['__bcc_ph']['mail']);
    }

    public function testAnAddressAnotherSignupHoldsPendingIsNotTaken(): void
    {
        self::pendingAccount(self::OTHER, self::TYPED);

        self::assertSame(201, self::signup()->get_status(), 'pending is not a reservation');
        self::assertSame(self::TYPED, self::pending(self::NEW));
    }

    // ── verify ──────────────────────────────────────────────────────────

    public function testTheCodeByThatAddressAppliesIt(): void
    {
        self::signup();

        $res = self::verify(['email' => self::TYPED, 'code' => self::lastCodeTo(self::TYPED)]);

        self::assertSame(200, $res->get_status());
        self::assertSame(self::TYPED, self::email(self::NEW), 'now the account address');
        self::assertNull(self::pending(self::NEW));
        self::assertSame('1', self::flag(self::NEW));
        self::assertSame([false], $GLOBALS['__bcc_ph']['core_email_notice'], "core's mail to the placeholder is off");
    }

    public function testTheLinkAppliesIt(): void
    {
        self::signup();

        $res = self::verify(['token' => self::tokenFor(self::NEW)]);

        self::assertSame(200, $res->get_status());
        self::assertSame(self::TYPED, self::email(self::NEW));
        self::assertNull(self::pending(self::NEW));
        self::assertSame('1', self::flag(self::NEW));
    }

    public function testAnAddressTakenMeanwhileIs409AndDropsThePending(): void
    {
        self::signup();
        $placeholder = self::email(self::NEW);
        $token       = self::tokenFor(self::NEW);
        $code        = self::lastCodeTo(self::TYPED);
        // Its real owner signs up with a password before the link is used.
        $GLOBALS['__bcc_ph']['users'][self::OTHER] = ['email' => self::TYPED, 'login' => 'u3', 'display' => 'Three'];

        $byCode = self::verify(['email' => self::TYPED, 'code' => $code]);
        self::assertSame(400, $byCode->get_status(), 'the address is the owner\'s now: the squatter\'s code does not match it');
        self::assertSame('bcc_invalid_otp', self::errorCode($byCode));

        $res = self::verify(['token' => $token]);

        self::assertSame(409, $res->get_status());
        self::assertSame('bcc_conflict', self::errorCode($res));
        self::assertSame('An account with that email already exists.', $res->get_data()['error']['message'] ?? null);
        self::assertSame($placeholder, self::email(self::NEW), 'nothing applied');
        self::assertNull(self::pending(self::NEW), 'the pending address is dropped');
        self::assertSame('0', self::flag(self::NEW), 'not verified');
    }

    public function testAFailedWriteKeepsThePendingForARetry(): void
    {
        self::signup();
        $placeholder = self::email(self::NEW);
        $GLOBALS['__bcc_ph']['update_fails'] = true;

        $res = self::verify(['token' => self::tokenFor(self::NEW)]);

        self::assertSame(500, $res->get_status());
        self::assertSame('bcc_internal_error', self::errorCode($res));
        self::assertSame($placeholder, self::email(self::NEW));
        self::assertSame(self::TYPED, self::pending(self::NEW), 'kept for a retry');
        self::assertSame('0', self::flag(self::NEW));
    }

    // ── several sign-ups holding one address ────────────────────────────

    /**
     * An older wallet sign-up (OTHER) and a newer one (NEW) both typed the
     * address; each was mailed its own code. Returns [OTHER's, NEW's].
     *
     * @return array{0: string, 1: string}
     */
    private static function twoHolders(): array
    {
        self::pendingAccount(self::OTHER, self::TYPED);
        AuthSupport::beginEmailVerification(self::OTHER, self::TYPED);
        $other = self::lastCodeTo(self::TYPED);
        self::signup();
        $new = self::lastCodeTo(self::TYPED);
        self::assertNotSame($other, $new, 'precondition: two different codes');
        return [$other, $new];
    }

    private static function assertStillPending(int $id): void
    {
        self::assertStringEndsWith('@' . AccountRecoveryService::PLACEHOLDER_EMAIL_DOMAIN, self::email($id), "{$id} keeps its placeholder");
        self::assertSame(self::TYPED, self::pending($id), "{$id} still holds it pending");
        self::assertSame('0', self::flag($id), "{$id} is not verified");
    }

    /** A code burned by the attempt cap, as exhaustOtp() leaves it. */
    private static function burn(int $id): void
    {
        AuthSupport::burnEmailOtp($id);
        $GLOBALS['__bcc_ph']['transients']['bcc_otp_exhausted_' . $id] = '1';
    }

    private static function wrongCode(string ...$codes): string
    {
        for ($n = 100000; ; $n++) {
            if (!in_array((string) $n, $codes, true)) {
                return (string) $n;
            }
        }
    }

    public function testWithTwoHoldersTheNewerOnesCodeVerifiesOnlyTheNewerOne(): void
    {
        [, $new] = self::twoHolders();

        $res = self::verify(['email' => self::TYPED, 'code' => $new]);

        self::assertSame(200, $res->get_status(), 'the code proves the inbox for the account it was sent for');
        self::assertSame(self::TYPED, self::email(self::NEW));
        self::assertSame('1', self::flag(self::NEW));
        self::assertStillPending(self::OTHER);
    }

    public function testWithTwoHoldersTheOlderOnesCodeVerifiesOnlyTheOlderOne(): void
    {
        [$other] = self::twoHolders();

        $res = self::verify(['email' => self::TYPED, 'code' => $other]);

        self::assertSame(200, $res->get_status());
        self::assertSame(self::TYPED, self::email(self::OTHER));
        self::assertSame('1', self::flag(self::OTHER));
        self::assertStillPending(self::NEW);
    }

    public function testAWrongCodeIsGenericAndCountsOnceAgainstEveryHolder(): void
    {
        [$other, $new] = self::twoHolders();
        $GLOBALS['__bcc_ph']['throttle_counting'] = true;

        $res = self::verify(['email' => self::TYPED, 'code' => self::wrongCode($other, $new)]);

        self::assertSame(400, $res->get_status());
        self::assertSame('bcc_invalid_otp', self::errorCode($res));
        foreach ([self::OTHER, self::NEW] as $id) {
            $fails = array_filter(
                $GLOBALS['__bcc_ph']['throttle_hits'],
                static fn(int $hits, string $bucket): bool => str_starts_with($bucket, "verify_email_fail:{$id}:") && $hits === 1,
                ARRAY_FILTER_USE_BOTH
            );
            self::assertCount(1, $fails, "one failure on {$id}'s own code");
        }
        self::assertStillPending(self::OTHER);
        self::assertStillPending(self::NEW);
    }

    private static function addressBudgetKey(): string
    {
        return AuthSupport::emailThrottleKey('verify_email_addr', self::TYPED);
    }

    /** How many comparisons $id's own attempt bucket (for its current code) has counted. */
    private static function attemptsOn(int $id): int
    {
        $otpId = AuthSupport::emailOtpIdentity($id);
        self::assertNotNull($otpId, "{$id} has a pending code");
        return (int) ($GLOBALS['__bcc_ph']['throttle_hits']['verify_email_attempt:' . $id . ':' . $otpId] ?? 0);
    }

    public function testTheAddressAllowsFiveComparisonsAcrossAllItsHoldersWhicheverHolder(): void
    {
        [$other, $new] = self::twoHolders();
        $GLOBALS['__bcc_ph']['throttle_counting'] = true;
        $wrong = self::wrongCode($other, $new);

        // Two wrong guesses = four comparisons.
        self::assertSame('bcc_invalid_otp', self::errorCode(self::verify(['email' => self::TYPED, 'code' => $wrong])));
        self::assertSame('bcc_invalid_otp', self::errorCode(self::verify(['email' => self::TYPED, 'code' => $wrong])));

        // NEW's right code: OTHER (older) is the fifth comparison, NEW would be the sixth.
        $res = self::verify(['email' => self::TYPED, 'code' => $new]);
        self::assertSame('bcc_otp_exhausted', self::errorCode($res), 'the sixth comparison is refused, whichever holder it is for');
        self::assertStillPending(self::NEW);
        self::assertSame(3, self::attemptsOn(self::OTHER), 'OTHER was compared all three times');
        self::assertSame(2, self::attemptsOn(self::NEW), 'NEW was not compared, or charged, the third time');
        self::assertSame(6, $GLOBALS['__bcc_ph']['throttle_hits'][self::addressBudgetKey()], 'a refused comparison still counts');

        // The link names its account and is not affected.
        self::assertSame(200, self::verify(['token' => self::tokenFor(self::NEW)])->get_status());
        self::assertSame(self::TYPED, self::email(self::NEW));
    }

    public function testWithFiveHoldersOneWrongGuessSpendsTheAddressesBudget(): void
    {
        $codes = [];
        foreach ([10, 11, 12, 13, 14] as $id) {
            self::pendingAccount($id, self::TYPED);
            AuthSupport::beginEmailVerification($id, self::TYPED);
            $codes[$id] = self::lastCodeTo(self::TYPED);
        }
        $GLOBALS['__bcc_ph']['throttle_counting'] = true;

        self::assertSame('bcc_invalid_otp', self::errorCode(self::verify(['email' => self::TYPED, 'code' => self::wrongCode(...array_values($codes))])), 'five comparisons');
        foreach ($codes as $id => $c) {
            self::assertSame(1, self::attemptsOn($id), "{$id} was compared once");
        }

        // The sixth comparison is refused whichever holder's code it is.
        foreach ([10, 14] as $id) {
            self::assertSame('bcc_otp_exhausted', self::errorCode(self::verify(['email' => self::TYPED, 'code' => $codes[$id]])), "{$id}'s right code");
            self::assertStillPending($id);
            self::assertSame(1, self::attemptsOn($id), "{$id} was not compared again");
        }

        // Once the window has passed, a holder's code works again.
        $GLOBALS['__bcc_ph']['throttle_hits'][self::addressBudgetKey()] = 0;
        self::assertSame(200, self::verify(['email' => self::TYPED, 'code' => $codes[14]])->get_status());
        self::assertSame(self::TYPED, self::email(14));
        self::assertStillPending(10);
    }

    public function testTheAddressesBudgetIgnoresLetterCase(): void
    {
        // The holders are found in any letter case (MySQL's collation), so
        // the budget must be one budget in any letter case too: otherwise
        // alternating the case would give a guesser a fresh one each time.
        [$other, $new] = self::twoHolders();
        $GLOBALS['__bcc_ph']['throttle_counting'] = true;
        $wrong = self::wrongCode($other, $new);

        self::assertSame('bcc_invalid_otp', self::errorCode(self::verify(['email' => self::TYPED, 'code' => $wrong])));
        self::assertSame('bcc_invalid_otp', self::errorCode(self::verify(['email' => strtoupper(self::TYPED), 'code' => $wrong])), 'found in upper case too');

        $res = self::verify(['email' => ucfirst(self::TYPED), 'code' => $new]);
        self::assertSame('bcc_otp_exhausted', self::errorCode($res), 'the same budget, whatever the case');
        self::assertStillPending(self::NEW);
    }

    public function testAResendDoesNotReArmTheAddressesBudget(): void
    {
        [$other, $new] = self::twoHolders();
        $GLOBALS['__bcc_ph']['throttle_counting'] = true;
        $wrong = self::wrongCode($other, $new);
        for ($i = 0; $i < 3; $i++) {
            self::verify(['email' => self::TYPED, 'code' => $wrong]);
        }

        self::assertSame(200, self::resend(['email' => self::TYPED]));
        $fresh = self::lastCodeTo(self::TYPED); // NEW's fresh code (oldest first, so NEW's is last)
        self::assertNotSame($new, $fresh);

        self::assertSame('bcc_otp_exhausted', self::errorCode(self::verify(['email' => self::TYPED, 'code' => $fresh])), 'still spent');
        self::assertStillPending(self::NEW);
        self::assertSame(200, self::verify(['token' => self::tokenFor(self::NEW)])->get_status(), 'the new mail\'s link works at once');
    }

    /**
     * #748: a holder whose user_email is no longer the wallet placeholder
     * may not have its stale pending address applied. Its code, mailed to
     * its real address (its verification target now), still matches by the
     * pending address here, and completeVerification() must answer the
     * refusal instead of finalizing. The other holder is unaffected.
     */
    public function testAHolderWhoseEmailIsNoLongerThePlaceholderIsRefusedNotVerified(): void
    {
        [, $new] = self::twoHolders();
        $GLOBALS['__bcc_ph']['users'][self::OTHER]['email'] = 'real@example.net';
        AuthSupport::beginEmailVerification(self::OTHER, 'real@example.net');
        $otherCode = self::lastCodeTo('real@example.net');

        $res = self::verify(['email' => self::TYPED, 'code' => $otherCode]);

        self::assertSame(409, $res->get_status());
        self::assertSame('bcc_invalid_state', self::errorCode($res));
        self::assertSame('0', self::flag(self::OTHER), 'nothing finalized');
        self::assertSame('real@example.net', self::email(self::OTHER), 'the real address is never replaced');
        self::assertArrayNotHasKey(PendingEmailService::META_PENDING_SIGNUP_EMAIL, $GLOBALS['__bcc_ph']['meta'][self::OTHER], 'the stale pending address is dropped');

        // NEW, the remaining holder, still verifies with its own code.
        self::assertSame(200, self::verify(['email' => self::TYPED, 'code' => $new])->get_status());
        self::assertSame(self::TYPED, self::email(self::NEW));
        self::assertSame('1', self::flag(self::NEW));
    }

    public function testOneAccountIsNotChargedToAnAddressBudget(): void
    {
        $GLOBALS['__bcc_ph']['throttle_counting'] = true;

        // One wallet sign-up holding the address.
        self::signup();
        self::verify(['email' => self::TYPED, 'code' => self::wrongCode(self::lastCodeTo(self::TYPED))]);

        // The account whose email it is.
        $GLOBALS['__bcc_ph']['users'][self::OTHER] = ['email' => 'owner@example.com', 'login' => 'u3', 'display' => 'Three'];
        $GLOBALS['__bcc_ph']['meta'][self::OTHER]  = ['bcc_handle' => 'three', AuthSupport::META_EMAIL_VERIFIED => '0'];
        AuthSupport::beginEmailVerification(self::OTHER, 'owner@example.com');
        self::verify(['email' => 'owner@example.com', 'code' => self::wrongCode(self::lastCodeTo('owner@example.com'))]);

        $addressBuckets = array_filter(
            $GLOBALS['__bcc_ph']['throttle_keys'],
            static fn(string $k): bool => str_starts_with($k, 'bcc_throttle_verify_email_addr_')
        );
        self::assertSame([], array_values($addressBuckets), 'one account keeps its own per-code cap only');
    }

    public function testItSaysExhaustedOnlyWhenEveryHoldersCodeIsBurned(): void
    {
        [$other, $new] = self::twoHolders();
        self::burn(self::OTHER);

        $res = self::verify(['email' => self::TYPED, 'code' => self::wrongCode($other, $new)]);
        self::assertSame('bcc_invalid_otp', self::errorCode($res), "NEW's code is still live");

        self::assertSame(200, self::verify(['email' => self::TYPED, 'code' => $new])->get_status());
        self::assertSame(self::TYPED, self::email(self::NEW));
    }

    public function testAVerifiedHolderIsPassedOverNotAnswered(): void
    {
        [$other, $new] = self::twoHolders();
        $GLOBALS['__bcc_ph']['meta'][self::OTHER][AuthSupport::META_EMAIL_VERIFIED] = '1';

        $res = self::verify(['email' => self::TYPED, 'code' => self::wrongCode($other, $new)]);
        self::assertSame('bcc_invalid_otp', self::errorCode($res), 'not 409: nothing is pending for that one');

        // With NEW's code burned too, the only holder with a code is burned.
        self::burn(self::NEW);
        $res = self::verify(['email' => self::TYPED, 'code' => self::wrongCode($other, $new)]);
        self::assertSame('bcc_otp_exhausted', self::errorCode($res), 'a verified holder does not count as a live code');
    }

    public function testAResendMailsEveryHolderItsOwnFreshCode(): void
    {
        [$other, $new] = self::twoHolders();
        $before = count(self::mailTo(self::TYPED));

        self::assertSame(200, self::resend(['email' => self::TYPED]));

        $mails = self::mailTo(self::TYPED);
        self::assertCount($before + 2, $mails, 'one code for each holder');
        self::assertCount($before + 2, $GLOBALS['__bcc_ph']['mail']);
        self::assertSame('bcc_invalid_otp', self::errorCode(self::verify(['email' => self::TYPED, 'code' => $new])), 'the old codes are replaced');
        self::assertSame('bcc_invalid_otp', self::errorCode(self::verify(['email' => self::TYPED, 'code' => $other])));

        // Each mail names the account its code confirms (the handles are
        // unique; the display names are whatever each sign-up chose).
        $names = static fn(int $at): string => 'This code confirms the email for <strong>@' . ($at === $before ? 'handle' . self::OTHER : 'newhandle') . '</strong>';
        self::assertStringContainsString($names($before), $mails[$before]['html']);
        self::assertStringContainsString($names($before + 1), $mails[$before + 1]['html']);
        self::assertStringNotContainsString('@newhandle', $mails[$before]['html']);
        self::assertStringNotContainsString('@handle' . self::OTHER, $mails[$before + 1]['html']);

        // Oldest first: OTHER's fresh code, then NEW's.
        self::assertSame(1, preg_match('/>\s*(\d{6})\s*</', $mails[$before]['html'], $m));
        self::assertSame(200, self::verify(['email' => self::TYPED, 'code' => $m[1]])->get_status());
        self::assertSame(self::TYPED, self::email(self::OTHER));
        self::assertStillPending(self::NEW);
    }

    public function testOnlyTheOldestFiveHoldersAreLookedAtAndTheLinkStillWorksPastThem(): void
    {
        // Seeded out of id order: the oldest five are 10–14, whatever order
        // the rows come back in.
        foreach ([15, 12, 10, 14, 11, 13] as $id) {
            self::pendingAccount($id, self::TYPED);
        }
        self::assertSame(5, PendingEmailService::MAX_PENDING_SIGNUP_HOLDERS);

        self::assertSame(200, self::resend(['email' => self::TYPED]));

        self::assertCount(5, self::mailTo(self::TYPED));
        foreach ([10, 11, 12, 13, 14] as $id) {
            self::assertNotNull(AuthSupport::emailOtpIdentity($id), "{$id} was mailed a code");
        }
        self::assertNull(AuthSupport::emailOtpIdentity(15), 'the sixth was not');

        // The sixth's own code (from its sign-up) is not looked at; its link is.
        AuthSupport::beginEmailVerification(15, self::TYPED);
        self::assertSame('bcc_invalid_otp', self::errorCode(self::verify(['email' => self::TYPED, 'code' => self::lastCodeTo(self::TYPED)])));
        self::assertSame(200, self::verify(['token' => self::tokenFor(15)])->get_status());
        self::assertSame(self::TYPED, self::email(15));
    }

    public function testTheAccountWhoseEmailItIsStillWinsOverPendingHolders(): void
    {
        $GLOBALS['__bcc_ph']['users'][self::OTHER] = ['email' => self::TYPED, 'login' => 'u3', 'display' => 'Three'];
        $GLOBALS['__bcc_ph']['meta'][self::OTHER]  = ['bcc_handle' => 'three', AuthSupport::META_EMAIL_VERIFIED => '0'];
        self::pendingAccount(20, self::TYPED);
        AuthSupport::beginEmailVerification(20, self::TYPED);
        $holderCode = self::lastCodeTo(self::TYPED);
        $before     = count($GLOBALS['__bcc_ph']['mail']);

        self::assertSame('bcc_invalid_otp', self::errorCode(self::verify(['email' => self::TYPED, 'code' => $holderCode])), 'the code is checked against the owner only');
        self::assertStillPending(20);

        self::assertSame(200, self::resend(['email' => self::TYPED]));
        self::assertCount($before + 1, $GLOBALS['__bcc_ph']['mail'], 'one mail, the owner\'s');
        self::assertNotNull(AuthSupport::emailOtpIdentity(self::OTHER));
    }

    public function testARealAddressIsNeverReplacedByAStalePending(): void
    {
        $GLOBALS['__bcc_ph']['users'][self::OTHER] = ['email' => 'real@example.com', 'login' => 'u3', 'display' => 'Three'];
        $GLOBALS['__bcc_ph']['meta'][self::OTHER]  = [PendingEmailService::META_PENDING_SIGNUP_EMAIL => self::TYPED];

        self::assertSame(PendingEmailService::SIGNUP_EMAIL_REFUSED, PendingEmailService::applyPendingSignupEmail(self::OTHER), 'H1: refused, never finalised');
        self::assertSame('real@example.com', self::email(self::OTHER));
        self::assertNull(self::pending(self::OTHER), 'the stale pending address is cleared');
    }

    // ── resend ──────────────────────────────────────────────────────────

    public function testResendByThatAddressMailsIt(): void
    {
        self::signup();

        self::assertSame(200, self::resend(['email' => self::TYPED]));

        self::assertCount(2, self::mailTo(self::TYPED));
        self::assertCount(2, $GLOBALS['__bcc_ph']['mail']);
    }

    public function testResendByHandleMailsThePendingAddressNotThePlaceholder(): void
    {
        self::signup();

        self::assertSame(200, self::resend(['identifier' => '@newhandle']));

        self::assertCount(2, self::mailTo(self::TYPED));
        self::assertCount(2, $GLOBALS['__bcc_ph']['mail'], 'nothing to the placeholder');
    }

    // ── recovery email ──────────────────────────────────────────────────

    public function testConfirmingARecoveryEmailClearsThePending(): void
    {
        self::pendingAccount(self::OTHER, self::TYPED);
        $otp = PendingEmailService::stage(self::OTHER, 'recovery@example.com', PendingEmailService::PURPOSE_RECOVERY);

        $out = PendingEmailService::confirm(self::OTHER, $otp, PendingEmailService::PURPOSE_RECOVERY);

        self::assertTrue($out['ok']);
        self::assertSame('recovery@example.com', self::email(self::OTHER));
        self::assertNull(self::pending(self::OTHER));
    }
}
