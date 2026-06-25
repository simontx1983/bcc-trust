<?php

namespace BCC\Trust\Disputes;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Singleton service container for the Disputes bounded context.
 *
 * Owns the construction of Disputes-namespace repositories so that
 * Core's Plugin no longer wires them. All accessors are lazy-initialized,
 * dependency-free singletons (no-arg constructors).
 */
final class DisputesPlugin
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private ?\BCC\Trust\Disputes\Repositories\DisputeParticipationRepository $disputeParticipationRepository = null;
    public function disputeParticipationRepository(): \BCC\Trust\Disputes\Repositories\DisputeParticipationRepository
    {
        return $this->disputeParticipationRepository
            ??= new \BCC\Trust\Disputes\Repositories\DisputeParticipationRepository();
    }
}
