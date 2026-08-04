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

    private ?\BCC\Trust\Disputes\Services\DisputeVoteService $disputeVoteService = null;

    /**
     * Rank Phase 6 Wave 3 — the dispute layer over the generic poll
     * engine. Engine collaborators come from the Core container (the
     * poll engine lives in the Rank domain).
     */
    public function disputeVoteService(): \BCC\Trust\Disputes\Services\DisputeVoteService
    {
        if ($this->disputeVoteService === null) {
            $core = \BCC\Trust\Core\Plugin::instance();
            $this->disputeVoteService = new \BCC\Trust\Disputes\Services\DisputeVoteService(
                $core->pollService(),
                $core->pollRepository(),
                $core->rankScoringConfig()
            );
        }
        return $this->disputeVoteService;
    }
}
