<?php

namespace BCC\Trust\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Onchain-flavoured handle for MySQL advisory locks.
 *
 * Currently a thin alias over the canonical bcc-core implementation. Add
 * onchain-specific key-namespacing helpers here if they emerge — keep the
 * primitive acquire/release in the parent so it stays shared.
 *
 * Same-name sibling: this class shares its short name with
 * {@see \BCC\PeepSo\Repositories\LockRepository}, the full advisory-lock
 * implementation used by the shadow-CPT sync layer. Do not collapse the
 * two — see docs/pattern-registry.md "Same-name-different-class index".
 *
 * @see \BCC\Core\DB\AdvisoryLock                Parent — primitive acquire/release.
 * @see \BCC\PeepSo\Repositories\LockRepository  Same-short-name sibling.
 */
final class LockRepository extends \BCC\Core\DB\AdvisoryLock
{
}
