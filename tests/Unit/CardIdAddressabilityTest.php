<?php
/**
 * CardIdAddressabilityTest — which spellings of `:id` resolve to which
 * `post_name` behind `GET /bcc/v1/cards/:type/:id`.
 *
 * Context. `sanitize_title()` percent-encodes every non-ASCII character, so a
 * validator page whose moniker carries an emoji is stored with a literal
 * `%f0%9f…` in `post_name` — 123 of the 2,830 live validator pages. The
 * route's old `:id` pattern admitted no `%`, and the `id` argument's old
 * `sanitize_text_field` callback stripped percent octets outright, so those
 * pages had NO reachable URL at all.
 *
 * ⚠⚠ WHY THE FIXTURE BELOW IS A TABLE AND NOT AN IMPLEMENTATION.
 * The rule under test compares against `sanitize_title()`, and this suite runs
 * with no WordPress core. A hand-written `sanitize_title` stub would be
 * asserting the stub's idea of percent-encoding rather than WordPress's — and
 * `tests/Stubs/canonical-backfill-stubs.php` already ships a crude GLOBAL one
 * (`preg_replace('/[^a-z0-9]+/','-')`) that flattens an emoji to a dash and
 * would make every case here pass for the wrong reason. So the namespaced stub
 * is a LOOKUP TABLE of values captured from real WordPress 7.0.2
 * (`wp-includes/formatting.php` with core's own default filters loaded), and it
 * THROWS on an input it has no recorded answer for. The authoritative
 * re-derivation lives in `scripts/tests/card-id-real-dispatch.php`, which runs
 * the same corpus — plus all 2,830 live slugs — through real core.
 *
 * @package BCC\Trust\Tests\Unit
 * @since 2026-10-30 (C1 — percent-encoded validator slugs)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services {
    /**
     * Values captured from real WordPress 7.0.2 `sanitize_title()`. Keys are
     * the strings `canonicalSlugFor()` passes to it, i.e.
     * `rawurldecode(strtolower($id))` (spelling 1) and
     * `strtolower(rawurldecode($id))` (spelling 2).
     *
     * @var array<string, string>
     */
    const BCC_REAL_SANITIZE_TITLE = [
        '%f0%9f%90%a0stake-fish-cosmos-2lcnj0'       => '%f0%9f%90%a0stake-fish-cosmos-2lcnj0',
        'bonynode cosmos'                            => 'bonynode-cosmos',
        'bonynode%'                                  => 'bonynode',
        'bonynode%25'                                => 'bonynode%25',
        'bonynode%25f0%259f%2592%259a-cosmos-zsyh4e' => 'bonynode%25f0%259f%2592%259a-cosmos-zsyh4e',
        'bonynode%f0%9f%92%9a-cosmos-zsyh4e'         => 'bonynode%f0%9f%92%9a-cosmos-zsyh4e',
        'bonynode%zz-cosmos-zsyh4e'                  => 'bonynodezz-cosmos-zsyh4e',
        'bonynode-cosmos'                            => 'bonynode-cosmos',
        'bonynode/admin-cosmos-zsyh4e'               => 'bonynode-admin-cosmos-zsyh4e',
        // idempotence of a resolved slug, for testEveryResolvedSlugIsItselfCanonical
        'chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k'   => 'chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k',
        "bonynode\u{1F49A}-cosmos-zsyh4e"            => 'bonynode%f0%9f%92%9a-cosmos-zsyh4e',
        "chiter-in-cosmos-\u{269B}-cosmos-95f92k"    => 'chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k',
        'ztake-org-cosmos-54at8c'                    => 'ztake-org-cosmos-54at8c',
        "\u{1F420}stake-fish-cosmos-2lcnj0"          => '%f0%9f%90%a0stake-fish-cosmos-2lcnj0',

        // ⚠ NOT a core value — real core returns '__bcc_probe__' unchanged.
        // This row deliberately differs so the anti-vacuity test below can
        // prove THIS function resolved rather than the crude global stub.
        '__bcc_probe__'                              => '__bcc_probe_sentinel__',
    ];

    if (!function_exists('BCC\\Trust\\Core\\Services\\sanitize_title')) {
        function sanitize_title(string $title): string
        {
            if (!array_key_exists($title, BCC_REAL_SANITIZE_TITLE)) {
                throw new \RuntimeException(
                    'No real-core sanitize_title() value recorded for ' . var_export($title, true)
                    . '. Re-derive it with scripts/tests/card-id-real-dispatch.php --print-table and'
                    . ' add it to the table rather than guessing — a guessed value makes this suite'
                    . ' assert nothing.'
                );
            }

            return BCC_REAL_SANITIZE_TITLE[$title];
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
        /** The charset the route admits, which the resolver repeats. */
        private const EXPECTED_ROUTE_PATTERN = '[A-Za-z0-9%][A-Za-z0-9_%-]{0,199}';

        /** The pattern this change replaced, kept so "was broken" is asserted. */
        private const PRE_CHANGE_PATTERN = '[A-Za-z0-9][A-Za-z0-9_-]{0,99}';

        private const STORED   = 'bonynode%f0%9f%92%9a-cosmos-zsyh4e';
        /** What `/v/bonynode%f0%9f%92%9a-…` actually arrives as. See the docs below. */
        private const FRONTEND = 'bonynode%25F0%259F%2592%259A-cosmos-zsyh4e';

        private static function slugFor(string $id): ?string
        {
            $m = new \ReflectionMethod(CardViewService::class, 'canonicalSlugFor');
            $m->setAccessible(true);

            /** @var string|null $r */
            $r = $m->invoke(null, $id);

            return $r;
        }

        private static function routePattern(): string
        {
            /** @var string $p */
            $p = (new \ReflectionClass(CardsEndpoint::class))->getConstant('ID_PATTERN');

            return $p;
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
        public function testTheNamespacedRealCoreTableIsTheSanitizeTitleInForce(): void
        {
            self::assertSame(
                '__bcc_probe_sentinel__',
                \BCC\Trust\Core\Services\sanitize_title('__bcc_probe__'),
                'The global crude sanitize_title stub won resolution — the percent cases prove nothing.'
            );
        }

        /** A resolver that accepts everything, or refuses everything, is not one. */
        public function testTheResolverBothAcceptsAndRefuses(): void
        {
            self::assertSame('ztake-org-cosmos-54at8c', self::slugFor('ztake-org-cosmos-54at8c'));
            self::assertNull(self::slugFor('bonynode%zz-cosmos-zsyh4e'));
        }

        // ──────────────────────────────────────────────────────────────
        // 1. Spelling 1 — the id IS post_name, octets intact
        // ──────────────────────────────────────────────────────────────

        /**
         * @return array<string, array{string, string}>
         */
        public static function storedSpellingProvider(): array
        {
            return [
                'stored slug, lowercase hex (what post_name holds)' => [
                    self::STORED, 'bonynode%f0%9f%92%9a-cosmos-zsyh4e',
                ],
                'stored slug, uppercase hex' => [
                    'bonynode%F0%9F%92%9A-cosmos-zsyh4e', 'bonynode%f0%9f%92%9a-cosmos-zsyh4e',
                ],
                'mixed-case ASCII around the octets' => [
                    'BonyNode%F0%9F%92%9A-cosmos-zsyh4e', 'bonynode%f0%9f%92%9a-cosmos-zsyh4e',
                ],
                'LEADING emoji' => [
                    '%f0%9f%90%a0stake-fish-cosmos-2lcnj0', '%f0%9f%90%a0stake-fish-cosmos-2lcnj0',
                ],
                'LEADING emoji, uppercase hex' => [
                    '%F0%9F%90%A0stake-fish-cosmos-2lcnj0', '%f0%9f%90%a0stake-fish-cosmos-2lcnj0',
                ],
                'three-byte sequence (U+269B)' => [
                    'chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k', 'chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k',
                ],
                'three-byte sequence, uppercase hex' => [
                    'chiter-in-cosmos-%E2%9A%9B-cosmos-95f92k', 'chiter-in-cosmos-%e2%9a%9b-cosmos-95f92k',
                ],
            ];
        }

        #[DataProvider('storedSpellingProvider')]
        public function testTheStoredSpellingResolvesToItsOwnPostName(string $id, string $expected): void
        {
            self::assertSame($expected, self::slugFor($id), $id);
            self::assertMatchesRegularExpression(
                '/\A' . self::routePattern() . '\z/D',
                $id,
                'the route must admit the slug it stores'
            );
        }

        /** And the pre-change pattern must be shown to have rejected them. */
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

        // ──────────────────────────────────────────────────────────────
        // 2. Spelling 2 — post_name escaped ONCE, which is what the
        //    frontend sends. This is the spelling that has to work.
        // ──────────────────────────────────────────────────────────────

        /**
         * MEASURED, and it is the opposite of the obvious assumption.
         * `/v/[slug]` hands `params.slug` the NORMALIZED but still-ENCODED
         * path segment — `%2D` and `%7A` come back decoded because RFC 3986
         * calls them unreserved, `%20` and `%F0` do not — and
         * `cardEntityPath()` then applies `encodeURIComponent` to it. So
         * `/v/bonynode%f0%9f%92%9a-cosmos-zsyh4e` reaches this backend as
         * `bonynode%25F0%259F%2592%259A-cosmos-zsyh4e`, double-encoded.
         *
         * Verified end to end against an isolated backend: before this change
         * all five representative C1 URLs rendered the 404 page; after it they
         * render the right validator.
         *
         * @return array<string, array{string, string}>
         */
        public static function frontendSpellingProvider(): array
        {
            return [
                'frontend spelling (one escape, upper hex)' => [
                    self::FRONTEND, 'bonynode%f0%9f%92%9a-cosmos-zsyh4e',
                ],
                'frontend spelling, lower hex' => [
                    'bonynode%25f0%259f%2592%259a-cosmos-zsyh4e', 'bonynode%f0%9f%92%9a-cosmos-zsyh4e',
                ],
                'frontend spelling, leading emoji' => [
                    '%25F0%259F%2590%25A0stake-fish-cosmos-2lcnj0', '%f0%9f%90%a0stake-fish-cosmos-2lcnj0',
                ],
            ];
        }

        #[DataProvider('frontendSpellingProvider')]
        public function testTheFrontendSpellingResolvesToTheStoredPostName(string $id, string $expected): void
        {
            self::assertSame($expected, self::slugFor($id), $id);
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
                'underscores (sanitize_title keeps them)'  => ['bro_n_bro-100-slash-protected-cosmos-rx52jg'],
                'uppercase ASCII slug'                    => ['ZTAKE-ORG-COSMOS-54AT8C'],
                'all digits (numeric branch, still admitted here)' => ['3692'],
                'single character'                        => ['a'],
                'bcc_handle shape'                        => ['phillip'],
                'handle with underscore'                  => ['tia_luxe'],
                'doubled dashes'                          => ['bonynode--cosmos-zsyh4e'],
                'absent from the fixture table on purpose' => ['this-slug-is-absent-from-the-table'],
            ];
        }

        /**
         * An id with no `%` is exactly what the pre-change pattern already
         * admitted, and it is returned UNTOUCHED — the resolver short-circuits
         * before any `sanitize_title()` comparison, so its behaviour is
         * unchanged BY CONSTRUCTION. The last row proves the short-circuit is
         * real: that id has no recorded fixture value, so the stub would throw
         * if the resolver consulted it.
         *
         */
        #[DataProvider('percentFreeProvider')]
        public function testAPercentFreeIdIsReturnedUntouchedAndNeverNormalised(string $id): void
        {
            self::assertSame($id, self::slugFor($id), $id);
        }

        // ──────────────────────────────────────────────────────────────
        // 4. Normalization may never name a different identifier
        // ──────────────────────────────────────────────────────────────

        /**
         * @return array<string, array{string, string}>
         */
        public static function refusedProvider(): array
        {
            return [
                'encoded path separator (%2f) — one segment would become two' => [
                    'bonynode%2fadmin-cosmos-zsyh4e',
                    'get_page_by_path turns %2F into a real / and then walks post ancestry',
                ],
                'encoded separator, uppercase hex (%2F)' => [
                    'bonynode%2Fadmin-cosmos-zsyh4e',
                    'the same hole in the other hex case',
                ],
                'encoded space (%20)' => [
                    'bonynode%20cosmos',
                    'sanitize_title would ALTER the decoded bytes, turning the space into a dash',
                ],
                'malformed encoding (%zz is not an octet)' => [
                    'bonynode%zz-cosmos-zsyh4e',
                    'rawurldecode leaves it alone and sanitize_title then drops the %',
                ],
                'bare trailing percent' => [
                    'bonynode%',
                    'a truncated octet',
                ],
            ];
        }

        /**
         * The refusal rule is one property: after at most one url-decode the
         * id must ALREADY be `sanitize_title()`-canonical. Anything
         * `sanitize_title` would have to alter rather than merely re-spell is
         * refused, so normalization can change an identifier's URL spelling
         * but never which identifier it names.
         *
         */
        #[DataProvider('refusedProvider')]
        public function testAnIdSanitizeTitleWouldAlterIsRefused(string $id, string $why): void
        {
            self::assertNull(self::slugFor($id), $id . ' — ' . $why);
        }

        /**
         * The refusals only matter if the ROUTE would have let them through —
         * otherwise this suite is exercising unreachable code. Every id above
         * matches the widened pattern, and the resolver is what stops it. This
         * is the "do not rely on widening the regex alone" claim, asserted
         * rather than described.
         */
        public function testTheRouteAdmitsEveryRefusedIdSoTheResolverIsLoadBearing(): void
        {
            $pattern = '/\A' . self::routePattern() . '\z/D';
            foreach (self::refusedProvider() as $label => [$id, $why]) {
                self::assertMatchesRegularExpression($pattern, $id, $label . ' — route must admit: ' . $why);
                self::assertNull(self::slugFor($id), $label . ' — resolver must refuse: ' . $why);
            }
        }

        /**
         * ⚠ The accepted-but-inert family, recorded so it is not mistaken for a
         * hole. One decode of a triple-escaped id lands on `%25…`, which IS
         * `sanitize_title`-canonical because core protects and restores
         * existing `%XX` octets. It names exactly what the caller spelled, and
         * it can never match a row: `sanitize_title` only percent-encodes
         * bytes >= 0x80, so `%25` (ASCII 0x25) cannot occur in any `post_name`
         * — measured, 0 of the 2,830 live slugs contain it, and every octet
         * they do contain is >= %80.
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
                'double-escaped percent with nothing behind it' => [
                    'bonynode%2525', 'bonynode%25',
                ],
            ];
        }

        #[DataProvider('inertProvider')]
        public function testTheInertPercentFamilyResolvesToAnUnmatchableSlug(string $id, string $expected): void
        {
            self::assertSame($expected, self::slugFor($id), $id);
            self::assertStringContainsString('%25', $expected, 'the point of this family is the %25');
        }

        /**
         * ⚠ The accepted alias family, also recorded rather than hidden. A
         * single decode is ordinary percent-encoding transparency — core's own
         * get_page_by_path() has behaved this way for every page on the site
         * since long before this route existed — so `%7A` resolves the page
         * `z` spells. One page can therefore have more than one URL. It names
         * no page the caller did not name.
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
        // 5. Bounds and charset
        // ──────────────────────────────────────────────────────────────

        /** `wp_posts.post_name` is varchar(200); a longer id cannot match a row. */
        public function testTheLengthCapIsTheColumnWidth(): void
        {
            self::assertSame(str_repeat('a', 200), self::slugFor(str_repeat('a', 200)));
            self::assertNull(self::slugFor(str_repeat('a', 201)));
            self::assertNull(self::slugFor(''));
        }

        /**
         * The longest live stored slug is 124 characters. The pre-change cap of
         * 100 left it unreachable even with `%` admitted, so raising the cap is
         * part of the fix and not incidental tidying.
         */
        public function testThe124CharacterStoredSlugFitsTheNewCapAndNotTheOld(): void
        {
            $longest = '%f0%9f%85%b2%f0%9f%85%be%f0%9f%86%86-%f0%9f%85%bb%f0%9f%85%b4%f0%9f%86%85'
                . '%f0%9f%85%b4%f0%9f%85%bb-%f0%9f%90%ae-jackal-hvch59';

            self::assertSame(124, strlen($longest));
            self::assertMatchesRegularExpression('/\A' . self::routePattern() . '\z/D', $longest);
            self::assertDoesNotMatchRegularExpression(
                '/\A' . self::PRE_CHANGE_PATTERN . '\z/D',
                $longest,
                'the pre-change pattern must be shown to reject it'
            );
        }

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
         * answers with the MANTRA card. So the route pattern bounds only the
         * URL spelling, and these must be refused wherever they arrive from.
         *
         */
        #[DataProvider('outsideCharsetProvider')]
        public function testCharactersOutsideTheRouteCharsetAreRefused(string $id): void
        {
            self::assertNull(self::slugFor($id), $id);
        }

        /**
         * A raw, unencoded UTF-8 id is refused, and that is deliberate — it is
         * not a stored spelling, and it 404s before this change too, so the
         * refusal is not a regression. Stated as a test so the limitation is
         * recorded rather than discovered.
         */
        public function testRawUnencodedUtf8RemainsUnresolvableByDesign(): void
        {
            self::assertNull(self::slugFor("bonynode\u{1F49A}-cosmos-zsyh4e"));
            self::assertNull(self::slugFor("\u{1F420}stake-fish-cosmos-2lcnj0"));
            self::assertNull(self::slugFor("chiter-in-cosmos-\u{269B}-cosmos-95f92k"));
        }

        // ──────────────────────────────────────────────────────────────
        // 6. Drift guards
        // ──────────────────────────────────────────────────────────────

        /**
         * The resolver repeats the route's charset, so the two can drift. This
         * pins both to one literal: widening either alone fails here.
         */
        public function testTheRoutePatternIsTheExpectedLiteral(): void
        {
            self::assertSame(self::EXPECTED_ROUTE_PATTERN, self::routePattern());
        }

        /**
         * The route pattern must stay free of `(` and `)`:
         * `scripts/contract-parity-guard.php` collapses `(?P<name>…)` to
         * `:name` with `[^)]*`, so a parenthesis here would mangle the parsed
         * path and turn the umbrella's blocking parity check red. `/` is
         * excluded for the obvious reason — it would let an id swallow a path
         * segment.
         */
        public function testTheRoutePatternCarriesNothingTheParityGuardWouldChokeOn(): void
        {
            self::assertStringNotContainsString('(', self::routePattern());
            self::assertStringNotContainsString(')', self::routePattern());
            self::assertStringNotContainsString('/', self::routePattern());
        }

        /**
         * Over a corpus spanning both charsets, anything the resolver accepts
         * must be something the route would have admitted. The converse is
         * deliberately false — that gap IS the resolver.
         */
        public function testTheResolverNeverAcceptsWhatTheRouteWouldReject(): void
        {
            $pattern = '/\A' . self::routePattern() . '\z/D';
            $first   = static fn (array $r): string => (string) $r[0];
            $corpus  = array_merge(
                array_map($first, array_values(self::storedSpellingProvider())),
                array_map($first, array_values(self::frontendSpellingProvider())),
                array_map($first, array_values(self::percentFreeProvider())),
                array_map($first, array_values(self::outsideCharsetProvider())),
                array_map($first, array_values(self::refusedProvider())),
                array_map($first, array_values(self::inertProvider())),
                array_map($first, array_values(self::aliasProvider())),
                [str_repeat('a', 200)]
            );

            $accepted = 0;
            foreach ($corpus as $id) {
                if (self::slugFor($id) !== null) {
                    $accepted++;
                    self::assertMatchesRegularExpression($pattern, $id, 'accepted but route-rejected: ' . $id);
                }
            }

            self::assertGreaterThan(0, $accepted, 'the corpus must contain accepted ids');
        }

        /**
         * Everything the resolver returns must itself be a canonical slug, or
         * the lookup it feeds is a guess. Checked against the recorded
         * real-core values rather than recomputed.
         */
        public function testEveryResolvedSlugIsItselfCanonical(): void
        {
            $rows = array_merge(
                array_values(self::storedSpellingProvider()),
                array_values(self::frontendSpellingProvider()),
                array_values(self::inertProvider()),
                array_values(self::aliasProvider())
            );

            foreach ($rows as [$id, $expected]) {
                self::assertSame($expected, self::slugFor($id), $id);
                self::assertSame(
                    $expected,
                    \BCC\Trust\Core\Services\sanitize_title($expected),
                    $expected . ' must be sanitize_title-canonical'
                );
            }
        }

        /**
         * `sanitize_text_field` STRIPS percent-encoded octets, so leaving it on
         * `:id` would hand the resolver `bonynode-cosmos-zsyh4e` — a different
         * and possibly real slug — for every C1 page. Measured against an
         * isolated backend: with the pattern widened and this callback left in
         * place, a direct API request for BonyNode's stored slug served a
         * DIFFERENT page. The argument must use the preserve-the-caller's-text
         * sanitizer instead.
         */
        public function testTheIdArgumentDoesNotUseAnOctetStrippingSanitizer(): void
        {
            $src = (string) file_get_contents(
                dirname(__DIR__, 2) . '/app/Domain/Core/REST/CardsEndpoint.php'
            );

            self::assertNotSame('', $src, 'CardsEndpoint.php must be readable for this assertion to mean anything');
            self::assertStringNotContainsString("'sanitize_callback' => 'sanitize_text_field'", $src);
            self::assertStringContainsString(
                "'sanitize_callback' => [BoundedIntParam::class, 'keepScalar']",
                $src
            );
            // and the reused primitive must still be the no-op it claims to be
            self::assertSame(self::STORED, BoundedIntParam::keepScalar(self::STORED));
            self::assertSame(self::FRONTEND, BoundedIntParam::keepScalar(self::FRONTEND));
        }
    }
}
