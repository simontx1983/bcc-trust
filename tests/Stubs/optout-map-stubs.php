<?php
/**
 * User-meta doubles for OptOutMapCoherenceTest: the REAL NftGroupGateService
 * reads and writes its opt-out map through these.
 *
 * Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope: PHPUnit loads test files in the parent process too.
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services {
    // Real NftGroupGateService, real opt-out map; only user meta is faked.
    // A single store stands in for wp_usermeta, the one place every instance
    // reads and writes. Namespace-scoped, loaded only in this class's
    // separate processes.
    if (!function_exists(__NAMESPACE__ . '\get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            $GLOBALS['__bcc_optout_reads'] = ($GLOBALS['__bcc_optout_reads'] ?? 0) + 1;
            $value = $GLOBALS['__bcc_optout_meta'][$userId][$key] ?? null;

            return $single ? ($value ?? '') : ($value === null ? [] : [$value]);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\update_user_meta')) {
        /** @param mixed $value */
        function update_user_meta(int $userId, string $key, $value): bool
        {
            $GLOBALS['__bcc_optout_meta'][$userId][$key] = $value;

            return true;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\delete_user_meta')) {
        function delete_user_meta(int $userId, string $key): bool
        {
            unset($GLOBALS['__bcc_optout_meta'][$userId][$key]);

            return true;
        }
    }
}
