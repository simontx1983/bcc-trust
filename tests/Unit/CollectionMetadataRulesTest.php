<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\ValueObjects\CollectionMetadataRules as Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PR 7 — the validation boundary for collection metadata.
 *
 * These are the rules that decide what may be persisted at all. The product
 * ones matter most: a realistic marketplace payload full of prices must not
 * survive this boundary, and an unavailable collection size must not become
 * a fabricated zero.
 */
final class CollectionMetadataRulesTest extends TestCase
{
    // ── collection size ─────────────────────────────────────────────────

    /** @return list<array{mixed, int|null, string|null}> */
    public static function supplyCases(): array
    {
        return [
            'plain integer'          => [12, 12, null],
            'zero is a real answer'  => [0, 0, null],
            'numeric string'         => ['4200', 4200, null],
            'leading zeros'          => ['007', 7, null],
            'ceiling exactly'        => ['4294967295', 4294967295, null],

            'absent null'            => [null, null, Rules::REASON_ABSENT],
            'absent empty string'    => ['', null, Rules::REASON_ABSENT],

            'negative int'           => [-1, null, Rules::REASON_NEGATIVE],
            'negative string'        => ['-5', null, Rules::REASON_NEGATIVE],

            'over the ceiling'       => ['4294967296', null, Rules::REASON_OVERFLOW],
            'far over the ceiling'   => ['999999999999999999999999', null, Rules::REASON_OVERFLOW],
            'int over the ceiling'   => [4294967296, null, Rules::REASON_OVERFLOW],

            'float'                  => [12.0, null, Rules::REASON_MALFORMED],
            'float string'           => ['12.5', null, Rules::REASON_MALFORMED],
            'exponent'               => ['1e5', null, Rules::REASON_MALFORMED],
            'hex'                    => ['0x10', null, Rules::REASON_MALFORMED],
            'trailing junk'          => ['12abc', null, Rules::REASON_MALFORMED],
            'thousands separator'    => ['1,200', null, Rules::REASON_MALFORMED],
            'inner space'            => ['1 200', null, Rules::REASON_MALFORMED],
            'plus sign'              => ['+12', null, Rules::REASON_MALFORMED],
            'boolean'                => [true, null, Rules::REASON_MALFORMED],
            'array'                  => [[1], null, Rules::REASON_MALFORMED],
        ];
    }

    #[DataProvider('supplyCases')]
    public function testTotalSupplyValidation(mixed $raw, ?int $expected, ?string $reason): void
    {
        $result = Rules::validateTotalSupply($raw);

        self::assertSame($expected, $result['value']);
        self::assertSame($reason, $result['reason']);
    }

    /**
     * ⚠ The whole point of refusing rather than clamping.
     *
     * A clamped 4,294,967,295 is a number nobody measured, presented as a
     * fact. "Unknown" is the only honest answer for a value the column
     * cannot hold.
     */
    public function testOverflowIsRefusedNeverClamped(): void
    {
        $result = Rules::validateTotalSupply('4294967296');

        self::assertNull($result['value']);
        self::assertNotSame(Rules::MAX_TOTAL_SUPPLY, $result['value']);
        self::assertSame(Rules::REASON_OVERFLOW, $result['reason']);
    }

    /** An unavailable count is unknown, and unknown is never 0. */
    public function testUnavailableSupplyIsNeverZero(): void
    {
        foreach ([null, '', 'n/a', [], false] as $raw) {
            self::assertNull(Rules::validateTotalSupply($raw)['value']);
        }
    }

    /**
     * A 30-digit value exceeds PHP_INT_MAX. Casting first would saturate to
     * PHP_INT_MAX and compare BELOW the ceiling, turning an overflow into a
     * plausible-looking number — so the comparison is done on the string.
     */
    public function testHugeValuesAreNotSaturatedByAnIntCast(): void
    {
        $huge = str_repeat('9', 30);
        self::assertSame(Rules::REASON_OVERFLOW, Rules::validateTotalSupply($huge)['reason']);
    }

    // ── text sanitization ───────────────────────────────────────────────

