<?php

namespace BCC\Trust\Core\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hydrated X (Twitter) verification connection.
 *
 * Built by `XRepository::getConnection()` from the
 * `bcc_trust_user_verifications` row (type='x') plus the decoded `meta`
 * JSON blob. Typed replacement for the previous stdClass-with-dynamic-
 * properties shape.
 *
 * Field naming (`x_*`) is preserved verbatim so existing callers need no
 * rename.
 */
final class XConnectionDTO
{
    public function __construct(
        public readonly ?string $x_id,
        public readonly ?string $x_username,
        public readonly ?string $x_avatar,
        public readonly float   $x_trust_boost,
        public readonly int     $x_fraud_reduction,
        public readonly ?string $x_access_token,
        public readonly ?string $x_verified_at,
        public readonly ?string $x_last_synced,
        public readonly ?string $x_email,
        public readonly ?string $x_access_token_decrypted,
    ) {
        $dto = 'XConnectionDTO';
        DTOAssert::finiteFloat($x_trust_boost,        $dto, 'x_trust_boost');
        DTOAssert::nonNegativeInt($x_fraud_reduction, $dto, 'x_fraud_reduction');
        DTOAssert::nullableDatetime($x_verified_at,   $dto, 'x_verified_at');
        DTOAssert::nullableDatetime($x_last_synced,   $dto, 'x_last_synced');
    }
}
