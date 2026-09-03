<?php

declare(strict_types=1);

/**
 * The one place collection metadata is validated before it can be stored.
 *
 * ── EVERY VALUE HERE IS UNTRUSTED EVIDENCE ──────────────────────────────
 * Names, symbols, descriptions and image URLs arrive from chain metadata a
 * contract author controls. They are not facts about the collection; they
 * are claims. This class decides what may be persisted at all. It decides
 * nothing about trust: no value here can verify a collection, choose a gate,
 * approve a marketplace link or resolve an identity.
 *
 * ── ABSENCE IS NORMAL, AND ABSENCE IS SQL NULL ──────────────────────────
 * Most collections answer only some of these. The rule throughout is that an
 * unknown value becomes `null` — never `''`, never `0`, never a placeholder.
 * `''` and `null` are the SAME absence at the write boundary
 * ({@see \BCC\Trust\Onchain\Repositories\CollectionRepository}), because the
 * live table already holds 119 Cosmos rows whose `image_url` is `''` and a
 * `COALESCE(image_url, …)` can never replace an empty string.
 *
 * ── AND NO MARKET DATA, EVER ────────────────────────────────────────────
 * ⚠ BCC is a community and trust platform, not a marketplace. There is no
 * validator here for a price, a currency, a volume, a listed count, a sale or
 * a royalty, because no such value may be captured. Collection SIZE is
 * allowed — it describes the collection, not its market performance.
 * {@see marketFieldNames()} is the canonical list the parsers strip, and the
 * suite asserts a realistic price payload dies at that boundary.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class CollectionMetadataRules
{
    /** `collection_name VARCHAR(200)`. */
    public const MAX_NAME = 200;

    /** `collection_symbol VARCHAR(32)` — display only. */
    public const MAX_SYMBOL = 32;

    /** `chain_description TEXT`, bounded well below the column ceiling. */
    public const MAX_DESCRIPTION = 2000;

    /** `image_url VARCHAR(500)`. */
    public const MAX_IMAGE_URL = 500;

    /** `marketplace_url VARCHAR(500)`. */
    public const MAX_MARKETPLACE_URL = 500;

    /**
     * `total_supply INT UNSIGNED` ceiling.
     *
     * ⚠ The column is NOT widened by this PR. A collection that genuinely
     * exceeds this is rejected and stored as "unknown" rather than clamped —
     * a clamped 4,294,967,295 would be a fabricated fact.
     */
    public const MAX_TOTAL_SUPPLY = 4294967295;

    /** Bounded reason codes. Never free text, never an upstream body. */
    public const REASON_ABSENT       = 'absent';
    public const REASON_MALFORMED    = 'malformed';
    public const REASON_NEGATIVE     = 'negative';
    public const REASON_OVERFLOW     = 'overflow';
    public const REASON_TOO_LONG     = 'too_long';
    public const REASON_UNSAFE_SCHEME = 'unsafe_scheme';

    /**
     * The market fields that may never be captured, stored or exposed.
     *
     * Used by the parsers to strip an upstream payload and by the tests to
     * prove a realistic price response cannot survive. Adding a name here is
     * how the prohibition is extended; removing one is a product change, not
     * a refactor.
     *
     * @return list<string>
     */
    public static function marketFieldNames(): array
    {
        return [
            'floor_price',
            'floorPrice',
            'floor_currency',
            'floorCurrency',
            'price',
            'priceCurrency',
            'total_volume',
            'totalVolume',
            'volume',
            'oneDayVolume',
            'sevenDayVolume',
            'listed_percentage',
            'listedPercentage',
            'listedCount',
            'numListed',
            'sales_count',
            'salesCount',
            'itemsSold',
            'lastSale',
            'last_sale',
            'lastSalePrice',
            'royalty_percentage',
            'royaltyPercentage',
            'royaltyRate',
            'sellerFeeBasisPoints',
            'marketCap',
            'openSeaMetadata',
        ];
    }

    /**
     * Strip every market key from an upstream payload.
     *
     * Applied at the PARSER boundary, before anything downstream can read the
     * array — so a market value is gone before a writer could ever see it,
     * rather than merely unused. Recursive, because providers nest these
     * under objects like `openSeaMetadata`.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function stripMarketFields(array $payload): array
    {
        $banned = array_flip(self::marketFieldNames());
        $clean  = [];

        foreach ($payload as $key => $value) {
            if (isset($banned[(string) $key])) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::stripMarketFields($value) : $value;
        }

        return $clean;
    }

    /**
     * Plain display text: no markup, no control characters, bounded.
     *
     * Returns `null` for anything that reduces to nothing, so an empty
     * upstream string and a missing key are indistinguishable downstream —
     * which is the point.
     */
    public static function sanitizeText(mixed $raw, int $maxLength): ?string
    {
        if (!is_string($raw)) {
            // A numeric name/symbol is legal upstream; anything else is not
            // text and is treated as absent rather than coerced.
            if (is_int($raw) || is_float($raw)) {
                $raw = (string) $raw;
            } else {
                return null;
            }
        }

        // Markup first: strip_tags on already-decoded entities would leave
        // "&lt;script&gt;" intact as visible text, which is fine, but a real
        // tag must never survive.
        $text = strip_tags($raw);

        // Control characters, including the C1 range and the BOM. \p{C} also
        // covers unassigned/format codepoints such as RLO, which can reverse
        // rendered text and is a known display-spoofing vector.
        $text = preg_replace('/\p{C}+/u', ' ', $text) ?? $text;

        // Collapse whitespace so a wall of newlines cannot pad a value past
        // a length check while rendering as something short.
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            $text = rtrim(mb_substr($text, 0, $maxLength, 'UTF-8'));
        }

        return $text === '' ? null : $text;
    }

    /** Collection symbol — display only, never an identity. */
    public static function sanitizeSymbol(mixed $raw): ?string
    {
        return self::sanitizeText($raw, self::MAX_SYMBOL);
    }

    /** Collection name — display only, never an identity. */
    public static function sanitizeName(mixed $raw): ?string
    {
        return self::sanitizeText($raw, self::MAX_NAME);
    }

    /** Blockchain collection description — untrusted until approved. */
    public static function sanitizeDescription(mixed $raw): ?string
    {
        return self::sanitizeText($raw, self::MAX_DESCRIPTION);
    }

    /**
     * Current collection size.
     *
     * ── WHAT THIS IS ────────────────────────────────────────────────────
     * The number of items that currently exist. Not maximum supply, not
     * items sold, not listed items, not market availability.
     *
     * ── AND WHAT IT REFUSES ─────────────────────────────────────────────
     * Anything that is not a plain non-negative decimal integer. No clamping:
     * a value above the `INT UNSIGNED` ceiling is REJECTED, because storing
     * 4,294,967,295 for "more than that" invents a fact. No coercion: `"12abc"`,
     * `"1e5"`, `"0x10"`, `" 12 "` with inner junk and floats are malformed,
     * not 12. And a failure is never 0 — an unavailable count is unknown.
     *
     * @return array{value: int|null, reason: string|null}
     *         `value` is the count, or null when it may not be stored;
     *         `reason` is a bounded code explaining a null.
     */
    public static function validateTotalSupply(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['value' => null, 'reason' => self::REASON_ABSENT];
        }

        if (is_bool($raw) || is_array($raw) || is_object($raw)) {
            return ['value' => null, 'reason' => self::REASON_MALFORMED];
        }

        if (is_float($raw)) {
            // 12.0 is arguably 12, but a float here means the upstream shape
            // is not the integer count we think it is. Refuse rather than round.
            return ['value' => null, 'reason' => self::REASON_MALFORMED];
        }

        if (is_int($raw)) {
            if ($raw < 0) {
                return ['value' => null, 'reason' => self::REASON_NEGATIVE];
            }
            if ($raw > self::MAX_TOTAL_SUPPLY) {
                return ['value' => null, 'reason' => self::REASON_OVERFLOW];
            }
            return ['value' => $raw, 'reason' => null];
        }

        $text = trim((string) $raw);
        if ($text === '') {
            return ['value' => null, 'reason' => self::REASON_ABSENT];
        }

        if (str_starts_with($text, '-')) {
            // Distinguish a real negative from junk so the reason code is honest.
            return ['value' => null, 'reason' => preg_match('/^-\d+$/', $text) === 1
                ? self::REASON_NEGATIVE
                : self::REASON_MALFORMED];
        }

        // Digits only. No sign, no separators, no exponent, no hex, no spaces.
        if (preg_match('/^\d+$/', $text) !== 1) {
            return ['value' => null, 'reason' => self::REASON_MALFORMED];
        }

        // Compare as a string first: a 30-digit value would overflow PHP_INT_MAX
        // and (int) would silently saturate, turning overflow into a plausible
        // number. Length then value keeps the comparison exact.
        $trimmed = ltrim($text, '0');
        $trimmed = $trimmed === '' ? '0' : $trimmed;
        $ceiling = (string) self::MAX_TOTAL_SUPPLY;

        if (strlen($trimmed) > strlen($ceiling)
            || (strlen($trimmed) === strlen($ceiling) && strcmp($trimmed, $ceiling) > 0)) {
            return ['value' => null, 'reason' => self::REASON_OVERFLOW];
        }

        return ['value' => (int) $trimmed, 'reason' => null];
    }

    /**
     * Collection image URL.
     *
     * HTTPS only — an `http://` image is a mixed-content warning on every
     * page that renders it, and `data:`/`javascript:` are injection vectors
     * dressed as images. `ipfs://` is refused here rather than rewritten:
     * choosing a gateway is a product decision, not a sanitizer's.
     */
    public static function sanitizeImageUrl(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $url = trim($raw);
        if ($url === '') {
            return null;
        }

        // Reject control characters outright rather than stripping them: a URL
        // containing one is malformed, and repairing it invents a destination.
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            return null;
        }

        if (strlen($url) > self::MAX_IMAGE_URL) {
            return null;
        }

        return $url;
    }

    /**
     * Treat `''` and `null` as the same absence.
     *
     * ⚠ This is the rule that makes the empty-string image rows recoverable.
     * `COALESCE(image_url, %s)` leaves `''` in place forever because `''` is
     * not NULL; every writer therefore normalizes THROUGH this helper before
     * the column is touched.
     */
    public static function absentToNull(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && trim($value) === '') {
            return null;
        }
        return $value;
    }

    /**
     * Should a newly-observed value replace what is already stored?
     *
     * NO when the new value is absent and the stored one is not: an
     * unavailable answer is not evidence the old one became wrong, and a
     * provider that times out must not blank a confirmed collection size.
     * A caller wanting a real clear does it explicitly, not by passing null.
     */
    public static function shouldOverwrite(mixed $existing, mixed $incoming): bool
    {
        $existing = self::absentToNull($existing);
        $incoming = self::absentToNull($incoming);

        if ($incoming === null) {
            return false;
        }

        return $existing !== $incoming;
    }
}
