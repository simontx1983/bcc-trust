<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Support\CosmosEndpointVerifier;
use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MOVING A COSMOS CHAIN TO A DIFFERENT APPROVED ENDPOINT — DELIBERATELY,
 * ONCE, AND ONLY BY A PERSON.
 *
 * ── WHY THIS IS NOT A MIGRATION ─────────────────────────────────────────
 * The obvious implementation is a versioned migration that runs on activation
 * and repoints the chain row. That would be wrong in three separate ways:
 *
 *   - it would fire on DEPLOY, so the endpoint would change at whatever
 *     moment a release happened, with no operator watching;
 *   - it would run on every environment that received the code, including
 *     production, whose discovery is deliberately disabled;
 *   - it would have no confirmation step, so a plan reviewed against one
 *     state would execute against another.
 *
 * So the switch is an explicit administrator action: capability, POST, a
 * scoped nonce, and a CONFIRMATION DIGEST computed from the state that was
 * reviewed. {@see execute()} recomputes that digest against live state and
 * refuses if it moved.
 *
 * ── WHAT A TRANSITION ACTUALLY HAS TO DO ────────────────────────────────
 * Almost nothing, which is the point. `pagination.key` values are minted BY A
 * NODE and are the only stored artefacts that a different endpoint may reject
 * or misread; everything else on the chain is a fact about the CHAIN.
 *
 *   CLEARED — per-family `contracts_cursor` (opaque, node-minted)
 *   CLEARED — checkpoint `cw_code_cursor` (same, when set)
 *   KEPT    — classifications and their reasons, probe evidence,
 *             classifier_version, confirmed/probable verdicts,
 *             `cw_max_code_id` (a code id is a chain fact, not a node
 *             artefact), retry_count / next_attempt_at, contract metadata,
 *             every collection row
 *
 * ⚠ `CosmwasmClassifier::VERSION` IS NOT BUMPED. It is the pending predicate
 * in six queries; touching it would requeue hundreds of families and throw
 * away evidence that cost real provider requests, to solve a problem an
 * endpoint change does not create.
 */
final class CosmosEndpointTransition
{
    public const AUDIT_ACTION = 'admin_cosmos_endpoint_switch';

    /**
     * Describe what a transition WOULD do, plus a digest of the state it was
     * computed against.
     *
     * @return array{
     *   ok: bool, reason: string, chain_id: int, slug: string,
     *   from: string|null, to: string|null, from_role: string|null,
     *   to_role: string|null, cursor_families: int, cursor_code_ids: list<int>,
     *   code_cursor_set: bool, watermark: int|null, digest: string
     * }
     */
    public static function plan(int $chainId, string $targetUrl): array
    {
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return self::emptyPlan($chainId, '', 'unknown_chain');
        }

        $slug = (string) ($chain->slug ?? '');
        if (!CosmosEndpointPolicy::isGoverned($slug)) {
            return self::emptyPlan($chainId, $slug, 'not_governed');
        }

        $from = CosmosEndpointPolicy::normalize((string) ($chain->rest_url ?? ''));
        $to   = CosmosEndpointPolicy::normalize($targetUrl);

        if ($to === null) {
            return self::emptyPlan($chainId, $slug, 'target_malformed');
        }
        if (!CosmosEndpointPolicy::isApproved($slug, $to)) {
            return self::emptyPlan($chainId, $slug, 'target_not_approved');
        }
        if ($from !== null && $from === $to) {
            return self::emptyPlan($chainId, $slug, 'already_current');
        }

        $codeIds    = CosmwasmCodeFamilyRepository::openContractCursorCodeIds($chainId);
        $checkpoint = \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::get($chainId);
        $codeCursor = is_object($checkpoint) ? (string) ($checkpoint->cw_code_cursor ?? '') : '';
        $watermark  = is_object($checkpoint) ? (int) ($checkpoint->cw_max_code_id ?? 0) : null;

