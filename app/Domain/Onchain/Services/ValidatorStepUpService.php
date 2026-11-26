<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Core\REST\AccountCredentialCheck;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\RateLimiter;
use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Services\AuthMailer;
use BCC\Trust\Onchain\Repositories\ValidatorStepUpRepository as StepUps;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Staff step-up for validator relationship changes.
 *
 * 1. {@see start()}: the staff member re-enters their PASSWORD (an account
 *    without one is refused: an OAuth-only admin cannot step up). For a
 *    link, revoke or cancel, a 6-digit code is emailed to them; a team add
 *    or remove is password-only. The step-up is bound to the action, page,
 *    target and role, lives 10 minutes, and is returned only as an opaque id.
 * 2. {@see checkCode()}: BEFORE the action's transaction, one attempt is
 *    counted atomically (5 at most) and the code compared with hash_equals.
 *    The count commits on its own, so a wrong code is never rolled back. The
 *    right code is RECORDED on the row (code_verified_at).
 * 3. {@see consumeInTransaction()}: INSIDE the action's transaction, the
 *    step-up is spent only for its exact binding and, when it carries a
 *    code, only once that code was recorded as verified. A caller that skips
 *    checkCode() (or ignores its answer) cannot spend it.
 *
 * A resend never extends a step-up past 30 minutes from its start.
 *
 * The code is stored only as HMAC-SHA256 under an HKDF-derived key, bound to
 * the step-up id; it is never logged or returned. Starting is capped per
 * user (each start emails a code), and so are password checks. Failures and
 * expiries are recorded in the relationship log; starts in the audit log.
 * Every method takes the staff user id explicitly (no current-user reads).
 *
 * @package BCC\Trust\Onchain\Services
 */
final class ValidatorStepUpService
{
    /** Actions confirmed with the password AND an emailed code. */
    public const ACTIONS_WITH_CODE = StepUps::CODE_REQUIRED_ACTIONS;

    /** Actions confirmed with the password alone. */
    public const ACTIONS_PASSWORD_ONLY = ['add_team_member', 'remove_team_member'];

    public const TTL_SECONDS    = 600;
    public const RESEND_SECONDS = 60;

    /** Step-up starts (and code resends: each sends mail) per user per window. */
    public const MAIL_LIMIT  = 5;
    public const MAIL_WINDOW = 900;

    /** Password checks per user per window, right or wrong. */
    public const PASSWORD_LIMIT  = 10;
    public const PASSWORD_WINDOW = 900;

    private const CODE_KEY_INFO = 'bcc-validator-step-up-code-v1';

    private const ACTION_LABELS = [
        'link_owner'   => 'link a validator owner',
        'revoke_owner' => 'revoke a validator owner',
        'cancel_link'  => 'cancel a validator owner link',
    ];

    /**
     * Begin a step-up.
     *
     * @return array{ok: true, step_up_id: string, expires_in: int, resend_after: int}|array{ok: false, error: string, status: int, retry_after?: int}
     */
    public static function start(\WP_REST_Request $request, int $actorId, string $action, int $pageId, int $targetUserId, string $role): array
    {
        $withCode = in_array($action, self::ACTIONS_WITH_CODE, true);
        if (!$withCode && !in_array($action, self::ACTIONS_PASSWORD_ONLY, true)) {
            return self::fail('unknown_action', 400);
        }
        if ($actorId <= 0) {
            return self::fail('auth_required', 401);
        }

        // An OAuth-only account has no password to re-enter: refuse rather
        // than fall back to a weaker proof.
        if (AccountRecoveryService::hasPassword($actorId) !== true || (string) $request->get_param('current_password') === '') {
            return self::fail('password_required', 403);
        }
        if (!RateLimiter::allowByKey('validator_step_up_password:' . $actorId, self::PASSWORD_LIMIT, self::PASSWORD_WINDOW)) {
            return self::rateLimited('validator_step_up_password:' . $actorId, self::PASSWORD_LIMIT, self::PASSWORD_WINDOW);
        }
        $checked = AccountCredentialCheck::confirm($request, $actorId, 'verify');
        if ($checked !== 'password') {
            self::recordFailure($actorId, $action, $pageId, $targetUserId, 'credential_invalid');
            return self::fail('credential_invalid', 403);
        }
        if ($withCode && !RateLimiter::allowByKey('validator_step_up_mail:' . $actorId, self::MAIL_LIMIT, self::MAIL_WINDOW)) {
            return self::rateLimited('validator_step_up_mail:' . $actorId, self::MAIL_LIMIT, self::MAIL_WINDOW);
        }

        $id   = bin2hex(random_bytes(16));
        $now  = time();
        $code = $withCode ? self::newCode() : null;
        StepUps::create([
            'id'             => $id,
            'user_id'        => $actorId,
            'action'         => $action,
            'page_id'        => max(0, $pageId),
            'target_user_id' => max(0, $targetUserId),
            'role'           => $role,
            'code_hash'      => $code === null ? null : self::codeHash($id, $code),
            'created_at'     => gmdate('Y-m-d H:i:s', $now),
            'expires_at'     => gmdate('Y-m-d H:i:s', $now + self::TTL_SECONDS),
            'resend_after'   => $code === null ? null : gmdate('Y-m-d H:i:s', $now + self::RESEND_SECONDS),
        ]);

        if ($code !== null && !self::mailCode($actorId, $code, $action)) {
            return self::fail('mail_unavailable', 503);
        }
        AuditLogger::log('validator_step_up_started', $pageId, ['action' => $action, 'target_user_id' => $targetUserId], 'page', $actorId);

        return [
            'ok'           => true,
            'step_up_id'   => $id,
            'expires_in'   => self::TTL_SECONDS,
            'resend_after' => $code === null ? 0 : self::RESEND_SECONDS,
        ];
    }

