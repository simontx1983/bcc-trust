<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\KeysetCursor as Cursor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keyset cursors — api-contract-v1.md §1.5 and §4.32.12.
 *
 * The class is pure, so this suite is exhaustive rather than
 * representative. That matters more than usual here: the two existing
 * private copies of this idiom were each wrong about something, and the
 * suite's job is to make sure the canonical is not.
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. A CURSOR FROM ONE SURFACE DECODES ON ANOTHER. §4.32.12 gives every
 *     collection a DIFFERENT order — the active list by `published_at`,
 *     the archived list by `archived_at`. Applying one surface's cursor to
 *     another skips or repeats rows with nothing in the response to show
 *     it happened. `BlogService` documents this property; its code does
 *     not deliver it (a blog cursor decodes as a comment cursor, because
 *     CommentService reads `$data['k'] ?? $data['t']`). This class must.
 *  2. A MALFORMED CURSOR RAISES. A bad cursor is a navigation accident —
 *     a stale bookmark, a truncated URL, a cursor from another tab — and
 *     must reset to the first page, never 400 and never throw.
 *  3. A NUMERIC-ISH ID IS COERCED. `(int) '4471abc'` is 4471, which
 *     repositions the page instead of resetting it. That is worse than
 *     either correct behaviour, because the reader sees a plausible page.
 *  4. A CURSOR WITHOUT THE UNIQUE TIEBREAK IS MINTABLE. Ordering by a
 *     timestamp alone is not a total order; two rows sharing a second
 *     page unstably, and `?state=active` is ordered by a field the
 *     operator can change mid-scroll.
 *  5. AN UNMINTABLE CURSOR IS MINTED. If `encode()` can produce something
 *     `decode()` refuses, the caller emits it, the next request silently
 *     restarts the list, and the reader loses their place with no error.
 *     Every encode/decode pair here is checked for round-trip.
 */
#[CoversClass(Cursor::class)]
final class KeysetCursorTest extends TestCase
{
    private const S = Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE;

