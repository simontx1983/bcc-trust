<?php
/**
 * F-93: bcc-core's AdvisoryLock for NftGroupReconcileSweepTest (bcc-core is
 * not autoloaded in the unit suite). `$GLOBALS['__rs']['held']` lists names
 * another tick already holds; acquisitions and releases are recorded in the
 * shared event log. The real lock is proven in bcc-core and in the
 * integration suite; this proves which lock the sweep asks for and that it
 * always lets go.
 */

declare(strict_types=1);

namespace BCC\Core\DB {
    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        final class AdvisoryLock
        {
            public static function acquire(string $name, int $timeout = 0): bool
            {
                if (in_array($name, $GLOBALS['__rs']['held'] ?? [], true)) {
                    $GLOBALS['__rs']['events'][] = 'busy:' . $name;
                    return false;
                }
                $GLOBALS['__rs']['events'][] = 'lock:' . $name . '/' . $timeout;
                return true;
            }

            public static function release(string $name): bool
            {
                $GLOBALS['__rs']['events'][] = 'unlock:' . $name;
                return true;
            }
        }
    }
}