    /**
     * Email a fresh code for a live step-up once its resend window passed.
     *
     * @return array{ok: true, expires_in: int, resend_after: int}|array{ok: false, error: string, status: int, retry_after?: int}
     */
    public static function resend(int $actorId, string $stepUpId): array
    {
        if ($actorId <= 0 || !self::wellFormed($stepUpId)) {
            return self::fail('step_up_invalid', 403);
        }
        $row = StepUps::find($stepUpId, $actorId);
        if ($row === null || $row->code_hash === null) {
            return self::fail('step_up_invalid', 403);
        }
        if (!RateLimiter::allowByKey('validator_step_up_mail:' . $actorId, self::MAIL_LIMIT, self::MAIL_WINDOW)) {
            return self::rateLimited('validator_step_up_mail:' . $actorId, self::MAIL_LIMIT, self::MAIL_WINDOW);
        }

        $now     = time();
        $created = strtotime((string) $row->created_at . ' UTC');
        $expires = min($now + self::TTL_SECONDS, ($created === false ? $now : $created) + StepUps::MAX_LIFETIME_SECONDS);
        if ($expires <= $now) {
            return self::fail('step_up_expired', 403);
        }
        $code = self::newCode();
        if (!StepUps::replaceCode(
            $stepUpId,
            $actorId,
            self::codeHash($stepUpId, $code),
            gmdate('Y-m-d H:i:s', $expires),
            gmdate('Y-m-d H:i:s', $now + self::RESEND_SECONDS),
            gmdate('Y-m-d H:i:s', $now)
        )) {
            return self::fail('resend_not_allowed', 409);
        }
        if (!self::mailCode($actorId, $code, (string) $row->action)) {
            return self::fail('mail_unavailable', 503);
        }

        return ['ok' => true, 'expires_in' => $expires - $now, 'resend_after' => self::RESEND_SECONDS];
    }

