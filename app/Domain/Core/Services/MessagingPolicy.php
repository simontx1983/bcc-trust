<?php
/**
 * MessagingPolicy — the authorization policy contexts for the DM
 * surface. Pure constants + matrix documentation; the ONE evaluator
 * that applies them is MessagesService::evaluatePolicy (all gate
 * resolution needs the PeepSo/blocks/friends seams that live there).
 *
 * There is deliberately NO boolean bypass flag anywhere on the DM
 * surface — every send path names its context and the matrix below is
 * the single place the rules differ. Modeled on PublicAllPolicy's
 * pure-class shape (caller resolves inputs, policy decides).
 *
 * ┌──────────────────────────────┬────────────┬──────────────────────┐
 * │ Rule                         │ NORMAL_DM  │ FIRST_CLAIM_RELEASE  │
 * ├──────────────────────────────┼────────────┼──────────────────────┤
 * │ Sender authenticated/valid   │ enforce    │ enforce (at delivery)│
 * │ Sender not suspended/banned  │ enforce    │ enforce (suppress)   │
 * │ Sender chat_enabled          │ enforce    │ submission-time only │
 * │ Mutual block (shielded 404)  │ enforce    │ enforce (suppress)   │
 * │ Recipient chat_enabled       │ enforce    │ BYPASS               │
 * │ Recipient friends_only       │ enforce    │ BYPASS               │
 * │ Self-message                 │ enforce    │ enforce (suppress)   │
 * │ Body validation (trim/5000)  │ enforce    │ re-checked (suppress)│
 * │ Rate limit 30/300s           │ enforce    │ n/a (system-paced)   │
 * └──────────────────────────────┴────────────┴──────────────────────┘
 *
 * VALIDATOR_FIRST_CLAIM_RELEASE exists for exactly one moment: the
 * one-time backlog release when a validator page receives its FIRST
 * verified operator claim. Messages queued against the page before any
 * operator existed deliver even if the new operator's chat_enabled is
 * off or friends_only is on — those two preferences are bypassed for
 * the backlog only. Every safety rule (blocks, suspension/ban, deleted
 * sender, self-message, content validity) still applies and maps to a
 * terminal `suppressed` queue state, never a delivery. After the
 * release, all new messages and all replies run NORMAL_DM — no ongoing
 * exemption of any kind.
 *
 * @package BCC\Trust\Core\Services
 * @since v1.52 (2026-07, validator messaging)
 */

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class MessagingPolicy
{
    /** Ordinary member↔member DM — every gate enforced. */
    public const NORMAL_DM = 'normal_dm';

    /**
     * One-time first-claim backlog delivery to a validator page's
     * first verified operator — recipient chat_enabled + friends_only
     * bypassed; every safety rule still enforced (→ suppression).
     */
    public const VALIDATOR_FIRST_CLAIM_RELEASE = 'validator_first_claim_release';

    private function __construct()
    {
        // Static-only.
    }
}
