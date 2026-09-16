<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * DID AN ADMINISTRATOR PROVE *THIS EXACT* ENDPOINT, AND IS THE CHAIN STILL
 * POINTED AT IT?
 *
 * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────
 * {@see CosmosEndpointVerifier} can answer "is this endpoint an approved host
 * serving the expected network" — but only by talking to it. That answer is
 * needed in places that must NEVER talk to anything:
 *
 *   - the scanner panel and the chains page, on every render;
 *   - {@see \BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot}, which
 *     the status surface builds per page load;
 *   - {@see \BCC\Trust\Onchain\Workers\DiscoveryRunExecutor}, on EVERY CHUNK of
 *     a run — a live probe there would multiply one operator decision into
 *     hundreds of requests;
 *   - {@see \BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance}, a five-minute
 *     recurring sweep, where a probe would be unattended outbound traffic on a
 *     timer — the exact thing the manual-only discovery rule forbids.
 *
 * So the live proof is split from the standing answer. An explicit
 * administrator action proves the endpoint ONCE and records the proof here;
 * every other surface re-checks the RECORD, with no network and no writes.
 *
 * ── WHAT IS RECORDED IS AN IDENTITY, NOT A VERDICT ──────────────────────
 * The stored value is the endpoint FINGERPRINT that was proven — the hash of
 * the whole normalized tuple (scheme, host, effective port, base path,
 * expected network). {@see isAuthorized()} recomputes the fingerprint of the
 * endpoint the chain is configured with RIGHT NOW and compares.
 *
 * That is what makes this safe to keep without an expiry: it cannot outlive
 * its subject. Repoint the chain — by the audited switch, by a migration, by
 * hand in the database — and the configured fingerprint changes, the stored
 * one does not match it, and the chain is unverified again by construction
 * rather than by somebody remembering to invalidate a flag.
 *
 * ── THIS IS NOT A SECOND ALLOWLIST ──────────────────────────────────────
 * Every question about what an endpoint may be is delegated to
 * {@see CosmosEndpointPolicy}: governed-ness, normalization, approval,
 * expected network and the fingerprint itself. This class owns exactly one
 * fact — WHICH proven identity was last recorded for a chain — and re-asks the
 * policy for everything else on every read. There is no host, scheme, path or
 * network literal anywhere in this file.
 *
 * ── EVERY UNSURE ANSWER IS "NOT AUTHORIZED" ─────────────────────────────
 * No record, an unreadable record, a record for a different fingerprint, a
 * chain whose endpoint no longer normalizes, a chain whose endpoint is no
 * longer approved: all false. The only `true` is an exact match against a
 * currently-approved endpoint. `null` is reserved for "this chain is not
 * governed by endpoint policy", which leaves ungoverned Cosmos-family chains
 * behaving exactly as they did before any of this existed.
 */
final class CosmosEndpointAuthorization
{
    /**
     * Per-chain option holding the proven identity.
     *
     * An option rather than a transient: this is a recorded operator decision,
     * not a cache of reachability, and it must not evaporate mid-run and strand
     * a run an administrator legitimately started. The short, bounded cache of
     * REACHABILITY lives in {@see CosmosEndpointVerifier}, where it belongs.
     *
     * Never autoloaded — it is read on the discovery paths, not on every
     * request. Same shape as `bcc_onchain_last_success_<id>`.
     */
    private const OPTION_PREFIX = 'bcc_cosmos_endpoint_authz_';