    /**
     * Check the code of a step-up, BEFORE the action's transaction. One
     * attempt is counted first, atomically, and commits on its own. The
     * right code is recorded on the row; consume() requires that record.
     *
     * @return string|null null when the action may proceed; otherwise the
     *   error key: step_up_invalid | step_up_expired | code_invalid
     */
    public static function checkCode(int $actorId, string $stepUpId, ?string $code): ?string
    {
        if ($actorId <= 0 || !self::wellFormed($stepUpId)) {
            return 'step_up_invalid';
        }
        $now = gmdate('Y-m-d H:i:s');
        if (!StepUps::countAttempt($stepUpId, $actorId, $now)) {
            $row = StepUps::find($stepUpId, $actorId);
            if ($row !== null && $row->used_at === null && strcmp((string) $row->expires_at, $now) <= 0) {
                self::recordFailure($actorId, (string) $row->action, (int) $row->page_id, (int) $row->target_user_id, 'step_up_expired', 'step_up_expired');
                return 'step_up_expired';
            }
            return 'step_up_invalid';
        }
        $row = StepUps::find($stepUpId, $actorId);
        if ($row === null) {
            return 'step_up_invalid';
        }
        if ($row->code_hash === null) {
            // A password-only step-up, which an owner-level action never is.
            return in_array((string) $row->action, self::ACTIONS_WITH_CODE, true) ? 'step_up_invalid' : null;
        }
        $expectedCodeHash = $row->code_hash;
        if ($code === null || preg_match('/^\d{6}$/', $code) !== 1
            || !hash_equals($expectedCodeHash, self::codeHash($stepUpId, $code))
        ) {
            self::recordFailure($actorId, (string) $row->action, (int) $row->page_id, (int) $row->target_user_id, 'code_invalid');
            return 'code_invalid';
        }
        if (!StepUps::markCodeVerified($stepUpId, $actorId, $now, $expectedCodeHash)) {
            // Already recorded by an earlier check of this code (a retried
            // action) is fine; a step-up that died in between is not.
            $row = StepUps::find($stepUpId, $actorId);
            if ($row === null || $row->code_hash === null || !hash_equals($expectedCodeHash, $row->code_hash)
                || $row->code_verified_at === null || $row->used_at !== null
                || strcmp($row->expires_at, gmdate('Y-m-d H:i:s')) <= 0 || (int) $row->attempts > StepUps::MAX_ATTEMPTS
            ) {
                return 'step_up_invalid';
            }
        }

        return null;
    }

    /**
     * Spend the step-up for its EXACT binding. Call INSIDE the action's
     * transaction, after checkCode() passed: a code-bearing step-up whose
     * code was never recorded as verified is refused here.
     */
    public static function consumeInTransaction(int $actorId, string $stepUpId, string $action, int $pageId, int $targetUserId, string $role): bool
    {
        if ($actorId <= 0 || !self::wellFormed($stepUpId)) {
            return false;
        }

        return StepUps::consume([
            'id'             => $stepUpId,
            'user_id'        => $actorId,
            'action'         => $action,
            'page_id'        => max(0, $pageId),
            'target_user_id' => max(0, $targetUserId),
            'role'           => $role,
        ], gmdate('Y-m-d H:i:s'));
    }

    /** Cron: delete step-ups that expired more than a day ago (bounded). */
    public static function purgeExpired(): int
    {
        return StepUps::purgeExpired(gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS));
    }

    /** HMAC-SHA256 of the code, bound to the step-up id, under an HKDF key. */
    public static function codeHash(string $stepUpId, string $code): string
    {
        $secret = defined('BCC_ENCRYPTION_KEY') ? (string) constant('BCC_ENCRYPTION_KEY') : '';
        if ($secret === '') {
            throw new \RuntimeException('BCC_ENCRYPTION_KEY is not set: step-up codes cannot be issued or checked.');
        }

        return hash_hmac('sha256', $stepUpId . '|' . $code, hash_hkdf('sha256', $secret, 32, self::CODE_KEY_INFO));
    }

    private static function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function wellFormed(string $stepUpId): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $stepUpId) === 1;
    }

    private static function mailCode(int $actorId, string $code, string $action): bool
    {
        $user = get_userdata($actorId);
        return $user instanceof \WP_User
            && AuthMailer::sendValidatorStepUpCode((string) $user->user_email, $code, self::ACTION_LABELS[$action] ?? 'a validator change');
    }

    private static function recordFailure(int $actorId, string $action, int $pageId, int $targetUserId, string $reason, string $logAction = 'step_up_failed'): void
    {
        RelationshipLogWriter::appendStandalone([
            'action'          => $logAction,
            'page_id'         => max(0, $pageId),
            'subject_user_id' => $targetUserId > 0 ? $targetUserId : null,
            'actor_kind'      => 'staff',
            'actor_user_id'   => $actorId,
            'payload'         => ['step_up_action' => $action, 'reason' => $reason],
        ]);
    }

    /** @return array{ok: false, error: string, status: int} */
    private static function fail(string $error, int $status): array
    {
        return ['ok' => false, 'error' => $error, 'status' => $status];
    }

    /** @return array{ok: false, error: string, status: int, retry_after: int} */
    private static function rateLimited(string $key, int $limit, int $window): array
    {
        return ['ok' => false, 'error' => 'rate_limited', 'status' => 429, 'retry_after' => max(1, RateLimiter::retryAfterByKey($key, $limit, $window))];
    }
}
