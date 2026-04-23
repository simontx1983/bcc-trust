<?php

namespace BCC\Trust\Core\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trust-engine flavoured DTO assertions.
 *
 * Currently a thin alias over the canonical bcc-core implementation. Add
 * trust-domain cross-field invariants here as they emerge — keep primitive
 * checks in the parent class so they stay shared across plugins.
 *
 * @see \BCC\Core\DTO\DTOAssert
 */
final class DTOAssert extends \BCC\Core\DTO\DTOAssert
{
}
