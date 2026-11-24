<?php

declare(strict_types=1);

/**
 * ValidatorRelationshipServiceIntegrationTest: the staff-link target check,
 * answered from $GLOBALS['__bcc_ineligible_targets'] (eligible unless listed).
 * The real check (account, suspension, PeepSo role) is unit-tested in
 * ValidatorLinkTargetCheckTest; here the service's REFUSAL inside the run is
 * what is proved. Load only in a separate process, from setUp(), before the
 * real class is autoloaded.
 */

namespace BCC\Trust\Onchain\Services;

if (!class_exists(__NAMESPACE__ . '\\ValidatorLinkTargetCheck', false)) {
    final class ValidatorLinkTargetCheck
    {
        public static function eligible(int $userId): bool
        {
            return !in_array($userId, $GLOBALS['__bcc_ineligible_targets'] ?? [], true);
        }
    }
}
