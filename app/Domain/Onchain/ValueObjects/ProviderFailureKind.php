<?php
/**
 * The closed vocabulary of reasons a provider request CHARGED the circuit
 * breaker — chain-agnostic, transport-level, and nothing else.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * Run 8 (2026-09-09) re-opened chain 8's breaker with eight charges and left
 * no durable explanation of what caused them. PR 7.7's `cw_last_error` was
 * correctly NULL — every CosmWasm enumeration request had succeeded — so the
 * charges came from somewhere the path-by-path telemetry does not reach. That
 * approach is incomplete by construction: attribution lived in individual
 * fetcher paths while the breaker is shared by nine chains and five
 * subsystems, so any request class without its own hook charges invisibly.
 *
 * This vocabulary sits at the SHARED boundary instead, so every charge is
 * explainable regardless of which caller produced it.
 *
 * ── WHY EXACTLY THESE THREE ─────────────────────────────────────────────
 * They are not a wish-list; they are the complete, MEASURED set of outcomes
 * {@see \BCC\Trust\Onchain\Support\ApiRetry} charges the breaker for. Driving
 * the real retry loop against a scripted wire (BreakerAttributionTest):
 *
 *   | outcome                    | charges | token          |
 *   |----------------------------|---------|----------------|
 *   | HTTP 429                   | 1       | rate_limited   |
 *   | HTTP >= 500 (incl. 501)    | 4       | http_5xx       |
 *   | WP_Error (wire failure)    | 4       | transport      |
 *   | HTTP 4xx other than 429    | 0       | — not recorded |
 *   | HTTP 3xx                   | 0       | — not recorded |
 *   | HTTP 2xx                   | 0       | records SUCCESS|
 *
 * A fourth token would therefore describe an outcome that cannot charge, and
 * a missing one would leave a charge unexplainable. The set is closed because
 * the charging rule is closed.
 *
 * ── WHAT THIS IS NOT ────────────────────────────────────────────────────
 * ⚠ NOT a classification verdict. A contract answering "I do not support that
 * query" is an APPLICATION answer: `ApiRetry`'s `application_error` opt-in
 * makes it skip the breaker entirely, and its telemetry is the contract row's
 * `probes_failed`. Recording it here would blame a provider for a healthy
 * contract's legitimate refusal.
 *
 * ⚠ NOT a superset of {@see CosmwasmEnumerationFailure}. That one names the
 * OUTCOME OF AN ENUMERATION READ (seven tokens, including `malformed_json`
 * and `unexpected_response`, neither of which charges the breaker). This one
 * names WHY A CHARGE HAPPENED. They overlap in three literals by necessity —
 * the same three wire outcomes — and `BreakerAttributionTest` pins those
 * literals byte-for-byte so the two vocabularies can never silently drift.
 * They are deliberately NOT merged: one is domain-specific and richer, the
 * other is chain-agnostic and exactly as wide as the charging rule.
 *
 * ⚠ `timeout` and `dns` are absent ON PURPOSE, for the same reason
 * {@see CosmwasmEnumerationFailure} never emits them: WordPress collapses
 * both into one `WP_Error`, and choosing between them would be the
 * fabricated diagnosis this whole effort exists to replace.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class ProviderFailureKind
{
    /** The provider asked us to slow down (HTTP 429). Charges ONCE — not retried. */
    public const RATE_LIMITED = 'rate_limited';

    /** The provider answered with a server error (HTTP >= 500, including 501). */
    public const HTTP_5XX = 'http_5xx';

    /** The request never completed at the wire (WP_Error). */
    public const TRANSPORT = 'transport';

    /**
     * Every token this attribution may ever hold.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::RATE_LIMITED,
            self::HTTP_5XX,
            self::TRANSPORT,
        ];
    }

    /** PURE. Is this one of the bounded tokens? */
    public static function isValid(string $kind): bool
    {
        return in_array($kind, self::all(), true);
    }

    /**
     * PURE. Name a charging outcome from the two structured facts the
     * transport already has.
     *
     * ⚠ THE RESPONSE BODY IS NOT A PARAMETER. A mapper that cannot see the
     * prose cannot leak it — the same discipline
     * {@see CosmwasmEnumerationFailure::fromResult()} uses.
     *
     * ⚠ RETURNS NULL FOR ANYTHING THAT DOES NOT CHARGE, so a caller cannot
     * accidentally attribute a non-429 4xx, a 3xx or a success. Null is the
     * honest answer for "this was not a provider failure", and it is what the
     * eight domain-level charge sites outside `ApiRetry` supply, because an
     * empty validator index or a caught exception has no wire outcome to name
     * and guessing one would be a fabricated diagnosis.
     *
     * @param  bool $transportFailure true when the transport returned a WP_Error
     * @param  int  $httpStatus       0 when there was no HTTP response at all
     */
    public static function fromOutcome(bool $transportFailure, int $httpStatus): ?string
    {
        if ($transportFailure) {
            return self::TRANSPORT;
        }
        if ($httpStatus === 429) {
            return self::RATE_LIMITED;
        }
        if ($httpStatus >= 500) {
            return self::HTTP_5XX;
        }

        return null;
    }

    /**
     * PURE. One short operator-facing sentence for a bounded token.
     *
     * Never interpolates upstream text: the token is the whole input, and an
     * unknown token gets a generic sentence rather than being echoed back.
     */
    public static function sentence(string $kind): string
    {
        switch ($kind) {
            case self::RATE_LIMITED:
                return 'The chain node asked us to slow down.';
            case self::HTTP_5XX:
                return 'The chain node reported a server error.';
            case self::TRANSPORT:
                return 'The connection to the chain node did not complete.';
            default:
                return 'The connection to the chain node failed.';
        }
    }

    /** The longest token, for any caller sizing a bounded store. */
    public static function maxLength(): int
    {
        $max = 0;
        foreach (self::all() as $kind) {
            $max = max($max, strlen($kind));
        }

        return $max;
    }
}
