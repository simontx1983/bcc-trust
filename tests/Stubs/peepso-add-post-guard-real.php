<?php
/**
 * Loads the REAL `BCC\Core\PeepSo\PeepSoAddPostGuard` from the sibling
 * bcc-core checkout, for the suites that exercise a write wrapped in it.
 *
 * ⭐ WHY THE REAL CLASS AND NOT A DOUBLE. A stub `pinOnly()` that just
 * invoked its callable would make every assertion below it vacuous: the
 * test would prove only that this plugin calls something named `pinOnly`,
 * which is the one thing a reviewer can already see. The property worth
 * pinning is that the REQUEST IS ACTUALLY NEUTRALISED while the write
 * runs, and only the real guard can answer that.
 *
 * It is safe to load here: `pinOnly()` passes an EMPTY hook list, so
 * `detachListenersExcept()` never runs and no WordPress function is
 * touched — `pinRequest()`/`restoreRequest()` are plain superglobal
 * assignments.
 *
 * ⚠ `class_exists(..., false)` — the second argument is load-bearing.
 * Without it the check AUTOLOADS the class it is asking about, so the
 * guard would report "already present" and skip the require on a tree
 * where it is reachable by autoload, making the two paths differ.
 *
 * ⛔ FAILS LOUDLY when the sibling checkout is absent. A silent skip here
 * would turn every pin assertion into a false green, which is exactly the
 * failure mode these tests exist to rule out.
 */

declare(strict_types=1);

if (!class_exists('BCC\\Core\\PeepSo\\PeepSoAddPostGuard', false)) {
    $bccCoreGuard = dirname(__DIR__, 2) . '/../bcc-core/src/PeepSo/PeepSoAddPostGuard.php';

    if (!is_file($bccCoreGuard)) {
        throw new \RuntimeException(
            'The real PeepSoAddPostGuard was not found at ' . $bccCoreGuard
            . '. This suite asserts that the request is neutralised during the'
            . ' write, so it must not pass without the real guard.'
        );
    }

    require_once $bccCoreGuard;
}

if (!method_exists('BCC\\Core\\PeepSo\\PeepSoAddPostGuard', 'pinOnly')) {
    throw new \RuntimeException(
        'PeepSoAddPostGuard::pinOnly() is missing. bcc-core #136 added it;'
        . ' a sibling checkout older than that cannot pin these writes.'
    );
}