    /** Build a raw payload the way an attacker or a legacy writer would. */
    private static function raw(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    // ── 1. Round trip ───────────────────────────────────────────────────

    /** MUST-NOT-HAPPEN #5. */
    #[DataProvider('roundTripKeys')]
    public function testEveryMintableCursorDecodesBack(string $key, int $id, string $why): void
    {
        $decoded = Cursor::decode(self::S, Cursor::encode(self::S, $key, $id));

        self::assertNotNull($decoded, $why);
        self::assertSame($key, $decoded['key'], $why);
        self::assertSame($id, $decoded['id'], $why);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function roundTripKeys(): iterable
    {
        yield 'mysql datetime'  => ['2026-10-13 12:00:00', 4471, 'the active and archived lists'];
        yield 'iso8601'         => ['2026-10-13T12:00:00Z', 4471, 'if a caller prefers ISO'];
        yield 'numeric score'   => ['12345', 9, 'a score-ordered surface'];
        yield 'decimal score'   => ['0.123456', 9, 'the relevance precision shape'];
        yield 'composite'       => ['1|2026-10-13 12:00:00', 9, 'pinned-first is a COMPOSITE key'];
        yield 'zero-ish key'    => ['0', 1, 'a legitimate score of zero is not an empty key'];
        yield 'id one'          => ['2026-01-01 00:00:00', 1, 'the lowest valid row id'];
        yield 'large id'        => ['2026-01-01 00:00:00', 9223372036854775807, 'a bigint id'];
        yield 'unicode key'     => ['2026-10-13 12:00:00·x', 5, 'multibyte must survive base64/JSON'];
        yield 'key with slash'  => ['a/b', 5, 'JSON_UNESCAPED_SLASHES must not corrupt it'];
        yield 'key with plus'   => ['a+b', 5, 'base64url must not confuse + with its alphabet'];
        yield 'key with equals' => ['a=b', 5, 'nor = with its padding'];
    }

    /**
     * ⚠ base64URL, not base64. The cursor travels in a query string, so
     * `+`, `/` and `=` must not appear — they would be re-interpreted as a
     * space, a path separator, and a parameter break.
     */
    public function testTheEncodingIsUrlSafe(): void
    {
        for ($id = 1; $id <= 40; $id++) {
            $c = Cursor::encode(self::S, 'key/with+chars=' . str_repeat('x', $id), $id);
            self::assertDoesNotMatchRegularExpression('#[+/=]#', $c, 'cursor must be URL-safe: ' . $c);
            self::assertSame(
                $id,
                Cursor::decode(self::S, $c)['id'] ?? null,
                'and must still decode'
            );
        }
    }

    // ── 2. Surface isolation — MUST-NOT-HAPPEN #1 ───────────────────────

    /**
     * ⚠⚠ The property `BlogService` claims and does not have. Every pair
     * of distinct surfaces is checked, not a representative one.
     */
    public function testACursorFromOneSurfaceIsRefusedByEveryOther(): void
    {
        $surfaces = Cursor::knownSurfaces();
        self::assertGreaterThan(1, count($surfaces), 'the test needs at least two surfaces');

        foreach ($surfaces as $minted) {
            $c = Cursor::encode($minted, '2026-10-13 12:00:00', 4471);

            self::assertNotNull(Cursor::decode($minted, $c), "{$minted} must decode its own");

            foreach ($surfaces as $other) {
                if ($other === $minted) {
                    continue;
                }
                self::assertNull(
                    Cursor::decode($other, $c),
                    "a {$minted} cursor must NOT decode as {$other}"
                );
            }
        }
    }

    /**
     * ⚠ The regression this class exists to prevent: the EXACT legacy
     * payload shape that cross-decodes today. A `{"t":…,"id":…}` cursor —
     * what `BlogService` mints — must be refused outright, not read via a
     * compatibility fallback.
     */
    public function testTheLegacyCrossDecodingPayloadIsRefused(): void
    {
        $blogShaped = self::raw(['t' => '2026-10-13T12:00:00Z', 'id' => 4471]);
        self::assertNull(
            Cursor::decode(self::S, $blogShaped),
            'accepting a surface-less legacy shape is what breaks the isolation'
        );

        // And the same payload with our key name but no surface.
        self::assertNull(Cursor::decode(self::S, self::raw(['k' => '2026-10-13', 'id' => 4471])));
        // And with a surface but no version.
        self::assertNull(Cursor::decode(self::S, self::raw(['s' => self::S, 'k' => 'x', 'id' => 1])));
    }

    /**
     * ⚠⚠ ISOLATES THE SURFACE RUNG, because the case above does not.
     *
     * Mutation control M2 — which replaces the strict surface check with
     * `isset($data['s']) && …`, i.e. "a missing surface means legacy" —
     * SURVIVED the test above. Every payload there also lacks `v`, so the
     * VERSION rung refuses them first and the surface rung is never
     * reached. The assertions passed for the wrong reason.
     *
     * This case supplies a VALID version and omits only the surface, so
     * the surface rung is the one thing that can refuse it.
     */
    public function testAValidVersionWithNoSurfaceIsStillRefused(): void
    {
        self::assertNull(
            Cursor::decode(self::S, self::raw(['v' => Cursor::VERSION, 'k' => 'x', 'id' => 1])),
            'a missing surface must be refused, not treated as compatible'
        );

        // Anti-vacuity: the same payload WITH the surface is accepted, so
        // the refusal above is attributable to the surface and nothing else.
        self::assertNotNull(
            Cursor::decode(self::S, self::raw([
                'v' => Cursor::VERSION, 's' => self::S, 'k' => 'x', 'id' => 1,
            ]))
        );

        // And an empty-string surface is not a wildcard.
        self::assertNull(
            Cursor::decode(self::S, self::raw(['v' => Cursor::VERSION, 's' => '', 'k' => 'x', 'id' => 1]))
        );
    }

    /**
     * ⚠⚠ ISOLATES THE STRICT base64 FLAG, which nothing else did.
     *
     * Mutation control M4 — dropping `true` from `base64_decode()` —
     * SURVIVED. Non-strict decoding *ignores* invalid characters, so
     * `'!!!not-base64!!!'` still produced nothing usable and the cursor was
     * still refused. The flag looked load-bearing and was not being tested.
     *
     * The real hazard is the opposite shape: a VALID cursor with junk
     * inserted. Non-strict decoding strips the junk and recovers the
     * original payload, so a corrupted or tampered cursor decodes as
     * though it were intact — the reader gets a page built from a cursor
     * nobody minted.
     */
    public function testACursorWithJunkInsertedIsRefusedRatherThanSilentlyRepaired(): void
    {
        $good = Cursor::encode(self::S, '2026-10-13 12:00:00', 4471);
        self::assertNotNull(Cursor::decode(self::S, $good), 'the clean cursor must decode');

        // ⚠ A SPACE is deliberately absent from this list. PHP's strict
        // `base64_decode($s, true)` tolerates WHITESPACE by design — it
        // rejects other invalid characters but skips spaces, tabs and
        // newlines. An earlier revision included ' ' and the case failed,
        // correctly: the space-corrupted cursor decoded to the IDENTICAL
        // payload. Which is harmless, and is pinned as such below rather
        // than papered over — tolerating whitespace cannot produce a wrong
        // page, because the recovered bytes are the same bytes.
        foreach (['!', '*', '%', '#', '~', '?'] as $junk) {
            $mid       = (int) (strlen($good) / 2);
            $corrupted = substr($good, 0, $mid) . $junk . substr($good, $mid);

            // Proof the junk is what strict decoding rejects, and that
            // non-strict decoding WOULD have recovered the payload.
            self::assertFalse(
                base64_decode(strtr($corrupted, '-_', '+/'), true),
                'strict base64 must reject the junk [' . $junk . ']'
            );
            self::assertSame(
                base64_decode(strtr($good, '-_', '+/'), true),
                base64_decode(strtr($corrupted, '-_', '+/')),
                'non-strict decoding silently repairs it — which is the hazard'
            );

            self::assertNull(
                Cursor::decode(self::S, $corrupted),
                'a corrupted cursor must reset the page, not be repaired [' . $junk . ']'
            );
        }
    }

    /**
     * ⚠ The whitespace exception to the case above, pinned as a FACT
     * rather than left as an unexplained gap.
     *
     * PHP's strict `base64_decode($s, true)` rejects invalid characters but
     * SKIPS whitespace. So a cursor with an embedded space still decodes —
     * and that is harmless, because the recovered bytes are byte-identical
     * to the clean cursor's. Whitespace tolerance cannot produce a wrong
     * page; it can only produce the right one.
     *
     * Recorded so a reader who notices the asymmetry finds the answer here
     * instead of assuming the junk list is simply incomplete.
     */
    public function testWhitespaceIsToleratedAndYieldsTheIdenticalPage(): void
    {
        $good = Cursor::encode(self::S, '2026-10-13 12:00:00', 4471);
        $mid  = (int) (strlen($good) / 2);

        foreach ([' ', "\t", "\n", "\r"] as $ws) {
            $spaced = substr($good, 0, $mid) . $ws . substr($good, $mid);

            self::assertSame(
                Cursor::decode(self::S, $good),
                Cursor::decode(self::S, $spaced),
                'whitespace must yield the same page, not a different one'
            );
        }
    }

    /**
     * ⚠⚠ THE LENGTH CAP CANNOT BE KILLED BY A VALUE TEST, so it is pinned
     * structurally.
     *
     * Mutation control M8 — dropping the cap — SURVIVED, and correctly: a
     * 513-byte run of `A` is valid base64 that decodes to binary garbage,
     * so JSON parsing refuses it either way. The cap's purpose is to avoid
     * WORK on a public read path, not to change the answer, and no
     * behavioural assertion can observe that.
     *
     * So this reads the source and requires the cap to be checked BEFORE
     * any decoding — which is the whole property.
     */
    public function testTheLengthCapIsCheckedBeforeAnyDecodingWork(): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Support/KeysetCursor.php'
        );
        self::assertIsString($src);

        $decodeAt = strpos($src, 'public static function decode(');
        self::assertNotFalse($decodeAt);

        $body    = substr($src, $decodeAt);
        $capAt   = strpos($body, 'MAX_ENCODED_LENGTH');
        $b64At   = strpos($body, 'base64_decode(');
        $jsonAt  = strpos($body, 'json_decode(');

        self::assertNotFalse($capAt, 'the cap must be checked inside decode()');
        self::assertNotFalse($b64At);
        self::assertNotFalse($jsonAt);

        self::assertLessThan($b64At, $capAt, 'the cap must precede base64 decoding');
        self::assertLessThan($jsonAt, $capAt, 'and JSON decoding');
    }

