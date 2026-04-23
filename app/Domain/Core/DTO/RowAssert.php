<?php

namespace BCC\Trust\Core\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trust-engine flavoured row-extraction helpers.
 *
 * Currently a thin alias over the canonical bcc-core implementation. Add
 * trust-domain extractors here as they emerge — keep the primitive
 * extractors (requireDigitInt, requireFloat, requireString, etc.) in the
 * parent class so they stay shared across plugins.
 *
 * @see \BCC\Core\DTO\RowAssert
 */
final class RowAssert extends \BCC\Core\DTO\RowAssert
{
}
