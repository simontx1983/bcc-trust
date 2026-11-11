<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\BoundedIntParam;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The bounded-integer request parser — api-contract-v1.md §4 `/members`.
 *
 * ── ⚠⚠ WHAT THIS GUARDS AGAINST ─────────────────────────────────────────
 *  1. AN OUT-OF-RANGE VALUE IS SERVED. `?per_page=100000` answered 200 with
 *     a real `LIMIT 100000` on a `__return_true` route, because the
 *     declared `'maximum' => 50` does not bind when the argument also
 *     declares a `sanitize_callback`.
 *  2. INVALID INPUT IS REWRITTEN INTO VALID INPUT. This is the subtle one:
 *     `absint('-5') === 5`, so a negative page size was not refused — it
 *     was served as 5. Every negative case below exists because of that.
 *  3. A CLAMP CREEPS BACK IN. Clamping is right for the three existing
 *     `clamp*` helpers and wrong here: §4 promises the caller an error.
 *     `parse()` returns NULL, never a substituted value, and the
 *     never-substitutes case pins it.
 *
 * ⚠ This suite needs no WordPress. That is the point: it is the part of the
 * fix CI can actually execute, since the CI jobs provide no WP core. The
 * route-level behaviour is proved by
 * `scripts/tests/members-bounds-real-dispatch.php` against a real install.
 */
#[CoversClass(BoundedIntParam::class)]
final class BoundedIntParamTest extends TestCase
{
    private const MAX = 50;