        return [
            'ok'              => true,
            'reason'          => 'ready',
            'chain_id'        => $chainId,
            'slug'            => $slug,
            'from'            => $from,
            'to'              => $to,
            'from_role'       => $from !== null ? CosmosEndpointPolicy::roleFor($slug, $from) : null,
            'to_role'         => CosmosEndpointPolicy::roleFor($slug, $to),
            'cursor_families' => count($codeIds),
            'cursor_code_ids' => $codeIds,
            'code_cursor_set' => $codeCursor !== '',
            'watermark'       => $watermark,
            'digest'          => self::digest($chainId, $from, $to, $codeIds, $codeCursor !== ''),
        ];
    }

    /**
     * PURE. A fingerprint of everything the operator was shown.
     *
     * ⚠ THE CODE IDS ARE IN THE DIGEST, NOT JUST THEIR COUNT. Two families
     * finishing while two others stall would leave the count at two and the
     * plan stale — a confirmation that only checked "still 2?" would pass and
     * clear rows nobody reviewed.
     *
     * @param list<int> $codeIds
     */
    public static function digest(
        int $chainId,
        ?string $from,
        ?string $to,
        array $codeIds,
        bool $codeCursorSet
    ): string {
        sort($codeIds);

        return substr(hash('sha256', implode('|', [
            $chainId,
            (string) $from,
            (string) $to,
            implode(',', $codeIds),
            $codeCursorSet ? '1' : '0',
        ])), 0, 32);
    }

    /**
     * Perform the switch, or refuse.
     *
     * ⚠ FAILS CLOSED ON A MOVED WORLD. The plan is recomputed here and its
     * digest compared with the one the operator confirmed. Anything that
     * changed in between — a cursor appearing, a family completing, the chain
     * being repointed by someone else — aborts before a single write.
     *
     * ⚠ THE NEW ENDPOINT IS VERIFIED BEFORE THE ROW MOVES, not after. Writing
     * first and checking second would leave the chain pointed at an unproven
     * host if the check failed.
     *
     * ⚠ THE WHOLE OPERATION RUNS UNDER THE DISCOVERY WORKER'S OWN LOCK.
     * {@see \BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker} takes the
     * non-blocking advisory lock `bcc_cosmwasm_chain_<id>` before it writes
     * any cursor. Holding the SAME lock here means the plan is recomputed,
     * compared with the reviewed digest, and acted on while no worker can add
     * or advance a cursor underneath it — without it, a cursor written between
     * the digest check and the clear would be wiped unreviewed or left behind
     * to be misread by the new endpoint. Contended → refuse, never wait: an
     * operator can simply press the button again once the pass finishes.
     *
     * @return array{ok: bool, reason: string, cleared_families: int, code_cursor_cleared: bool, verified_network: string|null}
     */
    public static function execute(
        int $chainId,
        string $targetUrl,
        string $confirmedDigest,
        int $actorId
    ): array {
        $lock = \BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker::ADVISORY_LOCK_PREFIX . $chainId;
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lock, 0)) {
            return self::result(false, 'lock_contended');
        }

        try {
            return self::executeLocked($chainId, $targetUrl, $confirmedDigest, $actorId);
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock);
        }
    }

    /**
     * The body of {@see execute()}. Callable ONLY with the worker lock held.
     *
     * @return array{ok: bool, reason: string, cleared_families: int, code_cursor_cleared: bool, verified_network: string|null}
     */
    private static function executeLocked(
        int $chainId,
        string $targetUrl,
        string $confirmedDigest,
        int $actorId
    ): array {
        $plan = self::plan($chainId, $targetUrl);
        if (!$plan['ok']) {
            return self::result(false, $plan['reason']);
        }
        if (!hash_equals($plan['digest'], $confirmedDigest)) {
            // The reviewed state and the live state disagree.
            return self::result(false, 'plan_stale');
        }

        // Prove the destination before committing to it. A probe object is
        // used rather than the stored row precisely because the row has not
        // moved yet.
        $probe = (object) [
            'id'       => $chainId,
            'slug'     => $plan['slug'],
            'rest_url' => $plan['to'],
        ];
        $verification = CosmosEndpointVerifier::verify($probe, false);
        if (!$verification['ok']) {
            return self::result(false, 'target_' . $verification['reason']);
        }

        $moved = ChainRepository::updateRestUrl($chainId, (string) $plan['to']);
        if (!$moved) {
            return self::result(false, 'write_failed');
        }

        // ── post-write verification ─────────────────────────────────────
        $after = ChainRepository::getById($chainId);
        $now   = $after !== null ? CosmosEndpointPolicy::normalize((string) ($after->rest_url ?? '')) : null;
        if ($now !== $plan['to']) {
            return self::result(false, 'post_write_mismatch');
        }

        $cleared = CosmwasmCodeFamilyRepository::clearContractCursors($chainId);
        if ($cleared < 0) {
            return self::result(false, 'cursor_clear_failed');
        }

        $codeCursorCleared = false;
        if ($plan['code_cursor_set']) {
            $codeCursorCleared = \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::requestCwBackfillRestart(
                $chainId,
                'endpoint_changed'
            );
        }

        // ⚠ Every cursor identified in the plan must be gone. A partial clear
        // is worse than none: the walk would resume from a key the new
        // endpoint may reject and silently truncate the family.
        $remaining = CosmwasmCodeFamilyRepository::countOpenContractCursors($chainId);
        if ($remaining !== 0) {
            return self::result(false, 'cursors_remain');
        }

        // ⚠ The breaker is chain-keyed, so its counter, open state and
        // attribution were all earned by the endpoint just replaced. Left in
        // place they would keep counting toward opening the breaker on the NEW
        // provider, and the admin page would describe a host that has not yet
        // served one request. Cleared AFTER the row moved and the cursors were
        // proven gone, so a refusal earlier in this method leaves it intact.
        $breakerCleared = \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::forgetForEndpointChange($chainId);

        // The complete normalized identity the chain now answers to — scheme,
        // host, effective port, base path and expected network.
        $endpointFp = CosmosEndpointPolicy::fingerprint($plan['slug'], (string) $plan['to']);

        self::audit(
            $chainId,
            $plan,
            $verification['network'],
            $cleared,
            $codeCursorCleared,
            $actorId,
            $endpointFp,
            $breakerCleared
        );

        return [
            'ok'                  => true,
            'reason'              => 'switched',
            'cleared_families'    => $cleared,
            'code_cursor_cleared' => $codeCursorCleared,
            'verified_network'    => $verification['network'],
        ];
    }

    /**
     * ⚠ HOSTS AND COUNTS ONLY. No cursor value, no contract address, no
     * provider sentence — an audit row is durable and widely readable, and
     * the point of the row is WHO changed WHAT, not what the node said.
     *
     * @param array<string, mixed> $plan
     */
    private static function audit(
        int $chainId,
        array $plan,
        ?string $network,
        int $cleared,
        bool $codeCursorCleared,
        int $actorId,
        ?string $endpointFp,
        bool $breakerCleared
    ): void {
        \BCC\Trust\Onchain\Admin\AdminActionSupport::audit(
            self::AUDIT_ACTION,
            'chain',
            $chainId,
            [
                'from'                => $plan['from'],
                'to'                  => $plan['to'],
                'to_role'             => $plan['to_role'],
                'verified_network'    => $network,
                'cleared_families'    => $cleared,
                'code_cursor_cleared' => $codeCursorCleared,
                'watermark_kept'      => $plan['watermark'],
                'digest'              => $plan['digest'],
                'actor'               => $actorId,
                // Bounded, non-secret: a 16-hex hash of the normalized
                // identity. Lets a later reader prove WHICH endpoint the chain
                // was moved to without storing anything a provider returned.
                'endpoint_fp'         => $endpointFp,
                'breaker_cleared'     => $breakerCleared,
            ]
        );
    }

    /**
     * @return array{ok: bool, reason: string, chain_id: int, slug: string, from: null, to: null, from_role: null, to_role: null, cursor_families: int, cursor_code_ids: list<int>, code_cursor_set: bool, watermark: null, digest: string}
     */
    private static function emptyPlan(int $chainId, string $slug, string $reason): array
    {
        return [
            'ok'              => false,
            'reason'          => $reason,
            'chain_id'        => $chainId,
            'slug'            => $slug,
            'from'            => null,
            'to'              => null,
            'from_role'       => null,
            'to_role'         => null,
            'cursor_families' => 0,
            'cursor_code_ids' => [],
            'code_cursor_set' => false,
            'watermark'       => null,
            'digest'          => '',
        ];
    }

    /**
     * @return array{ok: bool, reason: string, cleared_families: int, code_cursor_cleared: bool, verified_network: null}
     */
    private static function result(bool $ok, string $reason): array
    {
        return [
            'ok'                  => $ok,
            'reason'              => $reason,
            'cleared_families'    => 0,
            'code_cursor_cleared' => false,
            'verified_network'    => null,
        ];
    }
}
