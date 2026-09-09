<?php
/**
 * The closed vocabulary for WHAT KIND of request charged the circuit breaker.
 *
 * ── WHY A CLASS AND NOT A PATH ──────────────────────────────────────────
 * The operator question after Run 8 was "what was BCC doing when the breaker
 * opened?" A URL would answer it — and a URL is exactly what must never be
 * stored: `rest.cosmos.directory/cosmoshub/cosmwasm/wasm/v1/code/565/contracts`
 * carries a provider name, a chain, a path and a contract-adjacent id, and
 * once in a durable store it is in every backup and every export.
 *
 * These three values answer the same question with no identifiers at all.
 * They are derived from options the transport ALREADY holds — never from the
 * URL, never from the response.
 *
 * ⚠ NO PROVIDER NAME, NO HOST, NO PATH, NO QUERY, NO CONTRACT ADDRESS. If a
 * future value cannot be produced without one of those, it does not belong
 * in this vocabulary.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class ProviderRequestClass
{
    /**
     * A CosmWasm smart query — a question addressed to a CONTRACT.
     *
     * Detected from the presence of the `application_error` option, which
     * {@see \BCC\Trust\Onchain\Fetchers\CosmosFetcher::lcdGetResult()} supplies
     * for smart queries and for nothing else. That option is already a
     * first-class part of the retry contract, so reading it invents no new
     * coupling and needs no URL.
     */
    public const SMART_QUERY = 'smart_query';

    /** Any single ordinary request through the retry loop. */
    public const STANDARD_REQUEST = 'standard_request';

    /** A same-host batch, which charges at most once for the whole batch. */
    public const BATCH_REQUEST = 'batch_request';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SMART_QUERY,
            self::STANDARD_REQUEST,
            self::BATCH_REQUEST,
        ];
    }

    /** PURE. Is this one of the bounded values? */
    public static function isValid(string $class): bool
    {
        return in_array($class, self::all(), true);
    }

    /** Shown when no bounded request class exists. */
    public const NOT_RECORDED_LABEL = 'Not recorded';

    /**
     * What an ADMINISTRATOR is shown for a stored value.
     *
     * ⚠ Same contract as {@see ProviderFailureKind::label()}: the raw value
     * is never rendered, and null or unrecognised means "not recorded" rather
     * than a guess. Legacy records and domain-level charges both land here.
     */
    public static function label(?string $class): string
    {
        if ($class === null || !self::isValid($class)) {
            return self::NOT_RECORDED_LABEL;
        }

        switch ($class) {
            case self::SMART_QUERY:
                return 'Smart contract query';
            case self::STANDARD_REQUEST:
                return 'Standard provider request';
            case self::BATCH_REQUEST:
                return 'Batch provider request';
            default:
                return self::NOT_RECORDED_LABEL;
        }
    }

    /**
     * PURE. Classify a single request from the retry OPTIONS alone.
     *
     * ⚠ THE URL IS NOT A PARAMETER, deliberately. The only signal read is
     * whether the caller opted into application-error handling, which is
     * already how the transport distinguishes a contract question from a
     * node question.
     *
     * @param array<string, mixed> $options the ApiRetry options array
     */
    public static function fromOptions(array $options): string
    {
        $isSmartQuery = isset($options['application_error'])
            && is_callable($options['application_error']);

        return $isSmartQuery ? self::SMART_QUERY : self::STANDARD_REQUEST;
    }
}
