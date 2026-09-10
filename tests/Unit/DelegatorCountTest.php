<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\ValueObjects\DelegatorCount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: only a structurally valid success response can produce a
 * number. Everything else is UNKNOWN, and unknown is never zero.
 *
 * ── WHAT THIS PINS ──────────────────────────────────────────────────────
 * The shipped code read the count as
 * `(int) ($delegations['pagination']['total'] ?? 0)`, which turned a refused
 * request, a malformed body and an integer overflow into the same confident
 * "0 delegators" — written over a real number on a rendered validator card.
 *
 * The cases below are chosen so that the OLD expression would fail almost
 * every one of them: `(int) "abc"`, `(int) "12.9"` and
 * `(int) "99999999999999999999"` are 0, 12 and PHP_INT_MAX respectively, and
 * all three are wrong answers to "how many delegators are there".
 */
#[CoversClass(DelegatorCount::class)]
final class DelegatorCountTest extends TestCase
{
    /** @return array<string, array{mixed, int|null}> */
    public static function totals(): array
    {
        return [
            // ── the only inputs that may yield a number ──────────────────
            'explicit zero as string'   => ['0', 0],
            'explicit zero as int'      => [0, 0],
            'zero with leading zeros'   => ['0000', 0],
            'one'                       => ['1', 1],
            'typical count'             => ['12345', 12345],
            'int passthrough'           => [42, 42],
            'leading zeros trimmed'     => ['007', 7],
            'surrounding whitespace'    => [" 7\n", 7],
            'php int max exactly'       => [(string) PHP_INT_MAX, PHP_INT_MAX],

            // ── overflow: must NOT saturate ──────────────────────────────
            'php int max plus one'      => ['9223372036854775808', null],
            'twenty digits'             => ['99999999999999999999', null],
            'absurdly long digits'      => [str_repeat('9', 40), null],

            // ── malformed: must NOT become 0 ─────────────────────────────
            'empty string'              => ['', null],
            'whitespace only'           => ['   ', null],
            'letters'                   => ['abc', null],
            'decimal string'            => ['1.0', null],
            'truncating decimal'        => ['12.9', null],
            'exponent'                  => ['1e3', null],
            'hex'                       => ['0x10', null],
            'explicit plus sign'        => ['+1', null],
            'negative string'           => ['-1', null],
            'digits with suffix'        => ['12abc', null],
            'thousands separator'       => ['1,234', null],

            // ── wrong types: must NOT become 0 ───────────────────────────
            'negative int'              => [-1, null],
            'float'                     => [1.5, null],
            'float that looks integral' => [12.0, null],
            'true'                      => [true, null],
            'false'                     => [false, null],
            'null'                      => [null, null],
            'array'                     => [[], null],
        ];
    }

    /** @param mixed $raw */
    #[DataProvider('totals')]
    public function testParseTotal($raw, ?int $expected): void
    {
        $actual = DelegatorCount::parseTotal($raw);

        // assertSame, not assertEquals: 0 and null and '0' must not be
        // interchangeable here — telling them apart is the entire point.
        self::assertSame($expected, $actual);
    }

    /**
     * ⚠ ANTI-VACUITY. If `parseTotal` ever returned null for everything the
     * table above would still pass for the 20 unknown rows, so prove the
     * happy path is genuinely reachable and genuinely typed.
     */
    public function testKnownValuesAreRealIntegers(): void
    {
        $known = DelegatorCount::parseTotal('12345');
        self::assertIsInt($known);
        self::assertSame(12345, $known);
        self::assertNotNull(DelegatorCount::parseTotal('0'));
    }

    /** @return array<string, array{array<string, mixed>|null, int|null}> */
    public static function responses(): array
    {
        return [
            'null body'               => [null, null],
            'empty body'              => [[], null],
            'no pagination key'       => [['delegation_responses' => []], null],
            'pagination not an array' => [['pagination' => 'nope'], null],
            'pagination without total'=> [['pagination' => ['next_key' => null]], null],
            'total present but null'  => [['pagination' => ['total' => null]], null],
            'total zero'              => [['pagination' => ['total' => '0']], 0],
            'total positive'          => [['pagination' => ['total' => '87']], 87],
            'total overflowing'       => [['pagination' => ['total' => '99999999999999999999']], null],
            'total malformed'         => [['pagination' => ['total' => 'many']], null],
        ];
    }

    /** @param array<string, mixed>|null $data */
    #[DataProvider('responses')]
    public function testFromDelegationsResponse(?array $data, ?int $expected): void
    {
        self::assertSame($expected, DelegatorCount::fromDelegationsResponse($data));
    }

    /**
     * THE RESOLUTION RULE, stated as a table.
     *
     * ⚠ An explicitly reported zero MUST beat the previous value. "Unknown
     * keeps the old number" would otherwise quietly become "the count can
     * never fall to zero", which is a different bug in the other direction —
     * a validator really can lose its last delegator.
     *
     * @return array<string, array{int|null, int|null, int|null}>
     */
    public static function resolutions(): array
    {
        return [
            'unknown keeps previous'        => [null, 7, 7],
            'unknown with no previous'      => [null, null, null],
            'explicit zero overwrites'      => [0, 7, 0],
            'explicit zero with no previous'=> [0, null, 0],
            'new value overwrites'          => [9, 7, 9],
            'new value with no previous'    => [3, null, 3],
        ];
    }

    #[DataProvider('resolutions')]
    public function testResolve(?int $fetched, ?int $previous, ?int $expected): void
    {
        self::assertSame($expected, DelegatorCount::resolve($fetched, $previous));
    }
}
