<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\ValidatorStepUpService as S;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The staff step-up: password re-entry (no password = refused), a code
 * emailed for owner changes, attempts counted before comparing, and a
 * step-up spent only for its exact binding. The code never leaves except by
 * mail.
 */
#[CoversClass(S::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorStepUpServiceTest extends TestCase
{
    private const STAFF = 7;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/validator-step-up-stubs.php';
        \StepUpWorld::reset();
    }

    private static function request(?string $password = 'hunter2'): \WP_REST_Request
    {
        return new \WP_REST_Request($password === null ? [] : ['current_password' => $password]);
    }

    /** @return array{0: string, 1: string} [step-up id, emailed code] */
    private static function startLink(): array
    {
        $r = S::start(self::request(), self::STAFF, 'link_owner', 501, 900, 'owner');
        self::assertTrue($r['ok']);
        $mail = end(\StepUpWorld::$mails);
        self::assertIsArray($mail);

        return [$r['step_up_id'], $mail[1]];
    }

    public function testAnOwnerChangeEmailsACodeAndReturnsOnlyAnOpaqueId(): void
    {
        $r = S::start(self::request(), self::STAFF, 'link_owner', 501, 900, 'owner');

        self::assertSame(['ok' => true, 'expires_in' => 600, 'resend_after' => 60], array_diff_key($r, ['step_up_id' => 1]));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $r['step_up_id']);
        self::assertCount(1, \StepUpWorld::$mails);
        [$to, $code] = \StepUpWorld::$mails[0];
        self::assertSame('staff@example.org', $to);
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertStringNotContainsString($code, json_encode($r) ?: '', 'the code is never returned');
        $row = \StepUpWorld::$rows[$r['step_up_id']];
        self::assertSame(S::codeHash($r['step_up_id'], $code), $row->code_hash);
        self::assertStringNotContainsString($code, (string) $row->code_hash, 'only a keyed hash is stored');
        self::assertSame(['validator_step_up_started:7'], \StepUpWorld::$audits);
    }

    public function testATeamChangeIsPasswordOnly(): void
    {
        $r = S::start(self::request(), self::STAFF, 'add_team_member', 501, 900, 'representative');

        self::assertTrue($r['ok']);
        self::assertSame(0, $r['resend_after']);
        self::assertSame([], \StepUpWorld::$mails);
        self::assertNull(\StepUpWorld::$rows[$r['step_up_id']]->code_hash);
        self::assertNull(S::checkCode(self::STAFF, $r['step_up_id'], null));
    }

    public function testNoPasswordMeansNoStepUp(): void
    {
        \StepUpWorld::$hasPassword = false; // an OAuth-only admin
        self::assertSame('password_required', S::start(self::request(), self::STAFF, 'link_owner', 501, 900, 'owner')['error']);

        \StepUpWorld::$hasPassword = null; // unknown
        self::assertSame('password_required', S::start(self::request(), self::STAFF, 'link_owner', 501, 900, 'owner')['error']);

        \StepUpWorld::$hasPassword = true;
        self::assertSame('password_required', S::start(self::request(null), self::STAFF, 'link_owner', 501, 900, 'owner')['error']);
        self::assertSame([], \StepUpWorld::$rows);
    }

    public function testAWrongPasswordIsRefusedAndLogged(): void
    {
        \StepUpWorld::$credential = 'wallet'; // anything but a password confirmation

        $r = S::start(self::request(), self::STAFF, 'revoke_owner', 501, 900, 'owner');

        self::assertSame(['ok' => false, 'error' => 'credential_invalid', 'status' => 403], $r);
        self::assertSame([], \StepUpWorld::$rows);
        self::assertSame('step_up_failed', \StepUpWorld::$logRows[0]['action']);
        self::assertSame(['step_up_action' => 'revoke_owner', 'reason' => 'credential_invalid'], \StepUpWorld::$logRows[0]['payload']);
    }

    public function testPasswordChecksAndMailsAreCapped(): void
    {
        \StepUpWorld::$limits = ['validator_step_up_password:' => false];
        $r = S::start(self::request(), self::STAFF, 'link_owner', 501, 900, 'owner');
        self::assertSame([429, 'rate_limited', 120], [$r['status'], $r['error'], $r['retry_after']]);

        \StepUpWorld::$limits = ['validator_step_up_mail:' => false];
        $r = S::start(self::request(), self::STAFF, 'link_owner', 501, 900, 'owner');
        self::assertSame('rate_limited', $r['error']);
        self::assertSame([], \StepUpWorld::$rows, 'nothing stored, nothing sent');
        self::assertSame([], \StepUpWorld::$mails);
    }

    public function testAnUnknownActionIsRefused(): void
    {
        self::assertSame('unknown_action', S::start(self::request(), self::STAFF, 'transfer_owner', 501, 900, 'owner')['error']);
    }

    public function testTheRightCodePassesAndAWrongOneIsCountedAndLogged(): void
    {
        [$id, $code] = self::startLink();
        $wrong       = $code === '000000' ? '111111' : '000000';

        self::assertSame('code_invalid', S::checkCode(self::STAFF, $id, $wrong));
        self::assertSame('code_invalid', S::checkCode(self::STAFF, $id, 'abc'));
        self::assertSame('code_invalid', S::checkCode(self::STAFF, $id, null));
        self::assertSame(3, \StepUpWorld::$rows[$id]->attempts, 'every check counted');
        self::assertSame('code_invalid', \StepUpWorld::$logRows[0]['payload']['reason']);
        self::assertNull(S::checkCode(self::STAFF, $id, $code));
    }

    public function testFiveAttemptsKillTheStepUp(): void
    {
        [$id, $code] = self::startLink();
        $wrong       = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            S::checkCode(self::STAFF, $id, $wrong);
        }

        self::assertSame('step_up_invalid', S::checkCode(self::STAFF, $id, $code), 'even the right code, after the cap');
        self::assertFalse(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 501, 900, 'owner'), 'and it cannot be spent');
    }

    /**
     * The store, not the caller, proves the code was checked: a caller that
     * skips checkCode() or ignores a wrong-code answer cannot spend a
     * code-bearing step-up, even with attempts left under the cap.
     */
    public function testACodeStepUpIsNeverSpentWithoutAVerifiedCode(): void
    {
        [$id, $code] = self::startLink();
        $wrong       = $code === '000000' ? '111111' : '000000';

        self::assertFalse(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 501, 900, 'owner'), 'checkCode() skipped');
        self::assertSame('code_invalid', S::checkCode(self::STAFF, $id, $wrong));
        self::assertSame('code_invalid', S::checkCode(self::STAFF, $id, $wrong));
        self::assertSame(2, \StepUpWorld::$rows[$id]->attempts, 'under the cap');
        self::assertFalse(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 501, 900, 'owner'), 'a wrong-code answer ignored');

        self::assertNull(S::checkCode(self::STAFF, $id, $code));
        self::assertNotNull(\StepUpWorld::$rows[$id]->code_verified_at, 'the right code is recorded on the row');
        self::assertTrue(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 501, 900, 'owner'));
    }

    public function testTheRightCodeCheckedAgainStillPassesForARetriedAction(): void
    {
        [$id, $code] = self::startLink();

        self::assertNull(S::checkCode(self::STAFF, $id, $code));
        self::assertNull(S::checkCode(self::STAFF, $id, $code), 'already recorded: a retry after a transient failure');
        self::assertTrue(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 501, 900, 'owner'));
        self::assertSame('step_up_invalid', S::checkCode(self::STAFF, $id, $code), 'spent');
    }

    public function testAPasswordOnlyStepUpNeedsNoCode(): void
    {
        $r = S::start(self::request(), self::STAFF, 'remove_team_member', 501, 900, 'representative');

        self::assertTrue(S::consumeInTransaction(self::STAFF, $r['step_up_id'], 'remove_team_member', 501, 900, 'representative'));
    }

    /** The C3 split: a team step-up never authorizes an owner change, nor the reverse. */
    public function testATeamStepUpNeverSpendsOnAnOwnerActionNorTheReverse(): void
    {
        $team = S::start(self::request(), self::STAFF, 'add_team_member', 501, 900, 'owner')['step_up_id'];
        self::assertNull(S::checkCode(self::STAFF, $team, null));
        foreach (['link_owner', 'revoke_owner', 'cancel_link'] as $ownerAction) {
            self::assertFalse(S::consumeInTransaction(self::STAFF, $team, $ownerAction, 501, 900, 'owner'), $ownerAction);
        }

        [$owner, $code] = self::startLink();
        self::assertNull(S::checkCode(self::STAFF, $owner, $code));
        foreach (['add_team_member', 'remove_team_member'] as $teamAction) {
            self::assertFalse(S::consumeInTransaction(self::STAFF, $owner, $teamAction, 501, 900, 'owner'), $teamAction);
        }
    }

    /** Defence in depth: an owner-level row that somehow lost its code never skips the code. */
    public function testAnOwnerStepUpWithoutACodeIsInvalid(): void
    {
        [$id] = self::startLink();
        \StepUpWorld::$rows[$id]->code_hash = null;

        self::assertSame('step_up_invalid', S::checkCode(self::STAFF, $id, null));
        self::assertFalse(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 501, 900, 'owner'));
    }

    public function testAnExpiredStepUpIsReportedAndLogged(): void
    {
        [$id, $code] = self::startLink();
        \StepUpWorld::$rows[$id]->expires_at = gmdate('Y-m-d H:i:s', time() - 1);

        self::assertSame('step_up_expired', S::checkCode(self::STAFF, $id, $code));
        self::assertSame('step_up_expired', end(\StepUpWorld::$logRows)['action']);
    }

    public function testAnotherUsersOrAMalformedStepUpIsInvalid(): void
    {
        [$id, $code] = self::startLink();

        self::assertSame('step_up_invalid', S::checkCode(8, $id, $code), 'not this staff member\'s');
        self::assertSame('step_up_invalid', S::checkCode(self::STAFF, 'not-hex', $code));
        self::assertSame('step_up_invalid', S::checkCode(0, $id, $code));
    }

    public function testConsumeNeedsTheExactBindingAndWorksOnce(): void
    {
        [$id, $code] = self::startLink();
        self::assertNull(S::checkCode(self::STAFF, $id, $code));

        self::assertFalse(S::consumeInTransaction(self::STAFF, $id, 'revoke_owner', 501, 900, 'owner'), 'another action');
        self::assertFalse(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 502, 900, 'owner'), 'another page');
        self::assertFalse(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 501, 901, 'owner'), 'another target');
        self::assertFalse(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 501, 900, 'administrator'), 'another role');
        self::assertFalse(S::consumeInTransaction(8, $id, 'link_owner', 501, 900, 'owner'), 'another staff member');
        self::assertTrue(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 501, 900, 'owner'));
        self::assertFalse(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 501, 900, 'owner'), 'single use');
    }

    public function testResendRespectsTheWindowAndReplacesTheCode(): void
    {
        [$id, $code] = self::startLink();

        self::assertSame('resend_not_allowed', S::resend(self::STAFF, $id)['error'], 'inside the 60s window');

        \StepUpWorld::$rows[$id]->resend_after = gmdate('Y-m-d H:i:s', time() - 1);
        self::assertTrue(S::resend(self::STAFF, $id)['ok']);
        $newCode = end(\StepUpWorld::$mails)[1];
        if ($newCode !== $code) {
            self::assertSame('code_invalid', S::checkCode(self::STAFF, $id, $code), 'the old code is gone');
        }
        self::assertNull(S::checkCode(self::STAFF, $id, $newCode));
    }

    public function testAResendClearsTheVerificationSoTheNewCodeMustBeEntered(): void
    {
        [$id, $code] = self::startLink();
        self::assertNull(S::checkCode(self::STAFF, $id, $code));

        \StepUpWorld::$rows[$id]->resend_after = gmdate('Y-m-d H:i:s', time() - 1);
        self::assertTrue(S::resend(self::STAFF, $id)['ok']);
        self::assertFalse(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 501, 900, 'owner'), 'the old verification is gone');

        self::assertNull(S::checkCode(self::STAFF, $id, end(\StepUpWorld::$mails)[1]));
        self::assertTrue(S::consumeInTransaction(self::STAFF, $id, 'link_owner', 501, 900, 'owner'));
    }

    public function testResendsNeverExtendAStepUpPastThirtyMinutes(): void
    {
        [$id] = self::startLink();
        $row               = \StepUpWorld::$rows[$id];
        $row->resend_after = gmdate('Y-m-d H:i:s', time() - 1);
        $row->created_at   = gmdate('Y-m-d H:i:s', time() - 1500);

        $r = S::resend(self::STAFF, $id);
        self::assertTrue($r['ok']);
        self::assertEqualsWithDelta(300, $r['expires_in'], 2, 'capped at 30 minutes from the start, not 10 from now');
        self::assertSame(gmdate('Y-m-d H:i:s', strtotime($row->created_at . ' UTC') + 1800), $row->expires_at);

        $row->resend_after = gmdate('Y-m-d H:i:s', time() - 1);
        $row->created_at   = gmdate('Y-m-d H:i:s', time() - 1800);
        $mails             = count(\StepUpWorld::$mails);
        self::assertSame('step_up_expired', S::resend(self::STAFF, $id)['error'] ?? null, 'at the cap: start a new step-up');
        self::assertCount($mails, \StepUpWorld::$mails, 'no code mailed');
    }

    public function testTheCodeHashIsBoundToTheStepUpAndKeyed(): void
    {
        $a = S::codeHash(str_repeat('a', 32), '123456');
        self::assertNotSame($a, S::codeHash(str_repeat('b', 32), '123456'), 'bound to the id');
        self::assertSame(
            hash_hmac('sha256', str_repeat('a', 32) . '|123456', hash_hkdf('sha256', BCC_ENCRYPTION_KEY, 32, 'bcc-validator-step-up-code-v1')),
            $a
        );
    }
}
