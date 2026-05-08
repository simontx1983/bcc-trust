<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Typed exception thrown by NftPieceViewModelBuilder.
 *
 * `code` carries the §4.17 error string verbatim so the REST endpoint
 * can map directly to the wire response without a translation table.
 *
 * @since V2 Phase 6 (§H1)
 */
final class PieceBuilderException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
