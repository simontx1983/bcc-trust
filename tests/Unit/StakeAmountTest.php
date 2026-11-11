<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\StakeAmount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-66: StakeAmount::sortable() is the one place a provider amount becomes a
 * bccomp() operand, and every value it returns must be one bccomp() accepts.
 * Extracted from PolkadotFetcher::bondedTotal(), whose behaviour it keeps.
 */
#[CoversClass(StakeAmount::class)]
final class StakeAmountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function amounts(): array
    {
        return [
            'digits'              => ['123456789012345678901234567890', '123456789012345678901234567890'],
            'decimal'             => ['12.5', '12.5'],
            'zero'                => ['0', '0'],
            'int'                 => [42, '42'],
            'exponent form'       => ['1e9', '0'],
            'leading space'       => [' 5', '0'],
            'trailing dot'        => ['5.', '0'],
            'negative'            => ['-5', '0'],
            'empty string'        => ['', '0'],
            'null'                => [null, '0'],
            'float'               => [1.5, '0'],
            'array'               => [['5'], '0'],
            'bool'                => [true, '0'],
            'hex'                 => ['0x1f', '0'],
        ];
    }

    #[DataProvider('amounts')]
    public function testSortable(mixed $raw, string $expected): void
    {
        $out = StakeAmount::sortable($raw);

        self::assertSame($expected, $out);
        // Never throws: every output is a well-formed bcmath operand.
        self::assertIsInt(bccomp($out, '0'));
    }

    public function testCompareDescOrdersHighFirstAndTreatsUnreadableAsZero(): void
    {
        $rows = [['s' => '5'], ['s' => '1e9'], ['s' => '900'], [], ['s' => ['x']], ['s' => '0.5']];

        usort($rows, static fn (array $a, array $b): int => StakeAmount::compareDesc($a, $b, 's'));

        self::assertSame('900', $rows[0]['s']);
        self::assertSame('5', $rows[1]['s']);
        // ⚠ bccomp() at the default scale (0) compares integer parts only, so
        // '0.5' ties with the unreadable rows (which read as '0'). That is the
        // behaviour bondedTotal() had and keeps; stake amounts are integers in
        // base units on every chain that uses this.
        self::assertSame(0, StakeAmount::compareDesc(['s' => '0.5'], ['s' => '1e9'], 's'));
    }
}
