<?php
/**
 * CardIdAddressabilityTest — which spellings of `:id` resolve to which
 * `post_name` behind `GET /bcc/v1/cards/:type/:id`.
 *
 * Context. `sanitize_title()` percent-encodes every non-ASCII character, so a
 * page whose moniker carries an emoji is stored with a literal `%f0%9f…` in
 * `post_name` — 126 of the 2,806 `peepso-page` rows. Those pages had NO
 * reachable URL at all: the route's old `:id` pattern admitted no `%`, and the
 * `id` argument's old `sanitize_text_field` callback stripped percent octets
 * outright, turning the slug into a different and possibly real one.
 *
 * ⚠⚠ WHY THE FIXTURES BELOW ARE TABLES AND NOT IMPLEMENTATIONS.
 * The rule under test compares against `sanitize_title()` and replays
 * `get_page_by_path()`'s normalization through `sanitize_title_for_query()`,
 * and this suite runs with no WordPress core. Hand-written stubs would be
 * asserting the stubs' idea of percent-encoding rather than WordPress's — and
 * `tests/Stubs/canonical-backfill-stubs.php` already ships a crude GLOBAL
 * `sanitize_title` (`preg_replace('/[^a-z0-9]+/','-')`) that flattens an emoji
 * to a dash and would make every case here pass for the wrong reason. So both
 * stubs are LOOKUP TABLES of values captured from real WordPress **7.1.4**
 * (production's version; re-derived identically on 7.0.2), and each THROWS on
 * an input it has no recorded answer for. The authoritative re-derivation is
 * `scripts/tests/card-id-real-dispatch.php`.
 *
 * @package BCC\Trust\Tests\Unit
 * @since 2026-10-30 (C1 — percent-encoded validator slugs)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services {
    /**
     * `𝐥𝐞𝐬𝐧𝐢𝐤 | 𝐔𝐓𝐒𝐀` (page 4079) — a real 135-character stored slug whose
     * frontend spelling is 215 characters. It is the regression case for the
     * bound being applied to the resolved slug rather than to the URL
     * spelling; a 200-cap on the raw id made this page reachable by a direct
     * API call and unreachable through the site.
     */
    const BCC_LONG_SLUG = '%f0%9d%90%a5%f0%9d%90%9e%f0%9d%90%ac%f0%9d%90%a7%f0%9d%90%a2%f0%9d%90%a4'
        . '-%f0%9d%90%94%f0%9d%90%93%f0%9d%90%92%f0%9d%90%80-jackal-um9q4p';

    /**
     * Values captured from real WordPress 7.1.4 `sanitize_title()`.
     *
     * @return array<string, string>
     */
    function bcc_real_sanitize_title_table(): array
    {
        static $t = null;
        if ($t !== null) {
            return $t;
        }

        $long = BCC_LONG_SLUG;

        return $t = [
            // the C1 population: emoji monikers, percent-encoded on store
            rawurldecode('bonynode%f0%9f%92%9a-cosmos-zsyh4e')     => 'bonynode%f0%9f%92%9a-cosmos-zsyh4e',
            rawurldecode('%f0%9f%90%a0stake-fish-cosmos-2lcnj0')   => '%f0%9f%90%a0stake-fish-cosmos-2lcnj0',
            rawurldecode('chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k') => 'chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k',
            'bonynode%f0%9f%92%9a-cosmos-zsyh4e'                   => 'bonynode%f0%9f%92%9a-cosmos-zsyh4e',
            '%f0%9f%90%a0stake-fish-cosmos-2lcnj0'                 => '%f0%9f%90%a0stake-fish-cosmos-2lcnj0',

            // the 135-char slug, in both the decoded and stored spellings
            rawurldecode($long)                                    => $long,
            $long                                                  => $long,

            // plain ASCII (reached via the %7A alias case)
            'ztake-org-cosmos-54at8c'                              => 'ztake-org-cosmos-54at8c',
            'bonynode-cosmos'                                      => 'bonynode-cosmos',

            // the %25 family — canonical, because core protects existing octets
            'bonynode%25'                                          => 'bonynode%25',
            'bonynode%25f0%259f%2592%259a-cosmos-zsyh4e'           => 'bonynode%25f0%259f%2592%259a-cosmos-zsyh4e',

            // ⚠ the separator and space octets: canonical as SPELLING, which is
            // exactly why canonicality alone cannot protect the lookup
            'bonynode%2fadmin-cosmos-zsyh4e'                       => 'bonynode%2fadmin-cosmos-zsyh4e',
            'bonynode%20cosmos'                                    => 'bonynode%20cosmos',

            // refusals
            'bonynode/admin-cosmos-zsyh4e'                         => 'bonynode-admin-cosmos-zsyh4e',
            'bonynode cosmos'                                      => 'bonynode-cosmos',
            'bonynode%zz-cosmos-zsyh4e'                            => 'bonynodezz-cosmos-zsyh4e',
            'bonynode%'                                            => 'bonynode',

            // ⚠ NOT a core value. Real core returns '__bcc_probe__' unchanged;
            // this row deliberately differs so the anti-vacuity test can prove
            // THIS table resolved rather than the crude global stub.
            '__bcc_probe__'                                        => '__bcc_probe_sentinel__',
        ];
    }

    /**
     * Values captured from real WordPress 7.1.4 `sanitize_title_for_query()`,
     * i.e. the segments `get_page_by_path()` would actually search for.
     *
     * @return array<string, string>
     */
    function bcc_real_sanitize_title_for_query_table(): array
    {
        static $t = null;
        if ($t !== null) {
            return $t;
        }

        return $t = [
            'bonynode%F0%9F%92%9A-cosmos-zsyh4e'                   => 'bonynode%f0%9f%92%9a-cosmos-zsyh4e',
            '%F0%9F%90%A0stake-fish-cosmos-2lcnj0'                 => '%f0%9f%90%a0stake-fish-cosmos-2lcnj0',
            'chiter-in-cosmos-%E2%9A%9B-cosmos-95f92k'             => 'chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k',
            // core re-encodes with UPPERCASE hex before sanitising back down
            '%F0%9D%90%A5%F0%9D%90%9E%F0%9D%90%AC%F0%9D%90%A7%F0%9D%90%A2%F0%9D%90%A4'
                . '-%F0%9D%90%94%F0%9D%90%93%F0%9D%90%92%F0%9D%90%80-jackal-um9q4p' => BCC_LONG_SLUG,
            'ztake-org-cosmos-54at8c'                              => 'ztake-org-cosmos-54at8c',
            'bonynode-cosmos'                                      => 'bonynode-cosmos',
            'bonynode%25'                                          => 'bonynode%25',
            'bonynode%25f0%259f%2592%259a-cosmos-zsyh4e'           => 'bonynode%25f0%259f%2592%259a-cosmos-zsyh4e',
            // the %2f candidate splits into these two segments
            'bonynode'                                             => 'bonynode',
            'admin-cosmos-zsyh4e'                                  => 'admin-cosmos-zsyh4e',
            // the %20 candidate is REWRITTEN by core, which is the refusal
            'bonynode cosmos'                                      => 'bonynode-cosmos',
        ];
    }

    if (!function_exists('BCC\\Trust\\Core\\Services\\sanitize_title')) {
        function sanitize_title(string $title): string
        {
            $t = bcc_real_sanitize_title_table();
            if (!array_key_exists($title, $t)) {
                throw new \RuntimeException(
                    'No real-core sanitize_title() value recorded for ' . var_export($title, true)
                    . '. Re-derive it against production WordPress with'
                    . ' scripts/tests/card-id-real-dispatch.php --print-table and add it to the'
                    . ' table rather than guessing — a guessed value makes this suite assert nothing.'
                );
            }

            return $t[$title];
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\Services\\sanitize_title_for_query')) {
        function sanitize_title_for_query(string $title): string
        {
            $t = bcc_real_sanitize_title_for_query_table();
            if (!array_key_exists($title, $t)) {
                throw new \RuntimeException(
                    'No real-core sanitize_title_for_query() value recorded for '
                    . var_export($title, true) . '. This is the segment list'
                    . ' get_page_by_path() would search for — derive it, do not guess.'
                );
            }

            return $t[$title];
        }
    }
}

