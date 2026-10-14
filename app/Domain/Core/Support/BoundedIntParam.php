<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parse a request integer that has a real bound, REFUSING anything outside it.
 *
 * ── ⚠⚠ WHY THIS EXISTS, AND WHY IT IS NOT A CLAMP ───────────────────────
 * `GET /bcc/v1/members` declares `per_page` with `'minimum' => 1` and
 * `'maximum' => 50` and a `'sanitize_callback' => 'absint'`. The bound did
 * not bind, and the sanitizer is why:
 *
 *   `WP_REST_Request::sanitize_params()` substitutes `rest_parse_request_arg`
 *   — the function that enforces a schema — ONLY for an argument that
 *   declares no `sanitize_callback`. An argument that declares one is opted
 *   OUT of schema enforcement entirely.
 *
 * Measured on WordPress 6.7.2 before this change: `?per_page=100000`
 * answered **200**, reported `per_page: 100000`, and issued a real
 * `LIMIT 100000` — on a route whose `permission_callback` is
 * `__return_true`. The handler clamped the LOWER bound only.
 *
 * ⚠⚠ `absint` made it worse than an absent check, because it REWRITES
 * invalid input into valid input: `absint('-5') === 5`. A negative page size
 * was not rejected, it was silently served as 5. Any parser that runs after
 * `absint` has already lost the information it needs. That is why the route
 * now keeps the raw scalar and judges it here.
 *
 * ── ⚠ WHY NOT REUSE THE EXISTING HELPERS ────────────────────────────────
 * §11 scan: `WatchingService::clampPageSize()` and the two `clampLimit()`
 * methods all **clamp** — `max(1, min($max, $x))`. That is a different
 * contract, and the right one for their own surfaces. §4's `/members` entry
 * says *"Errors: invalid `page` / `per_page`"*, so this route owes the
 * caller a refusal, not a quiet substitution. Bending a clamp into a
 * rejection would change behaviour for every existing caller of those
 * helpers. Nothing in the codebase refused a bound before this class.
 *
 * ── ⚠ WHY A REFUSAL AND NOT A SCHEMA VALIDATOR ──────────────────────────
 * Adding `'validate_callback' => 'rest_validate_request_arg'` would enforce
 * the bound, but `WP_REST_Request::has_valid_params()` collects every
 * validator error and returns one `WP_Error` whose code is ALWAYS
 * `rest_invalid_param` — it discards a custom code. The contract names the
 * error for this route, so the refusal has to come from the handler, where
 * the house envelope can carry the house code.
 */
final class BoundedIntParam
{
    /**
     * A digit run longer than this cannot be a page or a page size, and
     * `(int)` would silently saturate at `PHP_INT_MAX`.
     */
    private const MAX_DIGITS = 10;

    /**
     * @param  mixed    $raw the parameter as the request holds it — an int
     *                       when it came from a schema `default`, otherwise
     *                       the query string's own text
     * @param  int      $min inclusive
     * @param  int|null $max inclusive, or null where the contract sets none
     * @return int|null the value, or NULL meaning "refuse this request"
     */
    public static function parse($raw, int $min, ?int $max): ?int
    {
        if (is_int($raw)) {
            // A schema `default` arrives already typed. Still range-checked:
            // a default outside its own declared bound is a bug worth
            // surfacing rather than serving.
            return self::inRange($raw, $min, $max);
        }

        if (!is_string($raw)) {
            // Arrays, booleans, floats, null. A float is deliberately here
            // and not truncated: `per_page=2.7` is not a page size, and
            // `absint` turning it into 2 is the behaviour this class exists
            // to stop. Note a float only reaches this method if something
            // upstream pre-cast it; over HTTP every value arrives as text.
            return null;
        }

        // ⚠ No trim. ` 20` is malformed input, not 20 with decoration, and
        // accepting it would mean two spellings of the same request.
        // ctype_digit('') is false, so the empty string refuses here too.
        if (!ctype_digit($raw)) {
            return null;
        }

        if (strlen($raw) > self::MAX_DIGITS) {
            return null;
        }

        return self::inRange((int) $raw, $min, $max);
    }

    private static function inRange(int $value, int $min, ?int $max): ?int
    {
        if ($value < $min) {
            return null;
        }
        if ($max !== null && $value > $max) {
            return null;
        }

        return $value;
    }

    /**
     * A `sanitize_callback` that PRESERVES what the caller sent.
     *
     * ⚠ Its job is the opposite of a normal sanitizer's. It exists so the
     * argument keeps a `sanitize_callback` — which is what stops WordPress
     * substituting `rest_parse_request_arg` and answering
     * `rest_invalid_param` instead of the contract's code — while handing
     * {@see self::parse()} the untouched text to judge. Casting or
     * normalising here would reintroduce exactly the `absint` defect.
     *
     * @param  mixed $value
     * @return int|string the original scalar, or '' for anything else
     */
    public static function keepScalar($value)
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) ? $value : '';
    }
}
