<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\CosmosEndpointVerifier;
use BCC\Trust\Onchain\Support\EndpointDescriptor;
use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The audited Cosmos REST-endpoint switch.
 *
 * ⚠ THE ONLY WAY THE ENDPOINT MOVES. Deliberately not a migration, not an
 * activation hook and not a cron task: a provider change happens at a moment a
 * person chose, on an environment they chose, against a plan they read.
 *
 * ── THREE STEPS, AND ONLY ONE OF THEM RENDERS ───────────────────────────
 *   1. {@see review()}   POST. Pre-flight, no provider call. Records what the
 *                        operator is about to confirm and returns an id.
 *   2. the confirmation screen is a GET that renders {@see plan()}. It writes
 *                        NOTHING — which is why minting happens in (1) and not
 *                        here, where a render would have had a side effect.
 *   3. {@see execute()}  POST. Takes the lock, re-validates every binding,
 *                        claims the review, proves the destination, and swaps.
 *
 * ── WHAT IT PROTECTS ────────────────────────────────────────────────────
 * The endpoint is not scanner state. `CosmosFetcher` dials it for validators,
 * delegations and CW-721 holdings, and `BlockchainQueryService` unions the
 * policy's approved hosts into the wallet SSRF allowlist — so an unaudited
 * repoint would let one subsystem follow a host another refuses.
 *
 *   - the target is governed, normalized and EXACTLY approved (never a prefix
 *     or suffix match), and `normalize()` rejects credentials and queries;
 *   - its identity is PROVEN LIVE before the row moves, with the verifier's
 *     cache bypassed and off `ApiRetry` so asking permission cannot charge the
 *     breaker;
 *   - the write is a compare-and-swap on the reviewed state, so it cannot land
 *     on a row that moved underneath it;
 *   - the row is read back, and the breaker is only forgotten once the new
 *     endpoint is CONFIRMED;
 *   - every write is audited with hosts, roles and counts — never a cursor, a
 *     contract or a provider sentence.
 *
 * ── WHAT IT NO LONGER DOES ──────────────────────────────────────────────
 * It used to clear CosmWasm contract cursors under the discovery worker's own
 * lock, because node-minted `pagination.key` values are the only stored
 * artefacts a different endpoint can reject or misread. Every such cursor
 * belonged to the scanner, so with the scanner frozen there is nothing of that
 * kind left to clear: validators and holdings persist no cursor. The reviewed
 * digest that protected those cursors is replaced by the compare-and-swap,
 * which is stronger for the row and narrower in scope — see
 * {@see ChainRepository::updateRestUrl()}.
 *
 * @package BCC\Trust\Onchain\Services
 */
final class CosmosEndpointTransition
{
    public const AUDIT_ACTION = 'admin_cosmos_endpoint_switch';

    /**
     * This service's OWN lock.
     *
     * ⚠ It used to borrow the discovery worker's own advisory-lock constant, to
     * exclude that worker while cursors were cleared. There are no cursors now,
     * and borrowing a constant from a class being deleted is how the two became
     * coupled in the first place. The name is deliberately not repeated here:
     * the decoupling is asserted by a test that greps this file.
     *
     * It is still needed: the switch is a critical section over several writes
     * (the swap, the breaker, the audit) that two operators could interleave.
     * Non-blocking — contended refuses rather than waits, because an operator
     * can press the button again.
     */
    private const LOCK_PREFIX = 'bcc_cosmos_endpoint_';

    // ── Step 1: review ──────────────────────────────────────────────────

