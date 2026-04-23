<?php

namespace BCC\Trust\Core\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hydrated GitHub verification connection.
 *
 * Built by `GitHubRepository::getConnection()` from the
 * `bcc_trust_user_verifications` row (type='github') plus the decoded `meta`
 * JSON blob. Replaces the stdClass-with-dynamic-properties shape used
 * previously — consumers now see typed fields with no `??` fallbacks.
 *
 * Field naming (`github_*`) is preserved verbatim so existing callers need
 * no rename; the DTO is a type-system upgrade, not a rename.
 *
 * All fields are `readonly` to prevent downstream mutation; the DTO is a
 * snapshot, not a handle on the underlying row.
 */
final class GitHubConnectionDTO
{
    public function __construct(
        public readonly ?string $github_id,
        public readonly ?string $github_username,
        public readonly ?string $github_avatar,
        public readonly float   $github_trust_boost,
        public readonly int     $github_fraud_reduction,
        public readonly ?string $github_access_token,
        public readonly ?string $github_verified_at,
        public readonly ?string $github_last_synced,
        public readonly int     $github_followers,
        public readonly int     $github_public_repos,
        public readonly int     $github_org_count,
        public readonly ?string $github_account_created,
        public readonly int     $github_account_age_days,
        public readonly int     $github_has_verified_email,
        public readonly ?string $github_access_token_decrypted,
    ) {
        $dto = 'GitHubConnectionDTO';
        DTOAssert::finiteFloat($github_trust_boost,       $dto, 'github_trust_boost');
        DTOAssert::nonNegativeInt($github_fraud_reduction, $dto, 'github_fraud_reduction');
        DTOAssert::nonNegativeInt($github_followers,       $dto, 'github_followers');
        DTOAssert::nonNegativeInt($github_public_repos,    $dto, 'github_public_repos');
        DTOAssert::nonNegativeInt($github_org_count,       $dto, 'github_org_count');
        DTOAssert::nonNegativeInt($github_account_age_days, $dto, 'github_account_age_days');
        DTOAssert::intEnum($github_has_verified_email, [0, 1], $dto, 'github_has_verified_email');
        DTOAssert::nullableDatetime($github_verified_at,      $dto, 'github_verified_at');
        DTOAssert::nullableDatetime($github_last_synced,      $dto, 'github_last_synced');
        DTOAssert::nullableDatetime($github_account_created,  $dto, 'github_account_created');
    }
}