    public function testSymbolIsStrippedOfMarkupAndBounded(): void
    {
        self::assertSame('BAYC', Rules::sanitizeSymbol('  BAYC  '));
        self::assertSame('alert(1)', Rules::sanitizeSymbol('<script>alert(1)</script>'));
        self::assertNull(Rules::sanitizeSymbol('   '));
        self::assertNull(Rules::sanitizeSymbol(''));
        self::assertNull(Rules::sanitizeSymbol(null));

        $long = str_repeat('A', 100);
        self::assertSame(Rules::MAX_SYMBOL, mb_strlen((string) Rules::sanitizeSymbol($long)));
    }

    public function testControlAndDirectionalCharactersAreRemoved(): void
    {
        // U+202E RIGHT-TO-LEFT OVERRIDE reverses rendered text and is a
        // display-spoofing vector, not a legitimate symbol character.
        $spoof = "Good\u{202E}livE";
        $clean = (string) Rules::sanitizeText($spoof, 64);

        self::assertStringNotContainsString("\u{202E}", $clean);
        self::assertStringNotContainsString("\x00", (string) Rules::sanitizeText("a\x00b", 64));
    }

    public function testEmptyAfterSanitizationBecomesNull(): void
    {
        // Markup that reduces to nothing is absence, not an empty string —
        // so it lands as SQL NULL rather than as the '' that made 119
        // production rows unfillable.
        self::assertNull(Rules::sanitizeText('<br/>', 64));
        self::assertNull(Rules::sanitizeText("\n\t  \r", 64));
    }

    public function testDescriptionIsBounded(): void
    {
        $long = str_repeat('word ', 2000);
        $out  = (string) Rules::sanitizeDescription($long);

        self::assertLessThanOrEqual(Rules::MAX_DESCRIPTION, mb_strlen($out));
    }

    // ── image URL ───────────────────────────────────────────────────────

    public function testImageUrlAcceptsOnlyHttps(): void
    {
        self::assertSame('https://cdn.test/a.png', Rules::sanitizeImageUrl('https://cdn.test/a.png'));

        // http is a mixed-content warning on every page that renders it;
        // data:/javascript: are injection dressed as an image; ipfs:// is
        // refused rather than rewritten because choosing a gateway is a
        // product decision, not a sanitizer's.
        foreach ([
            'http://cdn.test/a.png',
            'javascript:alert(1)',
            'data:image/png;base64,AAAA',
            'ipfs://QmHash',
            '//cdn.test/a.png',
            'cdn.test/a.png',
        ] as $bad) {
            self::assertNull(Rules::sanitizeImageUrl($bad), "must refuse: {$bad}");
        }
    }

    public function testImageUrlRefusesControlCharacters(): void
    {
        self::assertNull(Rules::sanitizeImageUrl("https://cdn.test/a.png\nSet-Cookie: x"));
    }

    // ── absence + overwrite rules ───────────────────────────────────────

    /**
     * ⚠ The rule that makes the empty-string image rows recoverable.
     * `''` and `null` are the SAME absence at the write boundary.
     */
    public function testEmptyStringAndNullAreTheSameAbsence(): void
    {
        self::assertNull(Rules::absentToNull(''));
        self::assertNull(Rules::absentToNull('   '));
        self::assertNull(Rules::absentToNull(null));
        self::assertSame('x', Rules::absentToNull('x'));
        self::assertSame(0, Rules::absentToNull(0), 'a real zero is not absence');
    }

    /**
     * A provider that times out must not blank a confirmed collection size.
     * An unavailable answer is not evidence the old one became wrong.
     */
    public function testAbsentIncomingNeverOverwritesAConfirmedValue(): void
    {
        self::assertFalse(Rules::shouldOverwrite(1000, null));
        self::assertFalse(Rules::shouldOverwrite('BAYC', ''));
        self::assertFalse(Rules::shouldOverwrite('BAYC', '   '));

        self::assertTrue(Rules::shouldOverwrite(null, 1000));
        self::assertTrue(Rules::shouldOverwrite(1000, 1001));
        self::assertFalse(Rules::shouldOverwrite(1000, 1000), 'no write when nothing changed');
    }

    // ── ⚠ market data ───────────────────────────────────────────────────