    /** @param mixed $raw */
    #[DataProvider('accepted')]
    public function testAValueInsideTheBoundIsPreservedExactly($raw, int $expected): void
    {
        self::assertSame($expected, BoundedIntParam::parse($raw, 1, self::MAX), var_export($raw, true));
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function accepted(): iterable
    {
        yield 'string 1 (the minimum)'   => ['1', 1];
        yield 'string 20 (the default)'  => ['20', 20];
        yield 'string 50 (the maximum)'  => ['50', 50];
        yield 'string 49'                => ['49', 49];
        yield 'int 1 from a schema default'  => [1, 1];
        yield 'int 20 from a schema default' => [20, 20];
        yield 'int 50'                   => [50, 50];
        // Leading zeros are a legal spelling of a digit run. Accepted, and
        // pinned so nobody "tidies" it into a refusal by accident.
        yield 'leading zero'             => ['020', 20];
        yield 'all leading zeros'        => ['0000000001', 1];
    }

    /**
     * ⚠⚠ Every one of these was SERVED before the fix, several of them as a
     * different number than the caller asked for.
     *
     * @param mixed $raw
     */
    #[DataProvider('refused')]
    public function testAnInvalidOrOutOfRangeValueIsRefused($raw, string $why): void
    {
        self::assertNull(BoundedIntParam::parse($raw, 1, self::MAX), $why);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function refused(): iterable
    {
        // ── over the bound ──────────────────────────────────────────────
        yield 'one over the maximum'  => ['51', 'the measured defect was 51 answering 200'];
        yield 'the measured 100000'   => ['100000', 'issued a real LIMIT 100000'];
        yield 'absurd'               => ['999999999', 'still only ten digits, still refused'];
        yield 'int over the maximum'  => [51, 'a schema default outside its own bound is a bug, not a page size'];

        // ── under the bound ─────────────────────────────────────────────
        yield 'zero'                  => ['0', 'per_page=0 is not a page of zero rows'];
        yield 'int zero'              => [0, 'same, pre-typed'];

        // ── ⚠ negatives: absint turned every one of these POSITIVE ──────
        yield 'negative one'          => ['-1', 'absint made this 1'];
        yield 'negative five'         => ['-5', 'absint made this 5 and SERVED it'];
        yield 'negative over bound'   => ['-100000', 'absint made this 100000'];
        yield 'int negative'          => [-5, 'pre-typed negative'];

        // ── ⚠ fractional: absint truncated instead of refusing ──────────
        yield 'fractional small'      => ['2.7', 'absint made this 2'];
        yield 'fractional at bound'   => ['50.9', 'absint made this 50 — inside the bound by truncation'];
        yield 'fractional over bound' => ['51.1', 'absint made this 51'];
        yield 'float type'            => [2.7, 'a float is not a page size'];
        yield 'negative fractional'   => ['-2.7', 'absint made this 2'];

        // ── malformed ───────────────────────────────────────────────────
        yield 'empty string'          => ['', 'ctype_digit("") is false'];
        yield 'alphabetic'            => ['abc', 'absint made this 0'];
        yield 'trailing junk'         => ['20abc', 'absint made this 20'];
        yield 'leading junk'          => ['abc20', 'absint made this 0'];
        yield 'scientific notation'   => ['1e3', 'absint made this 1'];
        yield 'hex'                   => ['0x10', 'absint made this 0'];
        yield 'explicit plus'         => ['+20', 'one request should have one spelling'];
        yield 'leading space'         => [' 20', 'no trim: decoration is malformation'];
        yield 'trailing space'        => ['20 ', 'same'];
        yield 'inner space'           => ['2 0', 'same'];
        yield 'comma grouped'         => ['1,000', 'absint made this 1'];
        yield 'unicode digit'         => ['٢٠', 'ctype_digit is ASCII-only, deliberately'];
        yield 'null'                  => [null, 'absent is handled by the schema default, not here'];
        yield 'true'                  => [true, 'absint(true) === 1'];
        yield 'false'                 => [false, 'absint(false) === 0'];
        yield 'array'                 => [['20'], 'absint of an array is a PHP notice and 1'];

        // ── overflow ────────────────────────────────────────────────────
        // (int) saturates at PHP_INT_MAX rather than failing, so a digit run
        // long enough to overflow is refused on length before the cast.
        yield 'eleven digits'         => ['10000000000', 'longer than any page could be'];
        yield 'twenty digits'         => ['99999999999999999999', '(int) would saturate silently'];
    }

    /**
     * `page` has no documented maximum, so a null `$max` must mean
     * "unbounded above" and not "bounded at zero".
     *
     * @param mixed $raw
     */
    #[DataProvider('unboundedAbove')]
    public function testANullMaximumMeansNoUpperBound($raw, ?int $expected): void
    {
        self::assertSame($expected, BoundedIntParam::parse($raw, 1, null), var_export($raw, true));
    }

    /** @return iterable<string, array{mixed, int|null}> */
    public static function unboundedAbove(): iterable
    {
        yield 'page 1'            => ['1', 1];
        yield 'page 9999'         => ['9999', 9999];
        yield 'page 1000000000'   => ['1000000000', 1000000000];
        yield 'page 0 still refused'    => ['0', null];
        yield 'page -1 still refused'   => ['-1', null];
        yield 'page junk still refused' => ['abc', null];
        yield 'eleven digits still refused' => ['10000000000', null];
    }

    /**
     * ⚠⚠ THE ANTI-CLAMP CASE. The three existing `clamp*` helpers in this
     * codebase return a substituted value; this parser must never do that,
     * because §4 promises the caller an error for `/members` and a
     * substitution is how the original defect was invisible.
     */
    public function testItNeverSubstitutesAValue(): void
    {
        foreach (['0', '51', '-5', '100000', 'abc', '2.7'] as $raw) {
            $out = BoundedIntParam::parse($raw, 1, self::MAX);
            self::assertNull($out, 'parse(' . $raw . ') must refuse, not substitute');
        }

        // And the mirror: a value inside the bound comes back as itself,
        // never nudged to a default.
        self::assertSame(37, BoundedIntParam::parse('37', 1, self::MAX));
    }

    /**
     * `keepScalar` is a sanitizer whose job is to NOT sanitize. If it ever
     * starts casting, the parser loses the information it refuses on — which
     * is precisely the `absint` defect returning by the back door.
     *
     * @param mixed $raw
     */
    #[DataProvider('scalarsKept')]
    public function testKeepScalarPreservesWhatTheCallerSent($raw, $expected): void
    {
        self::assertSame($expected, BoundedIntParam::keepScalar($raw), var_export($raw, true));
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function scalarsKept(): iterable
    {
        yield 'negative text survives'   => ['-5', '-5'];
        yield 'fractional text survives' => ['2.7', '2.7'];
        yield 'junk survives'            => ['abc', 'abc'];
        yield 'spaced text survives'     => [' 20', ' 20'];
        yield 'digits survive'           => ['20', '20'];
        yield 'int survives as int'      => [20, 20];
        yield 'array becomes empty'      => [[1], ''];
        yield 'null becomes empty'       => [null, ''];
        yield 'bool becomes empty'       => [true, ''];
        yield 'float becomes empty'      => [2.7, ''];
    }

    /**
     * ⚠ A round-trip through the real sanitizer, because the two halves are
     * only correct together: `keepScalar` must hand `parse()` something
     * `parse()` can still refuse.
     */
    #[DataProvider('refusedThroughTheSanitizer')]
    public function testTheSanitizerAndParserRefuseTogether(string $raw): void
    {
        $sanitized = BoundedIntParam::keepScalar($raw);

        self::assertNull(
            BoundedIntParam::parse($sanitized, 1, self::MAX),
            $raw . ' survived the sanitizer and then the parser'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function refusedThroughTheSanitizer(): iterable
    {
        yield '-5'     => ['-5'];
        yield '2.7'    => ['2.7'];
        yield '51'     => ['51'];
        yield '100000' => ['100000'];
        yield '0'      => ['0'];
        yield 'abc'    => ['abc'];
        yield ' 20'    => [' 20'];
    }
}
