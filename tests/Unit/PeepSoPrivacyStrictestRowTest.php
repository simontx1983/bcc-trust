<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * PeepSoPrivacy::fromGroupPostId() takes the STRICTEST privacy row.
 *
 * A group can carry more than one `peepso_group_privacy` row. This resolver
 * used to pass `$single = true`, which returns only the FIRST row by
 * `meta_id`, so a group with rows '0' then '2' resolved to **Open**. Because
 * `GroupContextResolver` calls this once and every group gate reads the
 * result, that single wrong answer reached all of them at once:
 * `resolveGroupAccess` skipped the secret 404, a non-member could open the
 * group by id or slug and read its `public_group` / `public_all` teasers, and
 * feed hydration emitted its name and link — while every LIST and SEARCH read
 * already hid it, because those had been fixed to mean "ANY row reads 2".
 * The two halves of the same rule disagreed.
 *
 * ⚠ Order independence is the point, so every two-row case appears in both
 * orders. A fix that merely read the LAST row instead of the first would pass
 * half of these and fail the other half.
 *
 * Process isolation is required, not cosmetic: the stub defines
 * `get_post_meta` inside this enum's own namespace.
 */
#[CoversClass(PeepSoPrivacy::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoPrivacyStrictestRowTest extends TestCase
{
    private const GROUP = 77;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/peepso-privacy-rows-stubs.php';
        $GLOBALS['__bcc_privacy_rows'] = [];
    }

    /** @param list<mixed> $rows */
    private function resolve(array $rows): PeepSoPrivacy
    {
        $GLOBALS['__bcc_privacy_rows'] = [self::GROUP => $rows];
        return PeepSoPrivacy::fromGroupPostId(self::GROUP);
    }

    // ── one row: unchanged behaviour ───────────────────────────────────

    #[DataProvider('singleRows')]
    public function testASingleRowResolvesAsBefore(string $value, PeepSoPrivacy $expected): void
    {
        self::assertSame($expected, $this->resolve([$value]));
    }

    /** @return iterable<string, array{0: string, 1: PeepSoPrivacy}> */
    public static function singleRows(): iterable
    {
        yield 'open'   => ['0', PeepSoPrivacy::Open];
        yield 'closed' => ['1', PeepSoPrivacy::Closed];
        yield 'secret' => ['2', PeepSoPrivacy::Secret];
    }

    // ── the regression: two rows, strictest wins, either order ─────────

    /**
     * ⭐ The case this fix exists for. Before it, the first of these
     * resolved to Open and a secret group was openable by id.
     *
     * @param list<string> $rows
     */
    #[DataProvider('twoRowCases')]
    public function testTheStrictestRowWinsWhateverTheOrder(array $rows, PeepSoPrivacy $expected): void
    {
        self::assertSame($expected, $this->resolve($rows));
    }

    /** @return iterable<string, array{0: list<string>, 1: PeepSoPrivacy}> */
    public static function twoRowCases(): iterable
    {
        yield 'open then secret'   => [['0', '2'], PeepSoPrivacy::Secret];
        yield 'secret then open'   => [['2', '0'], PeepSoPrivacy::Secret];
        yield 'closed then secret' => [['1', '2'], PeepSoPrivacy::Secret];
        yield 'secret then closed' => [['2', '1'], PeepSoPrivacy::Secret];
        yield 'open then closed'   => [['0', '1'], PeepSoPrivacy::Closed];
        yield 'closed then open'   => [['1', '0'], PeepSoPrivacy::Closed];
        yield 'three rows, one secret in the middle' => [['0', '2', '1'], PeepSoPrivacy::Secret];
        yield 'many rows, none secret'               => [['0', '0', '1', '0'], PeepSoPrivacy::Closed];
    }

    /**
     * ⚠ ANTI-VACUITY, and the control that matters most. If the resolver
     * simply answered Secret whenever it saw more than one row, every case
     * above would pass and the resolver would hide half the site.
     *
     * @param list<string> $rows
     */
    #[DataProvider('multiRowOpenCases')]
    public function testSeveralRowsThatAreAllOpenStayOpen(array $rows): void
    {
        self::assertSame(PeepSoPrivacy::Open, $this->resolve($rows));
    }

    /** @return iterable<string, array{0: list<string>}> */
    public static function multiRowOpenCases(): iterable
    {
        yield 'two open rows'   => [['0', '0']];
        yield 'three open rows' => [['0', '0', '0']];
    }

    // ── absence and malformed rows: deliberately still Open ────────────

    public function testNoPrivacyRowIsOpen(): void
    {
        self::assertSame(PeepSoPrivacy::Open, $this->resolve([]));
    }

    /**
     * Deliberately unchanged. PeepSo and `createPlainGroup` always write
     * privacy at creation, so this is a theoretical edge, and promoting
     * corrupt meta to Secret would hide live groups on the strength of a
     * malformed row — a different decision with a far wider blast radius.
     *
     * @param list<mixed> $rows
     */
    #[DataProvider('malformedRows')]
    public function testAMalformedRowDoesNotMakeAGroupSecret(array $rows): void
    {
        self::assertSame(PeepSoPrivacy::Open, $this->resolve($rows));
    }

    /** @return iterable<string, array{0: list<mixed>}> */
    public static function malformedRows(): iterable
    {
        yield 'an empty string'      => [['']];
        yield 'a non-numeric string' => [['secret']];
        yield 'an array row'         => [[['2']]];
        yield 'null'                 => [[null]];
    }

    /**
     * A malformed row must not MASK a real secret row either — the loop
     * skips what it cannot read rather than stopping at it.
     */
    public function testAMalformedRowBeforeASecretOneIsSkippedNotFatal(): void
    {
        self::assertSame(PeepSoPrivacy::Secret, $this->resolve([['junk'], '2']));
        self::assertSame(PeepSoPrivacy::Secret, $this->resolve(['nonsense', '2']));
    }

    // ── value shapes wp_postmeta actually stores ───────────────────────

    /**
     * wp_postmeta stores strings, and PeepSo writes through an int cast, so
     * both shapes occur. Integers are included because a fixture or a
     * migration can write them.
     *
     * @param list<mixed> $rows
     */
    #[DataProvider('valueShapes')]
    public function testIntAndStringRowsAreReadTheSame(array $rows, PeepSoPrivacy $expected): void
    {
        self::assertSame($expected, $this->resolve($rows));
    }

    /** @return iterable<string, array{0: list<mixed>, 1: PeepSoPrivacy}> */
    public static function valueShapes(): iterable
    {
        yield 'int rows'            => [[0, 2], PeepSoPrivacy::Secret];
        yield 'mixed int and string' => [['0', 2], PeepSoPrivacy::Secret];
        yield 'int closed'          => [[1], PeepSoPrivacy::Closed];
    }

    /**
     * ⚠ The OLD behaviour, reproduced against the same stub, so these tests
     * are known to catch it. The shim honours `$single` exactly as WordPress
     * does — first row, or '' — which is what made the defect possible.
     */
    public function testTheOldSingleRowReadAnsweredOpenForASecretGroup(): void
    {
        $GLOBALS['__bcc_privacy_rows'] = [self::GROUP => ['0', '2']];

        $first = \BCC\Trust\Core\ValueObjects\get_post_meta(self::GROUP, 'peepso_group_privacy', true);

        self::assertSame('0', $first, 'the stub must reproduce WordPress: $single returns the FIRST row');
        self::assertSame(
            PeepSoPrivacy::Open,
            match ((int) $first) {
                2       => PeepSoPrivacy::Secret,
                1       => PeepSoPrivacy::Closed,
                default => PeepSoPrivacy::Open,
            },
            'the superseded resolver answered Open here'
        );
        // And the current one does not.
        self::assertSame(PeepSoPrivacy::Secret, PeepSoPrivacy::fromGroupPostId(self::GROUP));
    }

    public function testADegenerateGroupIdIsOpenRatherThanAnError(): void
    {
        self::assertSame(PeepSoPrivacy::Open, PeepSoPrivacy::fromGroupPostId(0));
        self::assertSame(PeepSoPrivacy::Open, PeepSoPrivacy::fromGroupPostId(-1));
    }
}