namespace BCC\Trust\Tests\Unit {

    use BCC\Trust\Core\REST\CardsEndpoint;
    use BCC\Trust\Core\Services\CardViewService;
    use BCC\Trust\Core\Support\BoundedIntParam;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class CardIdAddressabilityTest extends TestCase
    {
        private const EXPECTED_ROUTE_PATTERN = '[A-Za-z0-9%][A-Za-z0-9_%-]{0,599}';
        private const PRE_CHANGE_PATTERN     = '[A-Za-z0-9][A-Za-z0-9_-]{0,99}';

        private const STORED   = 'bonynode%f0%9f%92%9a-cosmos-zsyh4e';
        private const FRONTEND = 'bonynode%25F0%259F%2592%259A-cosmos-zsyh4e';

        /** The real 135-character stored slug of page 4079. */
        private const LONG_STORED = \BCC\Trust\Core\Services\BCC_LONG_SLUG;

        private static function slugFor(string $id): ?string
        {
            $m = new \ReflectionMethod(CardViewService::class, 'canonicalSlugFor');
            $m->setAccessible(true);

            /** @var string|null $r */
            $r = $m->invoke(null, $id);

            return $r;
        }

        /** The segments core would search for, via the production replay. */
        private static function segmentsFor(string $candidate): array
        {
            $m = new \ReflectionMethod(CardViewService::class, 'pathSegmentsFor');
            $m->setAccessible(true);

            /** @var list<string> $r */
            $r = $m->invoke(null, $candidate);

            return $r;
        }

        private static function routePattern(): string
        {
            /** @var string $p */
            $p = (new \ReflectionClass(CardsEndpoint::class))->getConstant('ID_PATTERN');

            return $p;
        }

        /** The frontend spelling of any stored slug: every `%` escaped once. */
        private static function asFrontendSends(string $storedSlug): string
        {
            return str_replace('%', '%25', $storedSlug);
        }

        // ──────────────────────────────────────────────────────────────
        // 0. Anti-vacuity
        // ──────────────────────────────────────────────────────────────

        /**
         * PHPUnit loads every test into one process, and
         * `tests/Stubs/canonical-backfill-stubs.php` declares a GLOBAL
         * `sanitize_title` that flattens an emoji to a dash. If that one were
         * resolving for CardViewService, every percent case below would pass
         * for the wrong reason. Unqualified calls inside
         * `BCC\Trust\Core\Services` resolve to the namespaced function first;
         * this asserts that is what actually happened.
         */
        public function testTheNamespacedRealCoreTablesAreTheOnesInForce(): void
        {
            self::assertSame(
                '__bcc_probe_sentinel__',
                \BCC\Trust\Core\Services\sanitize_title('__bcc_probe__'),
                'The global crude sanitize_title stub won resolution — the percent cases prove nothing.'
            );
            // and the query-context table is reachable, which is what the
            // structural check depends on
            self::assertSame(
                'bonynode',
                \BCC\Trust\Core\Services\sanitize_title_for_query('bonynode')
            );
        }

        /** A resolver that accepts everything, or refuses everything, is not one. */
        public function testTheResolverBothAcceptsAndRefuses(): void
        {
            self::assertSame('ztake-org-cosmos-54at8c', self::slugFor('ztake-org-cosmos-54at8c'));
            self::assertNull(self::slugFor('bonynode%zz-cosmos-zsyh4e'));
        }

        // ──────────────────────────────────────────────────────────────
        // 1. The stored spelling, and the spelling the frontend sends
        // ──────────────────────────────────────────────────────────────

        /**
         * @return array<string, array{string, string}>
         */
        public static function storedSpellingProvider(): array
        {
            return [
                'stored slug, lowercase hex' => [self::STORED, 'bonynode%f0%9f%92%9a-cosmos-zsyh4e'],
                'stored slug, uppercase hex' => ['bonynode%F0%9F%92%9A-cosmos-zsyh4e', 'bonynode%f0%9f%92%9a-cosmos-zsyh4e'],
                'mixed-case ASCII around the octets' => ['BonyNode%F0%9F%92%9A-cosmos-zsyh4e', 'bonynode%f0%9f%92%9a-cosmos-zsyh4e'],
                'LEADING emoji' => ['%f0%9f%90%a0stake-fish-cosmos-2lcnj0', '%f0%9f%90%a0stake-fish-cosmos-2lcnj0'],
                'LEADING emoji, uppercase hex' => ['%F0%9F%90%A0stake-fish-cosmos-2lcnj0', '%f0%9f%90%a0stake-fish-cosmos-2lcnj0'],
                'three-byte sequence (U+269B)' => ['chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k', 'chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k'],
                'three-byte sequence, uppercase hex' => ['chiter-in-cosmos-%E2%9A%9B-cosmos-95f92k', 'chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k'],
            ];
        }

        #[DataProvider('storedSpellingProvider')]
        public function testTheStoredSpellingResolvesToItsOwnPostName(string $id, string $expected): void
        {
            self::assertSame($expected, self::slugFor($id), $id);
            self::assertMatchesRegularExpression('/\A' . self::routePattern() . '\z/D', $id);
        }

        public function testThePreChangePatternRejectedEveryStoredSpelling(): void
        {
            foreach (self::storedSpellingProvider() as $label => [$id]) {
                self::assertDoesNotMatchRegularExpression(
                    '/\A' . self::PRE_CHANGE_PATTERN . '\z/D',
                    $id,
                    $label . ' — if the old pattern admitted it, C1 was not the cause'
                );
            }
        }

        /**
         * MEASURED, and the opposite of the obvious assumption. `/v/[slug]`
         * hands `params.slug` the NORMALIZED but still-ENCODED path segment —
         * `%2D` and `%7A` come back decoded because RFC 3986 calls them
         * unreserved, `%20` and `%F0` do not — and `cardEntityPath()` then
         * applies `encodeURIComponent`. So `/v/bonynode%f0%9f%92%9a-…` reaches
         * this backend as `bonynode%25F0%259F%2592%259A-…`, double-encoded.
         *
         * @return array<string, array{string, string}>
         */
        public static function frontendSpellingProvider(): array
        {
            return [
                'frontend spelling (upper hex)' => [self::FRONTEND, 'bonynode%f0%9f%92%9a-cosmos-zsyh4e'],
                'frontend spelling, lower hex' => ['bonynode%25f0%259f%2592%259a-cosmos-zsyh4e', 'bonynode%f0%9f%92%9a-cosmos-zsyh4e'],
                'frontend spelling, leading emoji' => ['%25F0%259F%2590%25A0stake-fish-cosmos-2lcnj0', '%f0%9f%90%a0stake-fish-cosmos-2lcnj0'],
            ];
        }

        #[DataProvider('frontendSpellingProvider')]
        public function testTheFrontendSpellingResolvesToTheStoredPostName(string $id, string $expected): void
        {
            self::assertSame($expected, self::slugFor($id), $id);
        }

        // ──────────────────────────────────────────────────────────────
        // 2. The bound belongs on the SLUG, not on the URL spelling
        // ──────────────────────────────────────────────────────────────

        /**
         * Page 4079's stored slug is 135 characters and its frontend spelling
         * is 215. Bounding the RAW id at 200 — on the reasoning that
         * `post_name` is varchar(200) — left this page reachable by a direct
         * API call and unreachable through the site, which is the only route
         * users have. The bound belongs on the resolved candidate.
         */
        public function testASlugWhoseFrontendSpellingExceeds200StillResolves(): void
        {
            self::assertSame(135, strlen(self::LONG_STORED));
            $frontend = self::asFrontendSends(self::LONG_STORED);
            self::assertSame(215, strlen($frontend));

            self::assertSame(self::LONG_STORED, self::slugFor(self::LONG_STORED), 'stored spelling');
            self::assertSame(self::LONG_STORED, self::slugFor($frontend), 'frontend spelling');
        }

        /** The candidate itself is still bounded by the column width. */
        public function testTheCandidateIsBoundedByTheColumnWidth(): void
        {
            self::assertSame(str_repeat('a', 200), self::slugFor(str_repeat('a', 200)));
            self::assertNull(self::slugFor(str_repeat('a', 201)));
            self::assertNull(self::slugFor(''));
            // and the raw id still has a cheapness bound well above any spelling
            self::assertNull(self::slugFor(str_repeat('a', 601)));
        }

        // ──────────────────────────────────────────────────────────────
        // 3. Nothing that works today may stop working
        // ──────────────────────────────────────────────────────────────

        /**
         * @return array<string, array{string}>
         */
        public static function percentFreeProvider(): array
        {
            return [
                'ordinary slug'                           => ['ztake-org-cosmos-54at8c'],
                'underscores (sanitize_title keeps them)' => ['bro_n_bro-100-slash-protected-cosmos-rx52jg'],
                'uppercase ASCII slug'                    => ['ZTAKE-ORG-COSMOS-54AT8C'],
                'all digits (numeric branch, still admitted here)' => ['3692'],
                'single character'                        => ['a'],
                'bcc_handle shape'                        => ['phillip'],
                'handle with underscore'                  => ['tia_luxe'],
                'doubled dashes'                          => ['bonynode--cosmos-zsyh4e'],
                'absent from the fixture tables on purpose' => ['this-slug-is-absent-from-the-tables'],
            ];
        }

        /**
         * A `%`-free id is exactly what the pre-change pattern already
         * admitted, and it is returned UNTOUCHED — the resolver short-circuits
         * before any `sanitize_title()` comparison, so its behaviour is
         * unchanged BY CONSTRUCTION. The last row proves the short-circuit is
         * real: it has no recorded fixture value, so a consulted table would
         * throw.
         */
        #[DataProvider('percentFreeProvider')]
        public function testAPercentFreeIdIsReturnedUntouched(string $id): void
        {
            self::assertSame($id, self::slugFor($id), $id);
        }

        // ──────────────────────────────────────────────────────────────
        // 4. The structural check — what canonicality cannot do
        // ──────────────────────────────────────────────────────────────

        /**
         * ⚠⚠ The defect this closes, and why a blocklist would have been the
         * wrong shape. `%2f` and `%20` are VALID octets that `sanitize_title()`
         * calls canonical, so every spelling test passes them — but
         * `get_page_by_path()` decodes a SECOND time and gives them structural
         * meaning: `%2F` becomes a path separator and `%20` becomes a space
         * that core then rewrites to a dash. The single-encoded forms were
         * already refused because `sanitize_title` alters them; the
         * DOUBLE-encoded forms survive canonicality intact and are the real
         * hole.
         *
         * @return array<string, array{string, string}>
         */
        public static function structuralRefusalProvider(): array
        {
            return [
                'double-encoded separator, upper hex' => [
                    'bonynode%252Fadmin-cosmos-zsyh4e',
                    'decodes to a canonical %2f candidate that core then splits into two segments',
                ],
                'double-encoded separator, lower hex' => [
                    'bonynode%252fadmin-cosmos-zsyh4e',
                    'the same hole in the other hex case',
                ],
                'single-encoded separator, upper hex' => [
                    'bonynode%2Fadmin-cosmos-zsyh4e',
                    'refused one step earlier, because sanitize_title alters it',
                ],
                'single-encoded separator, lower hex' => [
                    'bonynode%2fadmin-cosmos-zsyh4e',
                    'same',
                ],
                'double-encoded space, lower hex' => [
                    'bonynode%2520cosmos',
                    'decodes to a canonical %20 candidate that core rewrites to bonynode-cosmos',
                ],
                'double-encoded space, upper hex' => [
                    'bonynode%2520Cosmos',
                    'the same rewrite in the other case',
                ],
                'single-encoded space' => [
                    'bonynode%20cosmos',
                    'refused one step earlier',
                ],
                'malformed octet' => ['bonynode%zz-cosmos-zsyh4e', 'not an octet'],
                'bare trailing percent' => ['bonynode%', 'a truncated octet'],
            ];
        }

        #[DataProvider('structuralRefusalProvider')]
        public function testAnIdCoreWouldSplitOrRewriteIsRefused(string $id, string $why): void
        {
            self::assertNull(self::slugFor($id), $id . ' — ' . $why);
        }

        /**
         * The refusals only matter if the ROUTE would have let them through.
         * Every id above matches the widened pattern; the resolver is what
         * stops it. This is the "do not rely on widening the regex alone"
         * claim, asserted rather than described.
         */
        public function testTheRouteAdmitsEveryStructuralRefusal(): void
        {
            $pattern = '/\A' . self::routePattern() . '\z/D';
            foreach (self::structuralRefusalProvider() as $label => [$id, $why]) {
                self::assertMatchesRegularExpression($pattern, $id, $label . ' — route must admit: ' . $why);
                self::assertNull(self::slugFor($id), $label . ' — resolver must refuse: ' . $why);
            }
        }

        /**
         * ⭐ THE DECOY. The separator case is dangerous only because the two
         * segments it produces could match real pages, so assert against the
         * actual lookup rather than against the refusal alone:
         * `bonynode%252Fadmin-cosmos-zsyh4e` would have had core search for
         * `bonynode` + `admin-cosmos-zsyh4e`, and a page table holding either
         * of those is a page the caller never named. The refusal has to happen
         * BEFORE that segment list can be used.
         */
        public function testTheSeparatorCaseNeverReachesTheDecoySegments(): void
        {
            $hostile = 'bonynode%252Fadmin-cosmos-zsyh4e';

            // What core WOULD have searched for, had the candidate been used.
            $wouldSearch = self::segmentsFor('bonynode%2fadmin-cosmos-zsyh4e');
            self::assertSame(
                ['bonynode', 'admin-cosmos-zsyh4e'],
                $wouldSearch,
                'the premise: core splits the %2f candidate into two segments'
            );

            // A decoy page table standing in for wp_posts. Every one of these
            // is a page the hostile id must never resolve to.
            $decoys = [
                'bonynode'                     => 9001,
                'admin-cosmos-zsyh4e'          => 9002,
                'bonynode-admin-cosmos-zsyh4e' => 9003,
                'bonynode-cosmos'              => 9004,
            ];

            $resolved = self::slugFor($hostile);
            self::assertNull($resolved, 'the hostile id must be refused outright');
            self::assertArrayNotHasKey(
                (string) $resolved,
                $decoys,
                'a refused id must not name any decoy page'
            );

            // And the same for the space spelling, whose decoy is bonynode-cosmos.
            self::assertSame(['bonynode-cosmos'], self::segmentsFor('bonynode%20cosmos'));
            self::assertNull(self::slugFor('bonynode%2520cosmos'));
        }

        /**
         * Every accepted candidate must be what core will actually look for —
         * exactly one segment, equal to the candidate. This is the invariant
         * the structural check enforces, asserted over the accepted corpus.
         */
        public function testEveryAcceptedCandidateIsTheSingleSegmentCoreWillSearchFor(): void
        {
            $first  = static fn (array $r): string => (string) $r[0];
            $ids    = array_merge(
                array_map($first, array_values(self::storedSpellingProvider())),
                array_map($first, array_values(self::frontendSpellingProvider())),
                array_map($first, array_values(self::aliasProvider())),
                array_map($first, array_values(self::inertProvider())),
                [self::LONG_STORED, self::asFrontendSends(self::LONG_STORED)]
            );

            $checked = 0;
            foreach ($ids as $id) {
                $cand = self::slugFor($id);
                self::assertNotNull($cand, $id);
                self::assertSame([$cand], self::segmentsFor($cand), 'core must search for exactly ' . $cand);
                $checked++;
            }
            self::assertGreaterThan(0, $checked);
        }

        // ──────────────────────────────────────────────────────────────
        // 5. Accepted-but-inert, and accepted aliases
        // ──────────────────────────────────────────────────────────────

        /**
         * One decode of a triple-escaped id lands on `%25…`, which IS
         * canonical because core protects and restores existing `%XX` octets.
         * It names exactly what the caller spelled and can never match a row:
         * `sanitize_title` only percent-encodes bytes >= 0x80, so `%25`
         * (ASCII 0x25) cannot occur in any `post_name` — 0 of 2,806 do, and
         * every octet they contain is >= `%80`.
         *
         * @return array<string, array{string, string}>
         */
        public static function inertProvider(): array
        {
            return [
                'triple-escaped octets' => [
                    'bonynode%2525f0%25259f%252592%25259a-cosmos-zsyh4e',
                    'bonynode%25f0%259f%2592%259a-cosmos-zsyh4e',
                ],
                'double-escaped percent alone' => ['bonynode%2525', 'bonynode%25'],
            ];
        }

        #[DataProvider('inertProvider')]
        public function testTheInertPercentFamilyResolvesToAnUnmatchableSlug(string $id, string $expected): void
        {
            self::assertSame($expected, self::slugFor($id), $id);
            self::assertStringContainsString('%25', $expected, 'the point of this family is the %25');
        }

        /**
         * A single decode is ordinary percent-encoding transparency — core's own
         * get_page_by_path() has behaved this way for every page on the site
         * since long before this route existed — so `%7A` resolves the page `z`
         * spells. One page can therefore have more than one URL. It names no
         * page the caller did not name.
         *
         * @return array<string, array{string, string}>
         */
        public static function aliasProvider(): array
        {
            return [
                '%7A decodes to z' => ['%7Atake-org-cosmos-54at8c', 'ztake-org-cosmos-54at8c'],
                '%2d decodes to -' => ['bonynode%2dcosmos', 'bonynode-cosmos'],
                '%2D uppercase'    => ['bonynode%2Dcosmos', 'bonynode-cosmos'],
            ];
        }

        #[DataProvider('aliasProvider')]
        public function testASingleDecodeAliasResolvesThePageItSpells(string $id, string $expected): void
        {
            self::assertSame($expected, self::slugFor($id), $id);
        }

        // ──────────────────────────────────────────────────────────────
        // 6. Charset floor
        // ──────────────────────────────────────────────────────────────

        /**
         * @return array<string, array{string}>
         */
        public static function outsideCharsetProvider(): array
        {
            return [
                'raw path separator'  => ['bonynode/admin'],
                'backslash'           => ['bonynode\\admin'],
                'dot'                 => ['bonynode.admin'],
                'raw unencoded UTF-8' => ["bonynode\u{1F49A}-cosmos-zsyh4e"],
                'leading dash'        => ['-bonynode'],
                'leading underscore'  => ['_bonynode'],
                'whitespace'          => ['bonynode cosmos'],
                'NUL byte'            => ["bonynode\0admin"],
                'angle bracket'       => ['<script>'],
                'query separator'     => ['bonynode&id=1'],
            ];
        }

        /**
         * The charset floor matters because `:id` is ALSO reachable through the
         * query string, and 'GET' outranks 'URL' in
         * WP_REST_Request::get_parameter_order() — verified against production,
         * where `/cards/validator/ztake-org-cosmos-54at8c?id=mantra-cosmos-uv234h`
         * answers with the MANTRA card. So the route pattern bounds only the URL
         * spelling, and these must be refused wherever they arrive from.
         */
        #[DataProvider('outsideCharsetProvider')]
        public function testCharactersOutsideTheRouteCharsetAreRefused(string $id): void
        {
            self::assertNull(self::slugFor($id), $id);
        }

        // ──────────────────────────────────────────────────────────────
        // 7. Drift guards
        // ──────────────────────────────────────────────────────────────

        public function testTheRoutePatternIsTheExpectedLiteral(): void
        {
            self::assertSame(self::EXPECTED_ROUTE_PATTERN, self::routePattern());
        }

        /**
         * `scripts/contract-parity-guard.php` collapses `(?P<name>…)` to
         * `:name` with `[^)]*`, so a parenthesis in the pattern would mangle the
         * parsed path and turn the umbrella's blocking parity check red. `/` is
         * excluded for the obvious reason.
         */
        public function testTheRoutePatternCarriesNothingTheParityGuardWouldChokeOn(): void
        {
            self::assertStringNotContainsString('(', self::routePattern());
            self::assertStringNotContainsString(')', self::routePattern());
            self::assertStringNotContainsString('/', self::routePattern());
        }

        public function testTheResolverNeverAcceptsWhatTheRouteWouldReject(): void
        {
            $pattern = '/\A' . self::routePattern() . '\z/D';
            $first   = static fn (array $r): string => (string) $r[0];
            $corpus  = array_merge(
                array_map($first, array_values(self::storedSpellingProvider())),
                array_map($first, array_values(self::frontendSpellingProvider())),
                array_map($first, array_values(self::percentFreeProvider())),
                array_map($first, array_values(self::outsideCharsetProvider())),
                array_map($first, array_values(self::structuralRefusalProvider())),
                array_map($first, array_values(self::inertProvider())),
                array_map($first, array_values(self::aliasProvider())),
                [self::LONG_STORED, self::asFrontendSends(self::LONG_STORED), str_repeat('a', 200)]
            );

            $accepted = 0;
            foreach ($corpus as $id) {
                if (self::slugFor($id) !== null) {
                    $accepted++;
                    self::assertMatchesRegularExpression($pattern, $id, 'accepted but route-rejected: ' . $id);
                }
            }
            self::assertGreaterThan(0, $accepted);
        }

        /**
         * `sanitize_text_field` STRIPS percent-encoded octets, so leaving it on
         * `:id` would hand the resolver `bonynode-cosmos-zsyh4e` — a different
         * and possibly real slug — for every C1 page. Measured against an
         * isolated backend: with the pattern widened and this callback left in
         * place, a direct API request for BonyNode's stored slug served a
         * DIFFERENT page.
         */
        public function testTheIdArgumentDoesNotUseAnOctetStrippingSanitizer(): void
        {
            $src = (string) file_get_contents(
                dirname(__DIR__, 2) . '/app/Domain/Core/REST/CardsEndpoint.php'
            );

            self::assertNotSame('', $src, 'CardsEndpoint.php must be readable for this to mean anything');
            self::assertStringNotContainsString("'sanitize_callback' => 'sanitize_text_field'", $src);
            self::assertStringContainsString(
                "'sanitize_callback' => [BoundedIntParam::class, 'keepScalar']",
                $src
            );
            self::assertSame(self::STORED, BoundedIntParam::keepScalar(self::STORED));
            self::assertSame(self::FRONTEND, BoundedIntParam::keepScalar(self::FRONTEND));
        }

        /**
         * The structural replay must stay a replay. If someone rewrites
         * `pathSegmentsFor()` as a summary of core's rules, this fails: it
         * pins the three transforms core applies, in order.
         */
        public function testTheStructuralReplayMatchesCoresTransforms(): void
        {
            $src = (string) file_get_contents(
                dirname(__DIR__, 2) . '/app/Domain/Core/Services/CardViewService.php'
            );

            self::assertStringContainsString("rawurlencode(urldecode(\$path))", $src);
            self::assertStringContainsString("str_replace('%2F', '/', \$path)", $src);
            self::assertStringContainsString("str_replace('%20', ' ', \$path)", $src);
            // Applied through a closure, not a string callable: a string
            // callable always resolves in the GLOBAL namespace, so it could not
            // be substituted here and would fatal in this suite.
            self::assertStringContainsString(
                'static fn (string $part): string => sanitize_title_for_query($part)',
                $src
            );
        }
    }
}
