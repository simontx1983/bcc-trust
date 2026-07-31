<?php
/**
 * CapabilityShadow — log-only comparison between a live gate's verdict
 * and the shadow CapabilityResolver (Addendum A2 divergence logging).
 *
 * Called AFTER the live gate has decided, with that decision as
 * $liveAllowed. The shadow evaluation can never change the outcome:
 * every failure path here is swallowed — a broken shadow must never
 * break a live request. UNKNOWN resolver verdicts don't compare.
 *
 * In Phase 3 the delegated keys share their implementation with the
 * live gates, so a divergence log line means a later refactor split
 * them — this is a canary, and it should stay silent.
 *
 * @package BCC\Trust\Core\Services\Capability
 * @since Rank redesign Phase 3 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Capability;

if (!defined('ABSPATH')) {
    exit;
}

final class CapabilityShadow
{
    /**
     * @param array<string, mixed> $context
     */
    public static function observe(string $key, bool $liveAllowed, int $userId, array $context = []): void
    {
        try {
            $decision = \BCC\Trust\Core\Plugin::instance()
                ->capabilityResolver()
                ->can($userId, $key, $context);

            if (!$decision->isDefinitive()) {
                return;
            }

            if ($decision->isAllowed() !== $liveAllowed) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] capability_shadow_divergence', [
                    'key'            => $key,
                    'user_id'        => $userId,
                    'live_allowed'   => $liveAllowed,
                    'shadow_outcome' => $decision->outcome,
                    'shadow_reason'  => $decision->reason,
                    'shadow_source'  => $decision->source,
                ]);
            }
        } catch (\Throwable $e) {
            // Shadow must never break the live path. Swallow, best-effort log.
            try {
                \BCC\Core\Log\Logger::warning('[bcc-trust] capability_shadow_error', [
                    'key'   => $key,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {
                // Even logging failed — nothing left to do safely.
            }
        }
    }
}
