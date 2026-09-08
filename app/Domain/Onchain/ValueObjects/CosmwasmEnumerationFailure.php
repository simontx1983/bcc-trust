<?php
/**
 * Bounded vocabulary for a CosmWasm ENUMERATION failure.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * The 2026-09-08 breaker audit could not answer the only question that
 * mattered — "what actually failed?" — because the enumeration path left no
 * usable trace. Run 6 opened the chain-8 circuit after eight consecutive
 * failures inside a 23-second window, and the only surviving evidence was
 * the breaker counter itself. `wp_bcc_chain_checkpoints.cw_last_error` was
 * NULL: the code-tail path recorded nothing at all, and the code-page path
 * stored a PROVIDER-CONTROLLED sentence, which is not a diagnosis and is not
 * safe to render.
 *
 * ── WHAT MAY BE STORED ──────────────────────────────────────────────────
 * A token from {@see codes()} and NOTHING else. Never a response body, a
 * header, a URL, an exception message, a query payload, or any prose the
 * contract or the provider chose. The token is derived from two facts BCC
 * already owns — the classifier's `error_kind` and the HTTP status — so the
 * upstream text never has to be read, let alone persisted.
 *
 * ⚠ IT NEVER GUESSES. `timeout` and `dns` are in the vocabulary because the
 * field must be able to carry them, but {@see fromResult()} does not emit
 * them: WordPress collapses both into one `WP_Error` whose only distinguishing
 * evidence is the cURL sentence inside its message, and reading that message
 * to manufacture a finer code would be exactly the fabricated diagnosis this
 * class exists to prevent. Transport faults are reported as `transport` until
 * a real signal exists to split them. See PR 7.6 follow-up note.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

use BCC\Trust\Onchain\Services\CosmwasmClassifier;

if (!defined('ABSPATH')) {
    exit;
}

final class CosmwasmEnumerationFailure
{
    /** The provider asked us to slow down. */
    public const RATE_LIMITED = 'rate_limited';

    /** The node answered with a server error. */
    public const HTTP_5XX = 'http_5xx';

    /** The request never completed at the wire. */
    public const TRANSPORT = 'transport';

    /** Reserved: a transport fault proven to be a timeout. Never guessed. */
    public const TIMEOUT = 'timeout';

    /** Reserved: a transport fault proven to be name resolution. Never guessed. */
    public const DNS = 'dns';

    /** A 2xx whose body could not be parsed as the expected JSON. */
    public const MALFORMED_JSON = 'malformed_json';

    /** Anything else — including a 4xx that is not a rate limit. */
    public const UNEXPECTED_RESPONSE = 'unexpected_response';

    /**
     * Every token this field may ever hold.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return [
            self::RATE_LIMITED,
            self::HTTP_5XX,
            self::TRANSPORT,
            self::TIMEOUT,
            self::DNS,
            self::MALFORMED_JSON,
            self::UNEXPECTED_RESPONSE,
        ];
    }

    /** PURE. Is this one of the bounded tokens? */
    public static function isValid(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }

    /**
     * PURE. Map an enumeration result to one bounded token.
     *
     * Reads ONLY the two structured facts. The result's `message` key is
     * deliberately not a parameter — a mapper that cannot see the prose
     * cannot leak it.
     *
     * Ordering matters: 429 is checked before the generic 4xx branch, and
     * the HTTP status is checked before the classifier kind, because a node
     * that answered 503 is better described by its status than by the
     * kind bucket that status fell into.
     */
    public static function fromResult(string $errorKind, int $httpCode): string
    {
        if ($httpCode === 429) {
            return self::RATE_LIMITED;
        }
        if ($httpCode >= 500) {
            return self::HTTP_5XX;
        }
        if ($httpCode === 0 && $errorKind === CosmwasmClassifier::KIND_TRANSPORT) {
            // ⚠ NOT `timeout`, NOT `dns` — see the class docblock.
            return self::TRANSPORT;
        }
        if ($errorKind === CosmwasmClassifier::KIND_MALFORMED) {
            return self::MALFORMED_JSON;
        }

        return self::UNEXPECTED_RESPONSE;
    }

    /**
     * PURE. One short, operator-facing sentence for a bounded token.
     *
     * Never interpolates upstream text: the token is the whole input.
     */
    public static function sentence(string $code): string
    {
        switch ($code) {
            case self::RATE_LIMITED:
                return 'The chain node asked us to slow down.';
            case self::HTTP_5XX:
                return 'The chain node reported a server error.';
            case self::TRANSPORT:
                return 'The connection to the chain node did not complete.';
            case self::TIMEOUT:
                return 'The chain node did not answer in time.';
            case self::DNS:
                return 'The chain node address could not be resolved.';
            case self::MALFORMED_JSON:
                return 'The chain node answered with data we could not read.';
            case self::UNEXPECTED_RESPONSE:
                return 'The chain node returned an unexpected response.';
            default:
                // A token from a newer build — say nothing we cannot stand behind.
                return 'The last enumeration attempt did not succeed.';
        }
    }
}
