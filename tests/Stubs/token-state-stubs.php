<?php
/**
 * A stand-in TokenStateRepository for suites that test JWT and session
 * BEHAVIOUR, not the database (F-462).
 *
 * The real repository reads a user's token state (tv, the sign-out
 * denylist, the refresh retired list) with one guarded SELECT through
 * global $wpdb. These suites keep that state in whatever get_user_meta()
 * stub they load, so this stand-in reads it back through the same
 * get_user_meta() JwtToken's unqualified call resolved to (a suite's
 * BCC\Trust\Core\Support one first, else the global), and answers
 * exactly what JwtToken read before F-462. It adds one switch:
 *
 *   $GLOBALS['__bcc_token_state']['fail'] = true   every read fails (null)
 *   $GLOBALS['__bcc_token_state']['fail_from'] = N  reads N, N+1, … fail
 *                                                   (1-based), earlier ones work
 *
 * and records each user id read in $GLOBALS['__bcc_token_state']['reads'].
 *
 * ⚠ The REAL class (its probe, the pre-flush stale-rows trap, the poisoned
 * meta cache) is tested by TokenStateRepositoryTest and
 * TokenStatePoisonTest. They load it BEFORE any stub file, so the
 * class_exists(…, false) guard below steps aside. Pass `false`: a plain
 * class_exists() would autoload the real class and this stand-in would
 * never be defined.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\TokenStateRepository', false)) {
        final class TokenStateRepository
        {
            public const TOKEN_VERSION_KEY = 'bcc_token_version';
            public const DENYLIST_KEY      = 'bcc_jwt_denylist';
            public const RETIRED_KEY       = 'bcc_jwt_retired';

            /** @return array{tv: int, denylist: mixed, retired: mixed}|null */
            public static function read(int $userId): ?array
            {
                $GLOBALS['__bcc_token_state']['reads'][] = $userId;
                $n        = count($GLOBALS['__bcc_token_state']['reads']);
                $failFrom = (int) ($GLOBALS['__bcc_token_state']['fail_from'] ?? 0);
                if (!empty($GLOBALS['__bcc_token_state']['fail']) || ($failFrom > 0 && $n >= $failFrom)) {
                    return null;
                }

                // The get_user_meta() JwtToken's own unqualified call reached:
                // a suite's BCC\Trust\Core\Support one first, else the global.
                $get = function_exists('BCC\Trust\Core\Support\get_user_meta')
                    ? 'BCC\Trust\Core\Support\get_user_meta'
                    : 'get_user_meta';

                return [
                    'tv'       => (int) $get($userId, self::TOKEN_VERSION_KEY, true),
                    'denylist' => $get($userId, self::DENYLIST_KEY, true),
                    'retired'  => $get($userId, self::RETIRED_KEY, true),
                ];
            }
        }
    }
}
