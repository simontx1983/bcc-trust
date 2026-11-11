<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * F-117: the Verify Collections bulk save must write only rows the saving
 * admin changed. A stale tab used to re-write every row on its page to the
 * state it was rendered with, silently reverting other admins' changes.
 */
#[CoversClass(VerifyCollectionsPage::class)]
final class VerifyCollectionsStaleSaveTest extends TestCase
{
    public function testUntouchedRowsAreNotWrittenEvenIfStale(): void
    {
        // Rendered: 1 verified, 2 unverified, 3 verified.
        // Since then another admin flipped 2 -> verified and 3 -> unverified.
        // This admin touched only row 1 (unchecked it).
        $known   = [1, 2, 3];
        $checked = [3 => true];                       // 2 left unchecked, 3 left checked (as rendered)
        $was     = [1 => true, 2 => false, 3 => true];

        [$verify, $unverify, $untouched] = VerifyCollectionsPage::partitionChanges($known, $checked, $was);

        self::assertSame([], $verify, 'row 3 was not touched here — must not be re-verified over the other admin');
        self::assertSame([1], $unverify, 'only the row this admin changed is written; row 2 is left alone');
        self::assertSame(2, $untouched);
    }

    public function testRowChangedHereIsWrittenBothWays(): void
    {
        [$verify, $unverify, $untouched] = VerifyCollectionsPage::partitionChanges(
            [7, 8],
            [7 => true],
            [7 => false, 8 => true]
        );

        self::assertSame([7], $verify);
        self::assertSame([8], $unverify);
        self::assertSame(0, $untouched);
    }

    public function testPageWithoutRenderedStateKeepsWriteEverything(): void
    {
        // A form rendered before was_verified existed: every row is written.
        [$verify, $unverify, $untouched] = VerifyCollectionsPage::partitionChanges([4, 5], [4 => true], []);

        self::assertSame([4], $verify);
        self::assertSame([5], $unverify);
        self::assertSame(0, $untouched);
    }
}
