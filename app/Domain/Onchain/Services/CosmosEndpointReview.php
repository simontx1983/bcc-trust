<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Log\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The pending endpoint-switch review for one operator.
 *
 * ── WHY A SERVER-SIDE RECORD AND NOT A FORM FIELD ───────────────────────
 * The switch has to prove that the operator confirmed a plan computed against
 * a specific stored state. The obvious way — round-trip the incumbent through
 * a hidden input — is forbidden here twice over:
 *
 *   - `wp_bcc_chains.rest_url` is a TAINTED CREDENTIAL COLUMN for
 *     `scripts/endpoint-exposure-guard.php`. Rendering it fails the build, and
 *     a hand-edited or legacy incumbent may carry userinfo or a query string.
 *   - {@see \BCC\Trust\Onchain\Support\EndpointDescriptor::identity()} is
 *     explicitly forbidden from any output path, and it normalises, so it is
 *     not byte-exact anyway.
 *
 * So nothing endpoint-derived is rendered. What the form carries is a random
 * identifier; everything it stands for stays here.
 *
 * ── WHAT IS STORED, AND WHY EACH FIELD ──────────────────────────────────
 *   review_id     random, submitted in the confirmation POST. A REPLACEMENT
 *                 review mints a new one, which is what invalidates an older
 *                 confirmation screen even when its chain and target match.
 *   chain_id      so a review for one chain cannot switch another.
 *   target        the normalized, policy-approved target the operator read.
 *   slug          the reviewed chain identity. Policy is keyed on slug ALONE
 *                 (network, the approved endpoint set and the role all come
 *                 from `CosmosEndpointPolicy::POLICY[$slug]`), so this is the
 *                 whole of "which chain, on which network" as far as the
 *                 switch is concerned.
 *   network       `expectedNetwork($slug)` as it was at review time, stored so
 *                 a policy correction between review and submit is visible
 *                 rather than silently applied.
 *   is_active     a gated precondition, re-checked at write time.
 *   incumbent_fp  a salted HMAC of the RAW incumbent — never the value.
 *   incumbent_null  whether the column was NULL, which no fingerprint can say.
 *
 * ── ONE PENDING REVIEW PER OPERATOR, AND NO TOKEN IN A URL ──────────────
 * Keyed by operator, so the confirmation screen can find it without carrying
 * anything in the query string: a bearer value in a URL lands in history, in
 * a referer and in access logs. Two tabs reviewing two chains is not a
 * problem — the second mint replaces the first, and the stale tab's POST
 * carries a `review_id` that no longer exists.
 *
 * @package BCC\Trust\Onchain\Services
 */
final class CosmosEndpointReview
{
    /** Long enough to read a plan and press a button; short enough to expire. */
    private const TTL = 600;

    private const PREFIX = 'bcc_cosmos_ep_review_';

    /**
     * A salted, site-keyed fingerprint of a raw endpoint.
     *
     * ⚠⚠⚠ NEVER RENDER, LOG OR RETURN THIS. It is derived from a column the
     * exposure guard treats as credential-bearing. It exists so the stored
     * incumbent can be COMPARED across two requests without being carried.
     *
     * Salted rather than a bare hash on purpose: `CosmosEndpointPolicy::fingerprint()`
     * is an unsalted truncated sha256, and the exposure guard's own note warns
     * that if a credentialed Cosmos endpoint is ever approved, that becomes a
     * grindable digest of a credentialed URL. This must not inherit that.
     *
     * Returns '' for a null incumbent so the caller is forced to carry the
     * null-ness separately — '' is a legitimate HMAC input and would otherwise
     * collide with an empty stored string.
     */
    public static function fingerprint(?string $rawEndpoint): string
    {
        if ($rawEndpoint === null) {
            return '';
        }

        return hash_hmac('sha256', $rawEndpoint, wp_salt('bcc_cosmos_endpoint_review'));
    }

    /**
     * Record a review for this operator and return its identifier.
     *
     * Replaces any existing review for the operator, which is the mechanism
     * that retires an older confirmation screen.
     *
     * @param array{chain_id: int, target: string, slug: string, network: string|null, is_active: int, incumbent_fp: string, incumbent_null: bool} $review
     * @return string the review id to put in the confirmation form
     */
    public static function mint(int $operatorId, array $review): string
    {
        $reviewId = wp_generate_password(32, false);

        $review['review_id'] = $reviewId;
        $review['minted_at'] = time();

        set_transient(self::key($operatorId), $review, self::TTL);

        return $reviewId;
    }

    /**
     * The operator's pending review, or null when there is none.
     *
     * Read-only: the confirmation screen uses this, and a GET must not consume.
     *
     * @return array{chain_id: int, target: string, slug: string, network: string|null, is_active: int, incumbent_fp: string, incumbent_null: bool, review_id: string, minted_at: int}|null
     */
    public static function peek(int $operatorId): ?array
    {
        $stored = get_transient(self::key($operatorId));
        if (!is_array($stored) || !isset($stored['review_id'], $stored['chain_id'])) {
            return null;
        }

        /** @var array{chain_id: int, target: string, slug: string, network: string|null, is_active: int, incumbent_fp: string, incumbent_null: bool, review_id: string, minted_at: int} $stored */
        return $stored;
    }

    /**
     * Claim the review, so it cannot be used a second time.
     *
     * ⚠ TRUE MEANS THE RECORD IS GONE. The caller must treat FALSE as "do not
     * proceed" — not as "probably fine". A false here means either the id did
     * not match or the delete did not take, and in both cases a later arrival
     * could still consume the same review and perform a second provider
     * request and a second write.
     *
     * Serialisation comes from the caller: every use of a review contends on
     * `bcc_cosmos_endpoint_<chain_id>`, because the review names its chain. So
     * read-then-delete is atomic with respect to any other use of the same
     * record, and the second arrival finds nothing.
     *
     * @param string $reviewId as submitted in the confirmation POST
     */
    public static function consume(int $operatorId, string $reviewId): bool
    {
        $stored = self::peek($operatorId);
        if ($stored === null) {
            return false;
        }

        if ($reviewId === '' || !hash_equals((string) $stored['review_id'], $reviewId)) {
            return false;
        }

        if (delete_transient(self::key($operatorId)) !== true) {
            // It was there a statement ago. Either the store refused the
            // delete or something else took it; both mean this process cannot
            // claim exclusive use of the review.
            Logger::error('[bcc-trust] endpoint review could not be consumed', [
                'action'   => 'cosmos_endpoint_review_consume_failed',
                'chain_id' => (int) $stored['chain_id'],
                'operator' => $operatorId,
            ]);

            return false;
        }

        return true;
    }

    /** Drop the operator's pending review without consuming it as a claim. */
    public static function forget(int $operatorId): void
    {
        delete_transient(self::key($operatorId));
    }

    private static function key(int $operatorId): string
    {
        return self::PREFIX . $operatorId;
    }
}
