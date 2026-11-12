<?php
/**
 * PageReadModelRepository's page_type default now goes through the shared
 * PageTypeMap::effectivePageType() (#547), while keeping this call site's
 * old `get_post_meta(...) ?: 'builder'` semantics byte-for-byte.
 *
 * The old expression sent EVERY falsy meta value to 'builder': '' (no
 * meta), but also '0', false, null and an empty array. effectivePageType()
 * maps only ''. The read model must not start writing a different
 * page_type for a page it has already synced, so the extra falsy cases
 * are pinned here, next to a parity check that every other string goes
 * through the shared helper unchanged.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\PageReadModelRepository;
use BCC\Trust\Core\Support\PageTypeMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageReadModelEffectivePageTypeTest extends TestCase
{
    private static function resolve(mixed $raw): string
    {
        $m = new \ReflectionMethod(PageReadModelRepository::class, 'effectivePageTypeFromMeta');
        $result = $m->invoke(null, $raw);
        self::assertIsString($result);
        return $result;
    }

    /**
     * The old `$raw ?: 'builder'`, as a reference oracle for the falsy set.
     */
    private static function legacy(mixed $raw): mixed
    {
        return $raw ?: 'builder';
    }

    /** @return iterable<string, array{mixed}> */
    public static function falsyMeta(): iterable
    {
        yield 'no meta row (WP returns "")' => [''];
        yield 'string zero'                  => ['0'];
        yield 'false'                        => [false];
        yield 'null'                         => [null];
        yield 'empty array'                  => [[]];
    }

    #[DataProvider('falsyMeta')]
    public function testEveryFalsyMetaValueStillReadsAsBuilder(mixed $raw): void
    {
        self::assertSame('builder', self::legacy($raw), 'oracle sanity: the old expression mapped this to builder');
        self::assertSame('builder', self::resolve($raw));
    }

    /** @return iterable<string, array{string}> */
    public static function storedTypes(): iterable
    {
        yield 'builder'      => ['builder'];
        yield 'validator'    => ['validator'];
        yield 'nft'          => ['nft'];
        yield 'unrecognised' => ['something-else'];
        yield 'zero-padded'  => ['00'];
        yield 'space'        => [' '];
    }

    #[DataProvider('storedTypes')]
    public function testANonEmptyStringIsTheSharedHelperAnswerAndTheOldAnswer(string $raw): void
    {
        self::assertSame(PageTypeMap::effectivePageType($raw), self::resolve($raw), 'parity with the shared helper');
        self::assertSame(self::legacy($raw), self::resolve($raw), 'parity with the old expression');
    }

    public function testTheSharedHelperAloneDoesNotCoverZeroWhichIsWhyTheWrapperExists(): void
    {
        // Anti-vacuity: if effectivePageType() ever starts mapping '0' too,
        // this fails and the wrapper's extra '0' branch can be dropped.
        self::assertSame('0', PageTypeMap::effectivePageType('0'));
    }
}
