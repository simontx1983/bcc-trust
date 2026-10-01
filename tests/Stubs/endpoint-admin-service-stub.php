<?php

declare(strict_types=1);

/**
 * The endpoint-switch SERVICE, counted rather than exercised.
 *
 * Loaded only by the handler-gate tests, which ask whether a request reaches
 * the service at all. The confirmation-panel test must NOT load this: it needs
 * the real `plan()`, because the redaction decision inside it is exactly what
 * that test is checking.
 *
 * @package BCC_Trust
 * @subpackage Tests
 */

namespace BCC\Trust\Onchain\Services {

if (!class_exists(CosmosEndpointTransition::class, false)) {
    /**
     * Counted, not exercised. These tests ask whether a request REACHES the
     * service, so the service itself must not run.
     */
    final class CosmosEndpointTransition
    {
        public const AUDIT_ACTION = 'admin_cosmos_endpoint_switch';

        /** @return array{ok: bool, reason: string, review_id: string} */
        public static function review(int $chainId, string $targetUrl, int $operatorId): array
        {
            \BccEndpointAdminState::$reviewCalls[] = ['chain' => $chainId, 'target' => $targetUrl];

            return ['ok' => true, 'reason' => 'ready', 'review_id' => 'stub-review-id'];
        }

        /** @return array{ok: bool, reason: string, verified_network: string|null, failed_followups: list<string>} */
        public static function execute(int $chainId, string $reviewId, int $operatorId): array
        {
            \BccEndpointAdminState::$executeCalls[] = ['chain' => $chainId, 'review' => $reviewId];

            return ['ok' => true, 'reason' => 'switched', 'verified_network' => 'cosmoshub-4', 'failed_followups' => []];
        }
    }
}

}