    /**
     * Is the chain's CURRENTLY CONFIGURED endpoint one an administrator has
     * proven? PURE OF NETWORK — one option read, no HTTP, no writes.
     *
     * Shaped as the tri-state {@see CosmwasmScanEligibility::verdict()} takes
     * as its `$endpointVerified` argument, so this is passed straight in.
     *
     * @param object $chain a ChainRow-shaped projection (slug, rest_url, id)
     * @return bool|null null = not governed; true = proven and unchanged since
     */
    public static function isAuthorized(object $chain): ?bool
    {
        $slug = (string) ($chain->slug ?? '');
        if (!CosmosEndpointPolicy::isGoverned($slug)) {
            // Not our jurisdiction. Behaviour for this chain is unchanged.
            return null;
        }

        $chainId = (int) ($chain->id ?? 0);
        if ($chainId <= 0) {
            return false;
        }

        $url = trim((string) ($chain->rest_url ?? ''));

        // Ask the policy, every time, about the endpoint as it is configured
        // now — a recorded proof cannot make an unapproved host acceptable.
        $normalized = CosmosEndpointPolicy::normalize($url);
        if ($normalized === null || !CosmosEndpointPolicy::isApproved($slug, $normalized)) {
            return false;
        }

        $configured = CosmosEndpointPolicy::fingerprint($slug, $normalized);
        if ($configured === null) {
            return false;
        }

        $stored = self::storedFingerprint($chainId);

        return $stored !== null && hash_equals($configured, $stored);
    }

    /**
     * The fingerprint recorded for this chain, or null.
     *
     * Validated on the way out: a value that is not shaped like one of our
     * fingerprints is treated as absent rather than compared, so a corrupted
     * or hand-edited option cannot become a match for anything.
     */
    public static function storedFingerprint(int $chainId): ?string
    {
        if ($chainId <= 0) {
            return null;
        }

        $record = get_option(self::OPTION_PREFIX . $chainId, null);
        if (!is_array($record)) {
            return null;
        }

        $fingerprint = $record['fingerprint'] ?? null;
        if (!is_string($fingerprint) || !CosmosEndpointPolicy::isFingerprint($fingerprint)) {
            return null;
        }

        return $fingerprint;
    }

    /**
     * ⚠ LIVE. CONTACTS THE ENDPOINT. Call ONLY from an explicit, secured
     * administrator action — never from a render, a status page, a cron tick,
     * the maintenance sweep or a worker chunk.
     *
     * Proves the endpoint through {@see CosmosEndpointVerifier} (one request,
     * no retries, no circuit-breaker charge, no redirects) and records the
     * proven identity on success.
     *
     * ⚠ THE RECORD IS WRITTEN ONLY AFTER A PASS. There is deliberately no
     * branch that records a failure, and a failure does not clear an existing
     * record either: a momentary outage must not be able to revoke an
     * authorization and strand a run that is legitimately in flight. What
     * revokes an authorization is the endpoint CHANGING, which invalidates it
     * by fingerprint without anything being written at all.
     *
     * @param object $chain   a ChainRow-shaped projection
     * @param int    $actorId the administrator who asked; recorded for audit
     * @return array{ok: bool, reason: string, network: string|null, fingerprint: string|null}
     */
    public static function authorize(object $chain, int $actorId = 0): array
    {
        $verification = CosmosEndpointVerifier::verify($chain);

        if ($verification['ok'] && is_string($verification['fingerprint'])) {
            self::record(
                (int) ($chain->id ?? 0),
                $verification['fingerprint'],
                is_string($verification['network']) ? $verification['network'] : '',
                $actorId
            );
        }

        return $verification;
    }

    /**
     * Record a proven identity. The ONLY writer.
     *
     * Public because {@see \BCC\Trust\Onchain\Services\CosmosEndpointTransition}
     * verifies the destination as part of the switch itself and should not have
     * to prove the same endpoint twice — but it is reachable only from code
     * that has just completed a live verification.
     */
    public static function record(int $chainId, string $fingerprint, string $network, int $actorId): bool
    {
        if ($chainId <= 0 || !CosmosEndpointPolicy::isFingerprint($fingerprint)) {
            return false;
        }

        return update_option(
            self::OPTION_PREFIX . $chainId,
            [
                'fingerprint' => $fingerprint,
                'network'     => $network,
                'at'          => time(),
                'by'          => $actorId,
            ],
            false
        );
    }

    /**
     * Drop a chain's record.
     *
     * Not needed to invalidate an endpoint change — the fingerprint comparison
     * already does that — but an operator switching discovery OFF for a chain
     * is withdrawing the authorization, and leaving the proof behind would let
     * a later re-enable inherit a decision nobody re-made.
     */
    public static function forget(int $chainId): bool
    {
        if ($chainId <= 0) {
            return false;
        }

        return delete_option(self::OPTION_PREFIX . $chainId);
    }
}