    /**
     * A realistic marketplace payload. Every market key must be gone after
     * the parser boundary — not merely unused downstream, but absent.
     */
    public function testARealisticPricePayloadDoesNotSurviveTheBoundary(): void
    {
        $payload = [
            'name'               => 'Bored Ape Yacht Club',
            'symbol'             => 'BAYC',
            'totalSupply'        => '10000',
            'floor_price'        => 12.5,
            'floorPrice'         => 12.5,
            'floor_currency'     => 'ETH',
            'total_volume'       => 998877.25,
            'volume'             => 100,
            'listed_percentage'  => 3.14,
            'listedCount'        => 314,
            'sales_count'        => 4242,
            'itemsSold'          => 4242,
            'lastSale'           => ['price' => 30.0],
            'royalty_percentage' => 2.5,
            'sellerFeeBasisPoints' => 250,
            'marketCap'          => 125000,
            'openSeaMetadata'    => [
                'floorPrice'     => 12.5,
                'collectionName' => 'BAYC',
            ],
        ];

        $clean = Rules::stripMarketFields($payload);

        foreach (Rules::marketFieldNames() as $banned) {
            self::assertArrayNotHasKey($banned, $clean, "{$banned} must not survive");
        }

        // Community identity survives — that is the point of the boundary.
        self::assertSame('Bored Ape Yacht Club', $clean['name']);
        self::assertSame('BAYC', $clean['symbol']);
        self::assertSame('10000', $clean['totalSupply']);
    }

    /** Providers nest market data; a shallow strip would leave it behind. */
    public function testNestedMarketDataIsStrippedRecursively(): void
    {
        $clean = Rules::stripMarketFields([
            'contract' => [
                'name'       => 'Thing',
                'floorPrice' => 9.99,
                'deeper'     => ['lastSale' => 1, 'symbol' => 'THG'],
            ],
        ]);

        self::assertArrayNotHasKey('floorPrice', $clean['contract']);
        self::assertArrayNotHasKey('lastSale', $clean['contract']['deeper']);
        self::assertSame('THG', $clean['contract']['deeper']['symbol']);
    }

    /**
     * The prohibition list is the product rule made mechanical. Removing an
     * entry is a product decision, not a refactor — so the suite pins the
     * families that must always be covered.
     */
    public function testTheProhibitionListCoversEveryMarketFamily(): void
    {
        $names = implode(' ', Rules::marketFieldNames());

        foreach (['floor', 'price', 'volume', 'listed', 'sale', 'royalt', 'marketCap'] as $family) {
            self::assertStringContainsStringIgnoringCase($family, $names, "no {$family} coverage");
        }
    }

    /**
     * There is no validator, getter or storage helper for a market value.
     *
     * ⚠ Written as a real scan of the class SOURCE rather than a loop with an
     * exclusion that silently compares an empty string — an earlier draft did
     * exactly that and the assertion could not fail. The two prohibition
     * methods are located by name and their bodies excluded by SPAN, so the
     * banned vocabulary is genuinely absent from everything else.
     */
    public function testNoMarketValueAccessorExists(): void
    {
        $reflection = new \ReflectionClass(Rules::class);
        $file       = (string) $reflection->getFileName();
        $source     = (string) file_get_contents($file);

        // The prohibition itself must name market fields; nothing else may.
        foreach (['marketFieldNames', 'stripMarketFields'] as $allowed) {
            $method = $reflection->getMethod($allowed);
            $lines  = file($file) ?: [];
            $span   = implode('', array_slice(
                $lines,
                (int) $method->getStartLine() - 1,
                (int) $method->getEndLine() - (int) $method->getStartLine() + 1
            ));
            $source = str_replace($span, '', $source);
            self::assertNotSame('', $span, "{$allowed} span must be found");
        }

        // Comments legitimately explain the prohibition; code must not implement it.
        $codeOnly = '';
        foreach (token_get_all($source) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= is_array($tok) ? $tok[1] : $tok;
        }

        foreach (['floorPrice', 'floor_price', 'totalVolume', 'total_volume',
                  'listedPercentage', 'royaltyPercentage', 'lastSale'] as $banned) {
            self::assertStringNotContainsString(
                $banned,
                $codeOnly,
                "{$banned} appears in code outside the prohibition list"
            );
        }
    }
}
