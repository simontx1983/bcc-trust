<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\StrictBoolParam;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-294: a request boolean is parsed strictly; anything that is not one is
 * null (the caller answers 422), never silently false.
 */
final class StrictBoolParamTest extends TestCase
{
    /** @return iterable<string, array{mixed, bool|null}> */
    public static function values(): iterable
    {
        yield 'true'        => [true, true];
        yield 'false'       => [false, false];
        yield 'int 1'       => [1, true];
        yield 'int 0'       => [0, false];
        yield 'int 2'       => [2, null];
        yield '"1"'         => ['1', true];
        yield '"0"'         => ['0', false];
        yield '"TRUE"'      => ['TRUE', true];
        yield '"false"'     => ['false', false];
        yield '" yes "'     => [' yes ', true];
        yield '"Off"'       => ['Off', false];
        yield '"maybe"'     => ['maybe', null];
        yield '"2"'         => ['2', null];
        yield 'empty'       => ['', null];
        yield 'blank'       => ['   ', null];
        yield 'array'       => [[true], null];
        yield 'float 1.0'   => [1.0, null];
        yield 'null'        => [null, null];
    }

    #[DataProvider('values')]
    public function testParse(mixed $raw, ?bool $expected): void
    {
        self::assertSame($expected, StrictBoolParam::parse($raw));
    }
}
