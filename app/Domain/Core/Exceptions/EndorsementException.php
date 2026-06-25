<?php
/**
 * Endorsement Exception
 *
 * A typed, contract-bearing exception for the endorse/revoke flow. It carries
 * the stable §1.4 API error code + HTTP status (and optional data array) so the
 * REST controller can map a failure to its envelope WITHOUT pattern-matching the
 * human-readable message. A copy-edit to a message can therefore no longer
 * silently reroute an error to `bcc_internal` 500.
 *
 * The named static constructors below are the single source of truth for the
 * (errorCode, httpStatus) pairs documented in docs/api-contract-v1.md §1.4.
 * EndorsementExceptionTest pins them.
 *
 * Extends \RuntimeException (→ \Exception) so existing non-REST `catch (Exception)`
 * call sites keep working unchanged.
 *
 * @package BCC\Trust\Core\Exceptions
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Exceptions;

if (!defined('ABSPATH')) {
    exit;
}

final class EndorsementException extends \RuntimeException
{
    private readonly string $errorCode;

    private readonly int $httpStatus;

    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $message,
        string $errorCode,
        int $httpStatus,
        array $data = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->data       = $data;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    // ──────────────────────────────────────────────────────────────────
    // Named static constructors — the canonical (code, status) mapping.
    // Each preserves the supplied human message for display/logging.
    // ──────────────────────────────────────────────────────────────────

    public static function authRequired(string $msg = 'Authentication required'): self
    {
        return new self($msg, 'bcc_unauthorized', 401);
    }

    public static function invalidPage(string $msg = 'Invalid page.'): self
    {
        return new self($msg, 'bcc_invalid_request', 400);
    }

    public static function selfPage(string $msg = 'You cannot endorse your own page'): self
    {
        return new self($msg, 'bcc_endorse_self', 403);
    }

    public static function alreadyEndorsed(string $msg = 'You have already endorsed this page'): self
    {
        return new self($msg, 'bcc_conflict', 409);
    }

    public static function fraudLocked(string $msg): self
    {
        return new self($msg, 'bcc_fraud_locked', 403);
    }

    public static function busy(string $msg = 'Endorsement system is busy. Please try again in a moment.'): self
    {
        return new self($msg, 'bcc_rate_limited', 429);
    }

    /**
     * Soft onboarding/age/quest gate. The message IS the unlock hint
     * (§1.4.5 data.unlock_hint companion).
     */
    public static function softGate(string $msg): self
    {
        return new self($msg, 'bcc_permission_denied', 403, ['unlock_hint' => $msg]);
    }

    public static function notFound(string $msg = 'Endorsement not found'): self
    {
        return new self($msg, 'bcc_not_found', 404);
    }

    /**
     * Daily / velocity throttle (e.g. "daily endorsement limit",
     * "receiving endorsements too quickly").
     */
    public static function dailyLimit(string $msg): self
    {
        return new self($msg, 'bcc_rate_limited', 429);
    }
}
