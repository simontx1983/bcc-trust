<?php
/**
 * AttestationException — service-layer error type translated to the
 * §1.4 error envelope by the REST endpoint.
 *
 * The service surfaces every mutation refusal as one of these. The
 * MeAttestationsEndpoint catches the exception once and translates
 * `(code, message, status, data)` into the canonical ApiResponse::error
 * shape — keeping endpoint logic flat and centralizing the §4.20
 * error catalogue in one place (the constants below).
 *
 * Why an exception rather than a result tuple: cast() / revoke() /
 * reaffirm() each have ~6 distinct early-return paths (auth, validation,
 * self-target, tier, slot exhausted, fraud, race). A typed exception
 * collapses every refusal into a single catch site without forcing the
 * service to thread an ok/err tuple through nested helpers.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer Slice C (2026-05-13)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class AttestationException extends RuntimeException
{
    /** Locked §4.20 error codes — keep in sync with api-contract-v1.md. */
    public const CODE_INVALID_REQUEST       = 'bcc_invalid_request';
    public const CODE_SELF                  = 'bcc_attestation_self';
    public const CODE_INELIGIBLE            = 'bcc_attestation_ineligible';
    public const CODE_BANDWIDTH_EXHAUSTED   = 'bcc_attestation_bandwidth_exhausted';
    public const CODE_RATE_LIMITED          = 'bcc_rate_limited';
    public const CODE_FRAUD_BLOCKED         = 'bcc_attestation_fraud_blocked';
    public const CODE_NOT_FOUND             = 'bcc_not_found';
    public const CODE_FORBIDDEN             = 'bcc_forbidden';
    public const CODE_REVOKED               = 'bcc_attestation_revoked';
    public const CODE_INTERNAL              = 'bcc_internal_error';

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $data = []
    ) {
        parent::__construct($message);
    }
}
