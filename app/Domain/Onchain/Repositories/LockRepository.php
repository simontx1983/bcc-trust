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
 * @see \BCC\Core\DB\AdvisoryLock
 */
final class LockRepository extends \BCC\Core\DB\AdvisoryLock
{
}