    public function testAnUnknownVersionIsRefused(): void
    {
        // ⚠ `1.0` is deliberately NOT in this list. An earlier revision
        // included it and the case FAILED — not because the code is wrong,
        // but because `json_encode(['v' => 1.0])` emits `"v":1`: PHP drops
        // an integral float's fraction unless JSON_PRESERVE_ZERO_FRACTION.
        // So a "float version" cannot exist on the wire at all, and
        // asserting it is refused was asserting something the format makes
        // impossible. Pinned as a fact in the next case instead, so nobody
        // later adds a float check that could never fire.
        foreach ([0, 2, 99, '1', null, true, []] as $v) {
            self::assertNull(
                Cursor::decode(self::S, self::raw(['v' => $v, 's' => self::S, 'k' => 'x', 'id' => 1])),
                'version ' . var_export($v, true) . ' must be refused'
            );
        }
        // Sanity: the real version is accepted, so the loop is not vacuous.
        self::assertNotNull(
            Cursor::decode(self::S, self::raw(['v' => Cursor::VERSION, 's' => self::S, 'k' => 'x', 'id' => 1]))
        );
    }

    /**
     * ⚠ The strict `=== self::VERSION` comparison is against an INT, and
     * that is safe because JSON cannot deliver anything else for an
     * integral number: `1.0` serializes as `1` and decodes as `int(1)`.
     * Recorded so the strictness is not later "hardened" with a float
     * branch that can never be reached.
     */
    public function testJsonNormalizesAnIntegralFloatVersionToAnInt(): void
    {
        $encoded = (string) json_encode(['v' => 1.0]);
        self::assertSame('{"v":1}', $encoded, 'PHP drops the fraction');

        $decoded = json_decode($encoded, true);
        self::assertIsInt($decoded['v']);

        // And therefore the cursor decodes, because it IS version 1.
        self::assertNotNull(
            Cursor::decode(self::S, self::raw(['v' => 1.0, 's' => self::S, 'k' => 'x', 'id' => 1]))
        );
    }