    /**
     * Record what the operator is about to confirm.
     *
     * No provider call: the proof belongs to {@see execute()}, where it is made
     * under the lock against the state that will actually be written.
     *
     * @return array{ok: bool, reason: string, review_id: string}
     */
    public static function review(int $chainId, string $targetUrl, int $operatorId): array
    {
        $plan = self::plan($chainId, $targetUrl);
        if (!$plan['ok']) {
            return ['ok' => false, 'reason' => $plan['reason'], 'review_id' => ''];
        }

        $reviewId = CosmosEndpointReview::mint($operatorId, [
            'chain_id'       => $chainId,
            'target'         => $plan['to'],
            'slug'           => $plan['slug'],
            'network'        => $plan['expected_network'],
            'is_active'      => $plan['is_active'],
            'incumbent_fp'   => $plan['incumbent_fp'],
            'incumbent_null' => $plan['incumbent_null'],
        ]);

        return ['ok' => true, 'reason' => 'ready', 'review_id' => $reviewId];
    }

    // ── Step 2: what the confirmation screen renders ─────────────────────

    /**
     * The reviewable plan. Pure read; contacts nothing.
     *
     * ⚠ `incumbent_fp` and `incumbent_raw` ARE NOT FOR DISPLAY. `rest_url` is a
     * tainted credential column for `scripts/endpoint-exposure-guard.php`, and a
     * hand-edited incumbent may carry userinfo or a query string. The renderer
     * uses `incumbent_display` — and `incumbent_shown_in_full` says whether the
     * full normalized form may be shown, which is true only when the incumbent
     * is itself policy-approved and therefore known credential-free.
     *
     * @return array{ok: bool, reason: string, chain_id: int, slug: string, is_active: int,
     *               to: string, to_role: string|null, expected_network: string|null,
     *               incumbent_display: string, incumbent_shown_in_full: bool,
     *               incumbent_normalized: string|null, incumbent_raw: string|null,
     *               incumbent_fp: string, incumbent_null: bool}
     */
    public static function plan(int $chainId, string $targetUrl): array
    {
        $chain = $chainId > 0 ? ChainRepository::getById($chainId) : null;
        if ($chain === null) {
            return self::emptyPlan($chainId, 'invalid_chain');
        }

        $slug = (string) ($chain->slug ?? '');
        if (!CosmosEndpointPolicy::isGoverned($slug)) {
            return self::emptyPlan($chainId, 'not_governed');
        }

        if ((int) ($chain->is_active ?? 0) !== 1) {
            return self::emptyPlan($chainId, 'chain_inactive');
        }

        $to = CosmosEndpointPolicy::normalize($targetUrl);
        if ($to === null) {
            return self::emptyPlan($chainId, 'target_malformed');
        }

        if (!CosmosEndpointPolicy::isApproved($slug, $to)) {
            return self::emptyPlan($chainId, 'target_not_approved');
        }

        $rawIncumbent  = $chain->rest_url ?? null;
        $incumbentNull = $rawIncumbent === null;
        $normalized    = $incumbentNull ? null : CosmosEndpointPolicy::normalize((string) $rawIncumbent);

        if ($normalized !== null && $normalized === $to) {
            return self::emptyPlan($chainId, 'already_current');
        }

        // Shown in full ONLY when the incumbent is itself approved: the policy
        // allowlist is closed and credential-free, so that is the one case we
        // can state is safe to print. Everything else is redacted to scheme and
        // host by the deny-by-default descriptor.
        $shownInFull = $normalized !== null && CosmosEndpointPolicy::isApproved($slug, $normalized);

        return [
            'ok'                      => true,
            'reason'                  => 'ready',
            'chain_id'                => $chainId,
            'slug'                    => $slug,
            'is_active'               => (int) ($chain->is_active ?? 0),
            'to'                      => $to,
            'to_role'                 => CosmosEndpointPolicy::roleFor($slug, $to),
            'expected_network'        => CosmosEndpointPolicy::expectedNetwork($slug),
            'incumbent_display'       => EndpointDescriptor::display($incumbentNull ? null : (string) $rawIncumbent),
            'incumbent_shown_in_full' => $shownInFull,
            'incumbent_normalized'    => $shownInFull ? $normalized : null,
            'incumbent_raw'           => $incumbentNull ? null : (string) $rawIncumbent,
            'incumbent_fp'            => CosmosEndpointReview::fingerprint($incumbentNull ? null : (string) $rawIncumbent),
            'incumbent_null'          => $incumbentNull,
        ];
    }

