<?php
/**
 * FrontendOrigin — the single parser for BCC_FRONTEND_ORIGIN.
 *
 * The constant is a comma-separated allowlist whose entries are either:
 *   - an exact origin string (scheme + host + optional port), or
 *   - a regex pattern prefixed with "regex:", matched case-SENSITIVELY and
 *     force-anchored against a whole request Origin. Used for Vercel preview
 *     URLs, and ONLY in the one shape isSafePreviewPattern() accepts (F-121):
 *       regex:^https://bcc-frontend-[a-z0-9]{9}-<team-slug>\.vercel\.app$
 *     Any other regex entry is IGNORED and logged. Git-branch aliases must
 *     be exact (non-regex) entries.
 *
 *     The pattern is wrapped as `^(?:…)$` at match time, so an entry written
 *     without anchors still cannot match a substring. See match() for why
 *     requiring operators to supply their own anchors is not sufficient.
 *
 * Before this class the constant was parsed in four places, and only one
 * of them understood the "regex:" prefix:
 *
 *   CorsHandler::resolveAllowedOrigin()         stripped it   (correct)
 *   JwtToken::audienceAllowlist()               did not
 *   FrontendRedirect::firstOrigin()             did not
 *   PolkadotSignatureVerifier::routeUrl()       did not (bcc-core)
 *
 * A "regex:" entry in first position therefore leaked a raw pattern into
 * places that need a usable URL: the JWT `aud` claim, password-reset and
 * email-verification links, and the internal wallet-verify callout.
 *
 * The distinction this class enforces:
 *   - Anything that needs a USABLE ORIGIN (a URL base, an audience) must
 *     use exactOrigins()/canonical() — regex entries can never serve.
 *   - Only Origin-header MATCHING may consider regex entries, via
 *     matches().
 *
 * @package BCC\Trust\Core\Support
 * @since   2026-08-19 (domain cutover hardening)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class FrontendOrigin
{
    private const REGEX_PREFIX = 'regex:';

    /**
     * Raw, trimmed, non-empty entries in declaration order.
     *
     * @return list<string>
     */
    private static function entries(): array
    {
        if (!defined('BCC_FRONTEND_ORIGIN')) {
            return [];
        }
        $raw = (string) constant('BCC_FRONTEND_ORIGIN');
        if ($raw === '') {
            return [];
        }

        $entries = array_map('trim', explode(',', $raw));

        return array_values(array_filter($entries, static fn(string $e): bool => $e !== ''));
    }

    /**
     * Exact origins only — every entry that is NOT a regex pattern,
     * in declaration order.
     *
     * This is the allowlist for anything requiring a real origin: the
     * JWT `aud` claim and its verification set, redirect bases, and
     * outbound internal calls.
     *
     * @return list<string>
     */
    public static function exactOrigins(): array
    {
        $exact = [];
        foreach (self::entries() as $entry) {
            if (!self::isPattern($entry)) {
                $exact[] = $entry;
            }
        }

        return $exact;
    }

    /**
     * Regex patterns with the "regex:" prefix stripped, in declaration
     * order. Only Origin-header matching should consult these.
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        $patterns = [];
        foreach (self::entries() as $entry) {
            if (!self::isPattern($entry)) {
                continue;
            }
            $pattern = substr($entry, strlen(self::REGEX_PREFIX));
            if (!self::isSafePreviewPattern($pattern)) {
                self::reportUnsafePattern($pattern);
                continue;
            }
            $patterns[] = $pattern;
        }

        return $patterns;
    }

    /**
     * The ONLY regex shape the allowlist accepts (F-121): a Vercel preview
     * deployment of ONE project in ONE team.
     *
     *   ^https://<project>-[a-z0-9]{N}-<team-slug>\.vercel\.app$
     *
     *   <project>   literal, lowercase labels joined by single hyphens, with
     *               no label equal to "git"
     *   [a-z0-9]{N} the deployment hash: hyphen-free, EXACT length, 8 ≤ N ≤ 16
     *   <team-slug> literal, the Vercel scope slug that owns the project
     *
     * ## Why anything looser is refused
     * Vercel preview hosts are `<project>-<hash>-<team>.vercel.app` and
     * git-branch aliases are `<project>-git-<branch>-<team>.vercel.app`. An
     * attacker controls their OWN project name, branch name and team slug,
     * and can register any team slug still free. So:
     *   - a hyphen-capable slot (`[a-z0-9-]+`, `.*`) lets their host swallow
     *     an extra label and end in our team slug, e.g. via a team named
     *     `x-<our-team>`;
     *   - a hyphen-free but variable-length slot (`[a-z0-9]+`) still matches
     *     "git". Project `bcc-frontend` + branch `phillip` + team
     *     `simon-s-projects` gives `bcc-frontend-git-phillip-simon-s-projects
     *     .vercel.app`, which the old documented example accepted.
     * With an exact-length random-hash slot and literals everywhere else, an
     * attacker's host can match only if Vercel's RANDOM hash happens to equal
     * a label of our project name. A `git` label in <project> would reopen
     * the branch-alias path, so it is refused too.
     *
     * Exact (non-regex) entries are never affected, so the production origin
     * cannot be locked out by this guard. A refused pattern matches nothing:
     * the preview loses CORS, a visible and safe failure.
     */
    public static function isSafePreviewPattern(string $pattern): bool
    {
        $label = '[a-z0-9]+';
        $slug  = $label . '(?:-' . $label . ')*';
        $shape = '#^\^https://(?<project>' . $slug . ')-\[a-z0-9\]\{(?<n>[0-9]{1,2})\}-(?<team>' . $slug . ')\\\\\.vercel\\\\\.app\$$#D';

        if (preg_match($shape, $pattern, $m) !== 1) {
            return false;
        }
        $n = (int) $m['n'];
        if ($n < 8 || $n > 16) {
            return false;
        }

        return !in_array('git', explode('-', $m['project']), true);
    }

    /** @var array<string, true> patterns already reported this request */
    private static array $reported = [];

    private static function reportUnsafePattern(string $pattern): void
    {
        if (isset(self::$reported[$pattern])) {
            return;
        }
        self::$reported[$pattern] = true;

        if (class_exists(\BCC\Core\Log\Logger::class)) {
            \BCC\Core\Log\Logger::error(
                '[bcc-trust] BCC_FRONTEND_ORIGIN regex entry REFUSED (F-121): it does not match the only safe shape '
                . '^https://<project>-[a-z0-9]{N}-<team-slug>\.vercel\.app$ (literal project without a "git" label, '
                . 'exact N in 8..16, literal team slug). It is ignored and matches nothing.',
                ['pattern' => $pattern]
            );
        }
    }

    /**
     * The canonical frontend origin — the FIRST EXACT entry.
     *
     * Deliberately skips leading regex entries rather than taking
     * position [0] blindly: a pattern is not a usable URL, and callers
     * of this method are all building one.
     */
    public static function canonical(): ?string
    {
        return self::exactOrigins()[0] ?? null;
    }

    /**
     * Resolve a request Origin against the allowlist.
     *
     * Exact matches are tried first so the fast path always wins; regex
     * entries are evaluated only on miss. A malformed pattern is silently
     * skipped (never matches) so a typo cannot break the whole allowlist.
     *
     * ## Why the pattern is force-anchored and matched case-sensitively
     *
     * A pattern is interpolated into a delimiter pair, so an entry written
     * without `^`/`$` matches a SUBSTRING. `regex:bcc-frontend-[a-z0-9]+-team
     * \.vercel\.app` would then accept
     * `https://bcc-frontend-abc-team.vercel.app.evil.test` — an
     * attacker-controlled lookalike host. Anchoring is not something an
     * operator should have to remember, so it is applied here.
     *
     * Requiring the operator to supply anchors would not be sufficient
     * either: `^https://a\.test|https://b\.test$` carries both anchors yet
     * still matches `https://a.test.evil.io`, because top-level alternation
     * binds looser than either anchor. Hence the non-capturing group — it is
     * load-bearing. Without it, `^…$` anchors only the first branch.
     * Wrapping an already-anchored pattern is harmless: anchors are
     * zero-width, so `^(?:^…$)$` matches exactly what `^…$` did.
     *
     * A consequence worth knowing: an unanchored entry now matches NOTHING
     * rather than matching too much. Both outcomes fail closed, and a
     * preview that stops working is a visible, safe failure.
     *
     * The `i` flag is deliberately absent. The exact path above is strict
     * (`in_array(…, true)`) and therefore case-sensitive; a case-insensitive
     * regex path would accept host spellings the exact path rejects and echo
     * the caller's raw bytes back in Access-Control-Allow-Origin.
     *
     * Same defect class as the `expanded_url` substring match in X share
     * verification, and the unbounded prefix match in the `rest_url` origin
     * filter (now BCC\Core\Support\HeadlessOrigin, which boundary-checks).
     *
     * @return string|null The matched origin to echo back, or null.
     */
    public static function match(string $requestOrigin): ?string
    {
        $requestOrigin = trim($requestOrigin);
        if ($requestOrigin === '') {
            return null;
        }

        if (in_array($requestOrigin, self::exactOrigins(), true)) {
            return $requestOrigin;
        }

        foreach (self::patterns() as $pattern) {
            if (@preg_match('#^(?:' . $pattern . ')$#', $requestOrigin) === 1) {
                return $requestOrigin;
            }
        }

        return null;
    }

    /** True when the allowlist is unset or empty (CORS/aud disabled). */
    public static function isConfigured(): bool
    {
        return self::entries() !== [];
    }

    private static function isPattern(string $entry): bool
    {
        return str_starts_with($entry, self::REGEX_PREFIX);
    }
}