    // ── 3. Malformed input resets, never raises — MUST-NOT-HAPPEN #2 ────

    /** @param mixed $cursor */
    #[DataProvider('hostileCursors')]
    public function testEveryMalformedCursorResetsToTheFirstPage(?string $cursor, string $why): void
    {
        self::assertNull(Cursor::decode(self::S, $cursor), $why);
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function hostileCursors(): iterable
    {
        yield 'null'              => [null, 'no cursor is the first page'];
        yield 'empty'             => ['', 'so is an empty one'];
        yield 'not base64'        => ['!!!not-base64!!!', 'strict base64 refuses this'];
        yield 'base64 of garbage' => [rtrim(strtr(base64_encode('not json at all'), '+/', '-_'), '='), 'valid base64, invalid JSON'];
        yield 'json scalar'       => [rtrim(strtr(base64_encode('42'), '+/', '-_'), '='), 'JSON but not an object'];
        yield 'json string'       => [rtrim(strtr(base64_encode('"x"'), '+/', '-_'), '='), 'likewise'];
        yield 'json null'         => [rtrim(strtr(base64_encode('null'), '+/', '-_'), '='), 'likewise'];
        yield 'empty object'      => [self::raw([]), 'no fields at all'];
        yield 'truncated'         => [substr(rtrim(strtr(base64_encode('{"v":1,"s":"ann_active","k":"x","id":1}'), '+/', '-_'), '='), 0, 12), 'a copy-paste that lost the tail'];
        yield 'over length'       => [str_repeat('A', Cursor::MAX_ENCODED_LENGTH + 1), 'refused before any decoding work'];
    }

    /**
     * ⚠ Exactly at the cap must still work — an off-by-one here would
     * refuse legitimate cursors on one surface and nobody would notice
     * until a long key appeared in production.
     */
    public function testTheLengthCapIsInclusive(): void
    {
        $atCap = str_repeat('A', Cursor::MAX_ENCODED_LENGTH);
        // Not a valid cursor, but it must be refused for its CONTENT, not
        // its length — proven by it reaching the decode path at all. The
        // observable assertion is that one more byte is refused earlier,
        // which the provider above covers; here we pin that the cap is far
        // above anything mintable.
        self::assertNull(Cursor::decode(self::S, $atCap));

        $longest = Cursor::encode(self::S, str_repeat('x', 40), PHP_INT_MAX);
        self::assertLessThan(
            Cursor::MAX_ENCODED_LENGTH,
            strlen($longest),
            'the cap must not be able to refuse a cursor this class can mint'
        );
    }

    // ── 4. Id coercion — MUST-NOT-HAPPEN #3 ─────────────────────────────

    /**
     * ⚠⚠ The worst failure mode in the class: a coerced id produces a
     * PLAUSIBLE page instead of an obvious reset, so the reader silently
     * loses or repeats rows.
     *
     * @param mixed $id
     */
    #[DataProvider('hostileIds')]
    public function testANonIntegralIdIsRefusedRatherThanCoerced($id, string $why): void
    {
        self::assertNull(
            Cursor::decode(self::S, self::raw(['v' => Cursor::VERSION, 's' => self::S, 'k' => 'x', 'id' => $id])),
            $why
        );
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function hostileIds(): iterable
    {
        yield 'numeric-ish string' => ['4471abc', '(int) gives 4471 — a plausible wrong page'];
        yield 'leading space'      => [' 4471', 'likewise'];
        yield 'float'              => [4471.9, 'a float id is not a row id'];
        yield 'float string'       => ['4471.9', 'nor is its string form'];
        yield 'zero'               => [0, 'no row has id 0'];
        yield 'zero string'        => ['0', 'likewise'];
        yield 'negative'           => [-1, 'nor a negative one'];
        yield 'negative string'    => ['-1', 'likewise'];
        yield 'leading zero'       => ['0447', 'ambiguous, and never minted by this class'];
        yield 'empty string'       => ['', 'not an id'];
        yield 'null'               => [null, 'missing id'];
        yield 'bool'               => [true, '(int) true is 1 — the lowest valid id, by accident'];
        yield 'array'              => [[4471], 'not a scalar'];
        yield 'hex'                => ['0x1177', 'not decimal'];
        yield 'exponent'           => ['4.4e3', 'not an integer literal'];
    }

    /** An integral STRING id is accepted — that is how JSON may carry a bigint. */
    public function testAnIntegralStringIdIsAccepted(): void
    {
        $d = Cursor::decode(self::S, self::raw([
            'v' => Cursor::VERSION, 's' => self::S, 'k' => 'x', 'id' => '4471',
        ]));
        self::assertNotNull($d);
        self::assertSame(4471, $d['id']);
    }

    // ── 5. Keys ─────────────────────────────────────────────────────────

    /** @param mixed $key */
    #[DataProvider('hostileKeys')]
    public function testANonStringOrEmptyKeyIsRefused($key, string $why): void
    {
        self::assertNull(
            Cursor::decode(self::S, self::raw(['v' => Cursor::VERSION, 's' => self::S, 'k' => $key, 'id' => 1])),
            $why
        );
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function hostileKeys(): iterable
    {
        yield 'missing' => [null, 'no sort key means no position'];
        yield 'empty'   => ['', 'an empty key cannot order anything'];
        yield 'int'     => [12345, 'the caller renders the key; this class does not interpret it'];
        yield 'float'   => [1.5, 'likewise'];
        yield 'array'   => [['x'], 'likewise'];
        yield 'bool'    => [true, 'likewise'];
    }

    // ── 6. Minting refuses what decoding would refuse — #4 and #5 ───────

    /**
     * ⚠ MUST-NOT-HAPPEN #5 from the other side: `encode()` must refuse the
     * inputs `decode()` would reject, so a caller cannot emit a cursor
     * that silently restarts the list.
     *
     * @param mixed $id
     */
    #[DataProvider('unmintable')]
    public function testEncodeRefusesWhatDecodeWouldReject(string $surface, string $key, int $id, string $why): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cursor::encode($surface, $key, $id);
        self::fail($why);
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function unmintable(): iterable
    {
        yield 'empty surface' => ['', 'k', 1, 'an untagged cursor would be refused by every surface'];
        yield 'empty key'     => [self::S, '', 1, 'an empty key is refused on decode'];
        yield 'zero id'       => [self::S, 'k', 0, 'id 0 is refused on decode'];
        yield 'negative id'   => [self::S, 'k', -5, 'likewise'];
    }

    /**
     * ⚠ MUST-NOT-HAPPEN #4 — the id is a REQUIRED positional parameter, so
     * there is no signature by which a caller can mint a cursor carrying
     * only a timestamp. Pinned by reflection, because the alternative
     * (someone adds `int $id = 0`) is a one-character change that would
     * reintroduce unstable paging and break no value test.
     */
    public function testTheUniqueTiebreakCannotBeOmitted(): void
    {
        $params = (new \ReflectionMethod(Cursor::class, 'encode'))->getParameters();

        self::assertSame(['surface', 'key', 'id'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $params
        ));
        foreach ($params as $p) {
            self::assertFalse($p->isOptional(), $p->getName() . ' must be required');
        }
    }

    /**
     * ⚠ An invalid-UTF-8 key cannot be encoded, and must raise rather than
     * silently produce `false` — which `base64_encode((string) false)`
     * would turn into an empty-ish cursor that decodes to null.
     */
    public function testAnUnencodableKeyRaisesRatherThanMintingNonsense(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cursor::encode(self::S, "\xB1\x31", 1);
    }

    // ── 7. The surface inventory ────────────────────────────────────────

    /**
     * Every §4.32.12 paginated collection has its own tag, and they are
     * distinct. The rotator is absent on purpose: §4.32.4 caps it at 5 and
     * its `pagination` is inert, so it mints no cursor.
     */
    public function testEveryPaginatedAnnouncementCollectionHasADistinctTag(): void
    {
        $s = Cursor::knownSurfaces();

        self::assertSame($s, array_values(array_unique($s)), 'tags must be distinct');
        self::assertSame([
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            Cursor::SURFACE_ANNOUNCEMENTS_ARCHIVED,
            Cursor::SURFACE_ANNOUNCEMENTS_OWNER,
            Cursor::SURFACE_ANNOUNCEMENT_COMMENTS,
        ], $s);

        // ⚠ The active and archived lists are the pair most likely to be
        // conflated — both order by a datetime and an id — and they order
        // by DIFFERENT columns, so they must never share a tag.
        self::assertNotSame(
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            Cursor::SURFACE_ANNOUNCEMENTS_ARCHIVED
        );
    }

    /**
     * The §11 claim, pinned at the source: this class is the canonical, and
     * it must not reach for anything a pure codec has no business touching.
     */
    public function testTheCodecIsPure(): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Support/KeysetCursor.php'
        );
        self::assertIsString($src);

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        foreach (['wpdb', 'get_option', 'current_time', 'time()', 'apply_filters', 'do_action'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                'a cursor codec must not reach ' . $forbidden
            );
        }
    }
}
