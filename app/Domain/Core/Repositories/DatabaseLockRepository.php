<?php

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trust-engine handle for MySQL advisory locks.
 *
 * Currently a thin alias over the canonical bcc-core implementation. Add
 * trust-specific key-namespacing helpers here if they emerge — keep the
 * primitive acquire/release in the parent so it stays shared.
 *
 * @see \BCC\Core\DB\AdvisoryLock
 */
final class DatabaseLockRepository extends \BCC\Core\DB\AdvisoryLock
{
}
