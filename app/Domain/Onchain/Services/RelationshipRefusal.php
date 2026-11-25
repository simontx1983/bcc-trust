<?php

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A validator relationship change refused for a stated reason (D3, an owner
 * already present, a spent step-up). Thrown inside the change's
 * transaction so nothing it wrote commits, and mapped by the service to the
 * API's `data.reason` and HTTP status.
 *
 * @package BCC\Trust\Onchain\Services
 */
final class RelationshipRefusal extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status)
    {
        parent::__construct($reason);
    }
}