    // ── Step 3: the switch ───────────────────────────────────────────────

    /**
     * @return array{ok: bool, reason: string, verified_network: string|null, failed_followups: list<string>}
     */
    public static function execute(int $chainId, string $reviewId, int $operatorId): array
    {
        $lock = self::LOCK_PREFIX . $chainId;

        // ⚠ `acquire()` returns false both for "a peer holds it" and for a
        // driver error, so the refusal cannot distinguish them. Logged rather
        // than given a separate reason, because the operator's next move is the
        // same either way: press it again.
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lock, 0)) {
            Logger::warning('[bcc-trust] endpoint switch lock not acquired', [
                'action'   => 'cosmos_endpoint_switch_lock_contended',
                'chain_id' => $chainId,
            ]);

            return self::result(false, 'lock_contended');
        }

        try {
            return self::executeLocked($chainId, $reviewId, $operatorId);
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock);
        }
    }

    /**
     * Callable ONLY with this service's lock held.
     *
     * @return array{ok: bool, reason: string, verified_network: string|null, failed_followups: list<string>}
     */
    private static function executeLocked(int $chainId, string $reviewId, int $operatorId): array
    {
        $review = CosmosEndpointReview::peek($operatorId);
        if ($review === null) {
            return self::result(false, 'review_token_invalid');
        }

        if ($reviewId === '' || !hash_equals((string) $review['review_id'], $reviewId)) {
            return self::result(false, 'review_token_invalid');
        }

        if ((int) $review['chain_id'] !== $chainId) {
            return self::result(false, 'review_chain_mismatch');
        }

        // Re-read, so every binding below is compared against the row as it is
        // NOW rather than as the review remembers it.
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return self::result(false, 'invalid_chain');
        }

        $slug = (string) ($chain->slug ?? '');

        // ── IDENTITY ────────────────────────────────────────────────────
        // Policy is keyed on SLUG ALONE: the expected network, the approved
        // endpoint set and the role all come from `POLICY[$slug]`. So slug plus
        // `is_active` is the whole of the stored state this decision rests on,
        // and `network` is carried as well so a policy correction between review
        // and submit is refused rather than silently applied. `chain_type` is
        // deliberately absent — nothing in this path reads it.
        $identityMoved = $slug !== (string) $review['slug']
            || (int) ($chain->is_active ?? 0) !== (int) $review['is_active']
            || CosmosEndpointPolicy::expectedNetwork($slug) !== $review['network'];

        if ($identityMoved) {
            return self::result(false, 'identity_changed');
        }

        // ── THE INCUMBENT ───────────────────────────────────────────────
        $rawIncumbent  = $chain->rest_url ?? null;
        $incumbentNull = $rawIncumbent === null;

        if ($incumbentNull !== (bool) $review['incumbent_null']) {
            return self::result(false, 'from_mismatch');
        }

        if (!$incumbentNull) {
            $fpNow = CosmosEndpointReview::fingerprint((string) $rawIncumbent);
            if (!hash_equals((string) $review['incumbent_fp'], $fpNow)) {
                return self::result(false, 'from_mismatch');
            }
        }

        // ── POLICY, AGAINST THE FRESH ROW ───────────────────────────────
        if (!CosmosEndpointPolicy::isGoverned($slug)) {
            return self::result(false, 'not_governed');
        }

        if ((int) ($chain->is_active ?? 0) !== 1) {
            return self::result(false, 'chain_inactive');
        }

        $to = (string) $review['target'];
        if (!CosmosEndpointPolicy::isApproved($slug, $to)) {
            return self::result(false, 'target_not_approved');
        }

        $normalizedIncumbent = $incumbentNull
            ? null
            : CosmosEndpointPolicy::normalize((string) $rawIncumbent);
        if ($normalizedIncumbent !== null && $normalizedIncumbent === $to) {
            return self::result(false, 'already_current');
        }

        // ── CLAIM THE REVIEW BEFORE ANY OUTBOUND REQUEST ────────────────
        // ⚠ ORDER IS THE POINT. Consuming after the proof would let a replayed
        // submission make a SECOND provider request before being refused. A
        // failed claim therefore costs zero provider calls and zero writes.
        if (!CosmosEndpointReview::consume($operatorId, $reviewId)) {
            return self::result(false, 'review_consume_failed');
        }

        // ── PROVE THE DESTINATION ───────────────────────────────────────
        // A probe object, not the stored row: the row has not moved yet. Cache
        // bypassed — a proof recorded earlier is not a proof made now.
        $probe = (object) ['id' => $chainId, 'slug' => $slug, 'rest_url' => $to];
        $verification = CosmosEndpointVerifier::verify($probe, false);
        if (!$verification['ok']) {
            return self::result(false, 'target_' . $verification['reason']);
        }

        $verifiedNetwork = isset($verification['network']) && is_string($verification['network'])
            ? $verification['network']
            : null;

        // ── THE SWAP ────────────────────────────────────────────────────
        $affected = ChainRepository::updateRestUrl($chainId, $to, [
            'rest_url'  => $incumbentNull ? null : (string) $rawIncumbent,
            'slug'      => $slug,
            'is_active' => (int) ($chain->is_active ?? 0),
        ]);

        if ($affected === -1) {
            // The statement did not report success. It may still have applied,
            // so this must not be reported as "nothing happened".
            Logger::error('[bcc-trust] endpoint switch write did not report success', [
                'action'   => 'cosmos_endpoint_switch_write_unconfirmed',
                'chain_id' => $chainId,
                'operator' => $operatorId,
            ]);

            return self::result(false, 'write_unconfirmed');
        }

        if ($affected === 0) {
            // Everything above matched a moment ago, so a racer landed between
            // the check and the write. One read to say which predicate lost.
            $after = ChainRepository::getById($chainId);
            $identityRaced = $after === null
                || (string) ($after->slug ?? '') !== $slug
                || (int) ($after->is_active ?? 0) !== (int) ($chain->is_active ?? 0);

            return self::result(false, $identityRaced ? 'identity_changed' : 'from_mismatch');
        }

        // ⚠ FROM HERE THE SWITCH HAS HAPPENED. Nothing below can un-happen it,
        // so nothing below may report a reason that means "nothing changed".
        // The chains cache was already busted inside updateRestUrl().

        $readBack  = ChainRepository::getById($chainId);
        $nowStored = $readBack === null
            ? null
            : CosmosEndpointPolicy::normalize((string) ($readBack->rest_url ?? ''));

        $confirmed = $readBack !== null && $nowStored === $to;

        $outcome = 'switched';
        if ($readBack === null) {
            $outcome = 'switched_unconfirmed';
        } elseif (!$confirmed) {
            $outcome = 'switched_then_superseded';
        }

        // ⚠ THE BREAKER IS NOT TOUCHED UNLESS THE ENDPOINT IS CONFIRMED.
        // Forgetting clears the counter, the open state and the half-open probe
        // for a chain — earned by whichever host is actually configured. If we
        // cannot say which host that is, clearing would discard a real signal
        // about a host that may still be serving.
        // ⚠ FALSE IS NOT A FAILURE. `forgetForEndpointChange()` answers "was
        // there anything to clear", and on a healthy chain there is not — so
        // treating false as a failed follow-up would warn the operator on every
        // ordinary switch. It is recorded as a FACT in the audit row. Only a
        // throw means the clear did not happen when it should have.
        $failed         = [];
        $breakerCleared = null;
        if ($confirmed) {
            try {
                $breakerCleared = \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::forgetForEndpointChange($chainId);
            } catch (\Throwable $e) {
                $failed[] = 'breaker';
                Logger::error('[bcc-trust] endpoint switch could not clear the breaker', [
                    'action'   => 'cosmos_endpoint_switch_breaker_clear_failed',
                    'chain_id' => $chainId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // ⚠ AUDITED ON EVERY POST-WRITE PATH, including the two where the row
        // could not be confirmed. A write that happened and was not recorded is
        // the worst of the outcomes, so the trace is written even when we cannot
        // describe the result confidently — `outcome` carries that uncertainty.
        if (!self::audit($chainId, $slug, $rawIncumbent, $to, $verifiedNetwork, $outcome, $failed, $operatorId)) {
            $failed[] = 'audit';
        }

        if ($outcome !== 'switched') {
            return self::result(true, $outcome, $verifiedNetwork, $failed);
        }

        return $failed === []
            ? self::result(true, 'switched', $verifiedNetwork, [])
            : self::result(true, 'switched_followups_failed', $verifiedNetwork, $failed);
    }

    /**
     * The durable row. HOSTS, ROLES AND COUNTS ONLY.
     *
     * No cursor value, no contract address, no provider sentence: an audit row
     * is durable and widely readable, and the point of it is who changed what.
     *
     * @param  list<string> $failed
     * @return bool whether the durable row was written
     */
    private static function audit(
        int $chainId,
        string $slug,
        ?string $rawFrom,
        string $to,
        ?string $verifiedNetwork,
        string $outcome,
        array $failed,
        int $operatorId
    ): bool {
        $meta = [
            // The normalized incumbent when it is approved and therefore
            // credential-free; otherwise scheme and host only. An audit row is
            // not a place to put a value we would refuse to render.
            'from'             => $rawFrom === null
                ? null
                : (CosmosEndpointPolicy::isApproved($slug, (string) CosmosEndpointPolicy::normalize($rawFrom))
                    ? CosmosEndpointPolicy::normalize($rawFrom)
                    : EndpointDescriptor::display($rawFrom)),
            'to'               => $to,
            'to_role'          => CosmosEndpointPolicy::roleFor($slug, $to),
            'verified_network' => $verifiedNetwork,
            'endpoint_fp'      => CosmosEndpointPolicy::fingerprint($slug, $to),
            'outcome'          => $outcome,
            'failed_followups' => $failed,
            'actor'            => $operatorId,
        ];

        $id = AuditLogger::logChecked(self::AUDIT_ACTION, $chainId, $meta, 'chain');

        Logger::info('[bcc-trust] admin action: ' . self::AUDIT_ACTION, array_merge(
            ['operator' => $operatorId, 'target_type' => 'chain', 'target_id' => $chainId],
            $meta
        ));

        if ($id === null) {
            Logger::error('[bcc-trust] endpoint switch audit row was not written', [
                'action'   => 'cosmos_endpoint_switch_audit_failed',
                'chain_id' => $chainId,
                'outcome'  => $outcome,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return array{ok: bool, reason: string, chain_id: int, slug: string, is_active: int,
     *               to: string, to_role: string|null, expected_network: string|null,
     *               incumbent_display: string, incumbent_shown_in_full: bool,
     *               incumbent_normalized: string|null, incumbent_raw: string|null,
     *               incumbent_fp: string, incumbent_null: bool}
     */
    private static function emptyPlan(int $chainId, string $reason): array
    {
        return [
            'ok'                      => false,
            'reason'                  => $reason,
            'chain_id'                => $chainId,
            'slug'                    => '',
            'is_active'               => 0,
            'to'                      => '',
            'to_role'                 => null,
            'expected_network'        => null,
            'incumbent_display'       => '',
            'incumbent_shown_in_full' => false,
            'incumbent_normalized'    => null,
            'incumbent_raw'           => null,
            'incumbent_fp'            => '',
            'incumbent_null'          => false,
        ];
    }

    /**
     * @param  list<string> $failed
     * @return array{ok: bool, reason: string, verified_network: string|null, failed_followups: list<string>}
     */
    private static function result(
        bool $ok,
        string $reason,
        ?string $verifiedNetwork = null,
        array $failed = []
    ): array {
        return [
            'ok'               => $ok,
            'reason'           => $reason,
            'verified_network' => $verifiedNetwork,
            'failed_followups' => $failed,
        ];
    }
}
